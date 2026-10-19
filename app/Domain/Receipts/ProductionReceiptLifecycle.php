<?php

namespace App\Domain\Receipts;

use App\Domain\Integration\AttemptRecorder;
use App\Domain\Integration\DocumentMatch;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Receipts\Exceptions\ReceiptRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\DocumentLine;
use App\Integrations\Sap\Dto\ProductionReceiptRequest;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Dto\SapWriteResult;
use App\Integrations\Sap\Dto\WriteOutcome;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Models\Pallet;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves a production receipt request through SAP.
 *
 *  - send(): SOLICITADO → ENVIANDO is committed before the call, so a
 *    worker that dies mid-call leaves ENVIANDO behind, never a silent retry.
 *  - Created answer: the document is read back by correlation and compared
 *    line by line; only a match confirms. Rejected: failed, pallets
 *    released. Anything else: PENDIENTE_CONCILIACION.
 *  - reconcile(): looks the document up by correlation. Found and matching:
 *    confirm. Found and different: stays pending for a person. Proved absent
 *    and older than the grace period: resend once more, up to the limit.
 *    Lookup failed: nothing changes.
 */
class ProductionReceiptLifecycle
{
    public function __construct(
        private readonly SapGateway $sap,
        private readonly AttemptRecorder $attempts,
        private readonly TraceLogger $trace,
    ) {}

    public function send(int $receiptId): void
    {
        $receipt = DB::transaction(function () use ($receiptId): ?ProductionReceipt {
            $receipt = ProductionReceipt::query()->lockForUpdate()->find($receiptId);

            if ($receipt === null || $receipt->status !== SapDocumentStatus::Requested) {
                return null;
            }

            $this->markSending($receipt);

            return $receipt;
        });

        if ($receipt !== null) {
            $this->callSap($receipt);
        }
    }

    public function reconcile(int $receiptId): void
    {
        $receipt = ProductionReceipt::query()->find($receiptId);

        if ($receipt === null || $receipt->status !== SapDocumentStatus::PendingReconciliation) {
            return;
        }

        $startedAt = CarbonImmutable::now();

        try {
            $document = $this->sap->findProductionReceiptByCorrelation($receipt->request_uuid);
        } catch (SapLookupFailed $e) {
            $this->attempts->lookup($receipt, $startedAt, true, [], $e->getMessage());
            $this->note($receipt, ['last_checked_at' => now()->toIso8601String(), 'last_result' => 'consulta_fallida']);

            return;
        }

        $this->attempts->lookup($receipt, $startedAt, false, ['found' => $document !== null, 'doc_entry' => $document?->docEntry]);

        if ($document !== null) {
            $this->verifyAndConfirm($receipt, $document);

            return;
        }

        $sentAt = $receipt->sent_at ?? $receipt->requested_at;
        $graceOver = $sentAt->lessThanOrEqualTo(now()->subMinutes(config()->integer('sap.reconciliation.resend_after_minutes')));
        $canRetry = $receipt->attempts < config()->integer('sap.reconciliation.max_send_attempts');

        if (! $graceOver || ! $canRetry) {
            $this->note($receipt, ['last_checked_at' => now()->toIso8601String(), 'last_result' => $canRetry ? 'no_existe_en_espera' : 'no_existe_limite_de_envios']);

            return;
        }

        // Proved absent after the grace period: resending is safe.
        $resend = DB::transaction(function () use ($receipt): ?ProductionReceipt {
            $locked = ProductionReceipt::query()->lockForUpdate()->find($receipt->id);

            if ($locked === null || $locked->status !== SapDocumentStatus::PendingReconciliation) {
                return null;
            }

            $this->markSending($locked);

            return $locked;
        });

        if ($resend !== null) {
            $this->callSap($resend);
        }
    }

    /**
     * A worker died while sending: the outcome is unknown, so it goes to
     * reconciliation, never back to SOLICITADO.
     */
    public function abandonStaleSend(int $receiptId): void
    {
        DB::transaction(function () use ($receiptId): void {
            $receipt = ProductionReceipt::query()->lockForUpdate()->find($receiptId);

            if ($receipt !== null && $receipt->status === SapDocumentStatus::Sending) {
                $this->toPending($receipt, 'El envío quedó sin respuesta registrada.');
            }
        });
    }

    /**
     * A person closes a pending request as failed. Only after SAP proved
     * there is no document and the grace period is over.
     */
    public function failAfterProof(int $receiptId, TraceContext $ctx, string $reason): void
    {
        $receipt = ProductionReceipt::query()->findOrFail($receiptId);

        if ($receipt->status !== SapDocumentStatus::PendingReconciliation) {
            throw ReceiptRuleViolation::notPending();
        }

        $sentAt = $receipt->sent_at ?? $receipt->requested_at;

        try {
            $absent = $this->sap->findProductionReceiptByCorrelation($receipt->request_uuid) === null;
        } catch (SapLookupFailed) {
            $absent = false;
        }

        if (! $absent || $sentAt->greaterThan(now()->subMinutes(config()->integer('sap.reconciliation.resend_after_minutes')))) {
            throw ReceiptRuleViolation::documentMayExist();
        }

        DB::transaction(function () use ($receiptId, $ctx, $reason): void {
            $receipt = ProductionReceipt::query()->lockForUpdate()->findOrFail($receiptId);

            if ($receipt->status === SapDocumentStatus::PendingReconciliation) {
                $this->toFailed($receipt, 'MANUAL', $reason, $ctx);
            }
        });
    }

    private function callSap(ProductionReceipt $receipt): void
    {
        $startedAt = CarbonImmutable::now();

        try {
            $result = $this->sap->createProductionReceipt($this->request($receipt));
        } catch (Throwable $e) {
            // Contract says implementations do not throw on writes; if one does, the outcome is unknown.
            $result = SapWriteResult::uncertain('EXCEPCION', class_basename($e).': '.$e->getMessage(), (int) $startedAt->diffInMilliseconds(now()));
        }

        $this->attempts->write($receipt, $startedAt, $result);

        match ($result->outcome) {
            WriteOutcome::Created => $this->afterCreated($receipt),
            WriteOutcome::Rejected => DB::transaction(fn () => $this->toFailed($this->lock($receipt), (string) $result->errorCode, (string) $result->errorMessage, $this->systemContext($receipt))),
            WriteOutcome::Uncertain => DB::transaction(fn () => $this->toPending($this->lock($receipt), (string) $result->errorMessage)),
        };
    }

    /**
     * SAP said it created the document. Read it back before believing it.
     */
    private function afterCreated(ProductionReceipt $receipt): void
    {
        $startedAt = CarbonImmutable::now();

        try {
            $document = $this->sap->findProductionReceiptByCorrelation($receipt->request_uuid);
        } catch (SapLookupFailed $e) {
            $this->attempts->lookup($receipt, $startedAt, true, [], $e->getMessage());
            DB::transaction(fn () => $this->toPending($this->lock($receipt), 'SAP respondió creado pero no se pudo verificar el documento.'));

            return;
        }

        $this->attempts->lookup($receipt, $startedAt, false, ['found' => $document !== null, 'doc_entry' => $document?->docEntry]);

        if ($document === null) {
            DB::transaction(fn () => $this->toPending($this->lock($receipt), 'SAP respondió creado pero el documento no aparece por su correlación.'));

            return;
        }

        $this->verifyAndConfirm($receipt, $document);
    }

    private function verifyAndConfirm(ProductionReceipt $receipt, SapDocument $document): void
    {
        $payload = $receipt->payload;
        $differences = DocumentMatch::differences($document, $payload['lines']);

        if ($document->productionOrderRef !== null && $document->productionOrderRef !== $payload['production_order_ref']) {
            $differences[] = "Orden distinta en SAP: {$document->productionOrderRef}";
        }

        DB::transaction(function () use ($receipt, $document, $differences): void {
            $locked = $this->lock($receipt);

            if (! in_array($locked->status, [SapDocumentStatus::Sending, SapDocumentStatus::PendingReconciliation], true)) {
                return;
            }

            if ($differences !== []) {
                // A document exists with our correlation but is not what we asked for: never confirm, never resend.
                if ($locked->status === SapDocumentStatus::Sending) {
                    $this->toPending($locked, 'El documento en SAP no coincide con la solicitud.');
                }
                $this->note($locked, ['mismatch' => true, 'doc_entry' => $document->docEntry, 'doc_num' => $document->docNum, 'differences' => $differences, 'last_checked_at' => now()->toIso8601String()]);

                return;
            }

            $this->toConfirmed($locked, $document);
        });
    }

    private function markSending(ProductionReceipt $receipt): void
    {
        $fromPending = $receipt->status === SapDocumentStatus::PendingReconciliation;

        $receipt->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now(), 'attempts' => $receipt->attempts + 1])->save();

        if ($fromPending) {
            $this->setPallets($receipt, ReceiptStatus::Requested);
        }
    }

    private function toPending(ProductionReceipt $receipt, string $why): void
    {
        $receipt->forceFill(['status' => SapDocumentStatus::PendingReconciliation, 'last_error_code' => 'INCIERTO', 'last_error_message' => mb_substr($why, 0, 500)])->save();
        $this->setPallets($receipt, ReceiptStatus::PendingReconciliation);

        $this->trace->record($this->systemContext($receipt), TraceEventType::ReceiptPendingReconciliation, $receipt,
            to: ['status' => SapDocumentStatus::PendingReconciliation->value], result: TraceResult::Error, reason: $why);
    }

    private function toFailed(ProductionReceipt $receipt, string $code, string $message, TraceContext $ctx): void
    {
        $receipt->forceFill(['status' => SapDocumentStatus::Failed, 'failed_at' => now(), 'last_error_code' => mb_substr($code, 0, 60), 'last_error_message' => mb_substr($message, 0, 500)])->save();

        ProductionReceiptPallet::query()->where('production_receipt_id', $receipt->id)->where('hold_status', PalletHold::Active)->update(['hold_status' => PalletHold::Released, 'updated_at' => now()]);
        $this->setPallets($receipt, ReceiptStatus::Failed);

        $this->trace->record($ctx, TraceEventType::ReceiptFailed, $receipt,
            to: ['status' => SapDocumentStatus::Failed->value], result: TraceResult::Error, reason: "{$code}: {$message}");
    }

    private function toConfirmed(ProductionReceipt $receipt, SapDocument $document): void
    {
        $receipt->forceFill([
            'status' => SapDocumentStatus::Confirmed,
            'sap_doc_entry' => $document->docEntry,
            'sap_doc_num' => $document->docNum,
            'confirmed_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $numbers = DocumentMatch::lineNumbers($document);
        $warehouse = $receipt->payload['lines'][0]['warehouse'] ?? '';

        foreach (ProductionReceiptPallet::query()->where('production_receipt_id', $receipt->id)->get() as $line) {
            $line->forceFill(['hold_status' => PalletHold::Confirmed, 'sap_line_num' => $numbers["{$line->item_code}|{$line->lot_code}|{$warehouse}"] ?? null])->save();
        }

        $this->setPallets($receipt, ReceiptStatus::Confirmed);

        $this->trace->record($this->systemContext($receipt), TraceEventType::ReceiptConfirmed, $receipt,
            to: ['status' => SapDocumentStatus::Confirmed->value],
            sapRefs: ['doc_entry' => $document->docEntry, 'doc_num' => $document->docNum],
        );
    }

    private function setPallets(ProductionReceipt $receipt, ReceiptStatus $status): void
    {
        $palletIds = ProductionReceiptPallet::query()->where('production_receipt_id', $receipt->id)->pluck('pallet_id');

        foreach (Pallet::query()->whereKey($palletIds)->orderBy('id')->lockForUpdate()->get() as $pallet) {
            if ($pallet->receipt_status === $status) {
                continue;
            }

            $from = $pallet->receipt_status;
            $pallet->forceFill(['receipt_status' => $status])->save();

            $this->trace->record($this->systemContext($receipt), match ($status) {
                ReceiptStatus::Confirmed => TraceEventType::ReceiptConfirmed,
                ReceiptStatus::Failed => TraceEventType::ReceiptFailed,
                ReceiptStatus::PendingReconciliation => TraceEventType::ReceiptPendingReconciliation,
                default => TraceEventType::ReceiptRequested,
            }, $pallet, from: ['receipt_status' => $from->value], to: ['receipt_status' => $status->value],
                sapRefs: $receipt->sap_doc_num === null ? null : ['doc_entry' => $receipt->sap_doc_entry, 'doc_num' => $receipt->sap_doc_num],
                context: ['request_uuid' => $receipt->request_uuid]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function note(ProductionReceipt $receipt, array $values): void
    {
        ProductionReceipt::query()->whereKey($receipt->id)->where('status', SapDocumentStatus::PendingReconciliation)
            ->update(['reconciliation' => json_encode([...($receipt->reconciliation ?? []), ...$values]), 'updated_at' => now()]);
    }

    private function lock(ProductionReceipt $receipt): ProductionReceipt
    {
        return ProductionReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
    }

    private function request(ProductionReceipt $receipt): ProductionReceiptRequest
    {
        $payload = $receipt->payload;

        return new ProductionReceiptRequest($receipt->request_uuid, $payload['production_order_ref'], array_values(array_map(
            fn (array $l) => new DocumentLine($l['item_code'], $l['lot_code'], new Quantity($l['quantity']), $l['warehouse']),
            $payload['lines'],
        )));
    }

    private function systemContext(ProductionReceipt $receipt): TraceContext
    {
        return TraceContext::system(Origin::Job, $receipt->request_uuid);
    }
}
