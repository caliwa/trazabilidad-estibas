<?php

namespace App\Domain\Integration;

use App\Domain\Integration\Enums\DocumentPhase;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Dto\SapWriteResult;
use App\Integrations\Sap\Dto\WriteOutcome;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\Pallet;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves a SAP document request (production receipt or inventory transfer)
 * through SAP. The rules are the same for both kinds:
 *
 *  - send(): SOLICITADO → ENVIANDO is committed before the call, so a
 *    worker that dies mid-call leaves ENVIANDO behind, never a silent retry.
 *  - Created answer: the document is read back by correlation and compared
 *    line by line; only a match confirms. Rejected: failed, pallets
 *    released. Anything else: PENDIENTE_CONCILIACION.
 *  - reconcile(): looks the document up by correlation. Found and matching:
 *    confirm. Found and different: stays pending for a person. Proved absent
 *    and older than the grace period: resend, up to the limit. Lookup
 *    failed: nothing changes.
 *
 * Subclasses only say which tables, SAP calls and pallet dimension apply.
 */
abstract class SapDocumentLifecycle
{
    public function __construct(
        protected readonly SapGateway $sap,
        protected readonly AttemptRecorder $attempts,
        protected readonly TraceLogger $trace,
    ) {}

    /**
     * @return Builder<ProductionReceipt>|Builder<InventoryTransfer>
     */
    abstract protected function documents(): Builder;

    /**
     * @return Builder<ProductionReceiptPallet>|Builder<InventoryTransferPallet>
     */
    abstract protected function linesOf(ProductionReceipt|InventoryTransfer $document): Builder;

    abstract protected function create(ProductionReceipt|InventoryTransfer $document): SapWriteResult;

    /**
     * @throws SapLookupFailed
     */
    abstract protected function find(string $requestUuid): ?SapDocument;

    /**
     * Header checks beyond the lines (order, warehouses).
     *
     * @return list<string>
     */
    abstract protected function headerDifferences(ProductionReceipt|InventoryTransfer $document, SapDocument $found): array;

    /** Pallet column this document kind moves: receipt_status or transfer_status. */
    abstract protected function palletColumn(): string;

    abstract protected function palletStatus(DocumentPhase $phase): ReceiptStatus|TransferStatus;

    abstract protected function traceEvent(DocumentPhase $phase): TraceEventType;

    public function send(int $documentId): void
    {
        $document = DB::transaction(function () use ($documentId): ProductionReceipt|InventoryTransfer|null {
            $document = $this->documents()->lockForUpdate()->find($documentId);

            if ($document === null || $document->status !== SapDocumentStatus::Requested) {
                return null;
            }

            $this->markSending($document);

            return $document;
        });

        if ($document !== null) {
            $this->callSap($document);
        }
    }

    public function reconcile(int $documentId): void
    {
        $document = $this->documents()->find($documentId);

        if ($document === null || $document->status !== SapDocumentStatus::PendingReconciliation) {
            return;
        }

        $startedAt = CarbonImmutable::now();

        try {
            $found = $this->find($document->request_uuid);
        } catch (SapLookupFailed $e) {
            $this->attempts->lookup($document, $startedAt, true, [], $e->getMessage());
            $this->note($document, ['last_checked_at' => now()->toIso8601String(), 'last_result' => 'consulta_fallida']);

            return;
        }

        $this->attempts->lookup($document, $startedAt, false, ['found' => $found !== null, 'doc_entry' => $found?->docEntry]);

        if ($found !== null) {
            $this->verifyAndConfirm($document, $found);

            return;
        }

        $sentAt = $document->sent_at ?? $document->requested_at;
        $graceOver = $sentAt->lessThanOrEqualTo(now()->subMinutes(config()->integer('sap.reconciliation.resend_after_minutes')));
        $canRetry = $document->attempts < config()->integer('sap.reconciliation.max_send_attempts');

        if (! $graceOver || ! $canRetry) {
            $this->note($document, ['last_checked_at' => now()->toIso8601String(), 'last_result' => $canRetry ? 'no_existe_en_espera' : 'no_existe_limite_de_envios']);

            return;
        }

        // Proved absent after the grace period: resending is safe.
        $resend = DB::transaction(function () use ($document): ProductionReceipt|InventoryTransfer|null {
            $locked = $this->documents()->lockForUpdate()->find($document->id);

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
    public function abandonStaleSend(int $documentId): void
    {
        DB::transaction(function () use ($documentId): void {
            $document = $this->documents()->lockForUpdate()->find($documentId);

            if ($document !== null && $document->status === SapDocumentStatus::Sending) {
                $this->toPending($document, 'El envío quedó sin respuesta registrada.');
            }
        });
    }

    /**
     * A person closes a pending request as failed. Only after SAP proved
     * there is no document and the grace period is over.
     *
     * @return bool false when the request is not pending or SAP has not proved the document does not exist
     */
    protected function failIfProvedAbsent(int $documentId, TraceContext $ctx, string $reason): bool
    {
        $document = $this->documents()->findOrFail($documentId);

        if ($document->status !== SapDocumentStatus::PendingReconciliation) {
            return false;
        }

        try {
            $absent = $this->find($document->request_uuid) === null;
        } catch (SapLookupFailed) {
            $absent = false;
        }

        $sentAt = $document->sent_at ?? $document->requested_at;

        if (! $absent || $sentAt->greaterThan(now()->subMinutes(config()->integer('sap.reconciliation.resend_after_minutes')))) {
            return false;
        }

        DB::transaction(function () use ($documentId, $ctx, $reason): void {
            $document = $this->documents()->lockForUpdate()->findOrFail($documentId);

            if ($document->status === SapDocumentStatus::PendingReconciliation) {
                $this->toFailed($document, 'MANUAL', $reason, $ctx);
            }
        });

        return true;
    }

    private function callSap(ProductionReceipt|InventoryTransfer $document): void
    {
        $startedAt = CarbonImmutable::now();

        try {
            $result = $this->create($document);
        } catch (Throwable $e) {
            // The contract says writes do not throw; if one does, the outcome is unknown.
            $result = SapWriteResult::uncertain('EXCEPCION', class_basename($e).': '.$e->getMessage(), (int) $startedAt->diffInMilliseconds(now()));
        }

        $this->attempts->write($document, $startedAt, $result);

        match ($result->outcome) {
            WriteOutcome::Created => $this->afterCreated($document),
            WriteOutcome::Rejected => DB::transaction(fn () => $this->toFailed($this->lock($document), (string) $result->errorCode, (string) $result->errorMessage, $this->systemContext($document))),
            WriteOutcome::Uncertain => DB::transaction(fn () => $this->toPending($this->lock($document), (string) $result->errorMessage)),
        };
    }

    /**
     * SAP said it created the document. Read it back before believing it.
     */
    private function afterCreated(ProductionReceipt|InventoryTransfer $document): void
    {
        $startedAt = CarbonImmutable::now();

        try {
            $found = $this->find($document->request_uuid);
        } catch (SapLookupFailed $e) {
            $this->attempts->lookup($document, $startedAt, true, [], $e->getMessage());
            DB::transaction(fn () => $this->toPending($this->lock($document), 'SAP respondió creado pero no se pudo verificar el documento.'));

            return;
        }

        $this->attempts->lookup($document, $startedAt, false, ['found' => $found !== null, 'doc_entry' => $found?->docEntry]);

        if ($found === null) {
            DB::transaction(fn () => $this->toPending($this->lock($document), 'SAP respondió creado pero el documento no aparece por su correlación.'));

            return;
        }

        $this->verifyAndConfirm($document, $found);
    }

    private function verifyAndConfirm(ProductionReceipt|InventoryTransfer $document, SapDocument $found): void
    {
        $differences = [...DocumentMatch::differences($found, $document->payload['lines']), ...$this->headerDifferences($document, $found)];

        DB::transaction(function () use ($document, $found, $differences): void {
            $locked = $this->lock($document);

            if (! in_array($locked->status, [SapDocumentStatus::Sending, SapDocumentStatus::PendingReconciliation], true)) {
                return;
            }

            if ($differences !== []) {
                // A document exists with our correlation but is not what we asked for: never confirm, never resend.
                if ($locked->status === SapDocumentStatus::Sending) {
                    $this->toPending($locked, 'El documento en SAP no coincide con la solicitud.');
                }
                $this->note($locked, ['mismatch' => true, 'doc_entry' => $found->docEntry, 'doc_num' => $found->docNum, 'differences' => $differences, 'last_checked_at' => now()->toIso8601String()]);

                return;
            }

            $this->toConfirmed($locked, $found);
        });
    }

    private function markSending(ProductionReceipt|InventoryTransfer $document): void
    {
        $fromPending = $document->status === SapDocumentStatus::PendingReconciliation;

        $document->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now(), 'attempts' => $document->attempts + 1])->save();

        if ($fromPending) {
            $this->setPallets($document, DocumentPhase::Requested);
        }
    }

    private function toPending(ProductionReceipt|InventoryTransfer $document, string $why): void
    {
        $document->forceFill(['status' => SapDocumentStatus::PendingReconciliation, 'last_error_code' => 'INCIERTO', 'last_error_message' => mb_substr($why, 0, 500)])->save();
        $this->setPallets($document, DocumentPhase::Pending);

        $this->trace->record($this->systemContext($document), $this->traceEvent(DocumentPhase::Pending), $document,
            to: ['status' => SapDocumentStatus::PendingReconciliation->value], result: TraceResult::Error, reason: $why);
    }

    private function toFailed(ProductionReceipt|InventoryTransfer $document, string $code, string $message, TraceContext $ctx): void
    {
        $document->forceFill(['status' => SapDocumentStatus::Failed, 'failed_at' => now(), 'last_error_code' => mb_substr($code, 0, 60), 'last_error_message' => mb_substr($message, 0, 500)])->save();

        $this->linesOf($document)->where('hold_status', PalletHold::Active)->update(['hold_status' => PalletHold::Released, 'updated_at' => now()]);
        $this->setPallets($document, DocumentPhase::Failed);

        $this->trace->record($ctx, $this->traceEvent(DocumentPhase::Failed), $document,
            to: ['status' => SapDocumentStatus::Failed->value], result: TraceResult::Error, reason: "{$code}: {$message}");
    }

    private function toConfirmed(ProductionReceipt|InventoryTransfer $document, SapDocument $found): void
    {
        $document->forceFill([
            'status' => SapDocumentStatus::Confirmed,
            'sap_doc_entry' => $found->docEntry,
            'sap_doc_num' => $found->docNum,
            'confirmed_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $numbers = DocumentMatch::lineNumbers($found);
        $warehouse = $document->payload['lines'][0]['warehouse'] ?? '';

        foreach ($this->linesOf($document)->get() as $line) {
            $line->forceFill(['hold_status' => PalletHold::Confirmed, 'sap_line_num' => $numbers["{$line->item_code}|{$line->lot_code}|{$warehouse}"] ?? null])->save();
        }

        $this->setPallets($document, DocumentPhase::Confirmed);

        $this->trace->record($this->systemContext($document), $this->traceEvent(DocumentPhase::Confirmed), $document,
            to: ['status' => SapDocumentStatus::Confirmed->value],
            sapRefs: ['doc_entry' => $found->docEntry, 'doc_num' => $found->docNum],
        );

        $this->afterConfirmed($document);
    }

    /** Runs inside the confirming transaction. Nothing by default. */
    protected function afterConfirmed(ProductionReceipt|InventoryTransfer $document): void {}

    private function setPallets(ProductionReceipt|InventoryTransfer $document, DocumentPhase $phase): void
    {
        $column = $this->palletColumn();
        $status = $this->palletStatus($phase);

        foreach (Pallet::query()->whereKey($this->linesOf($document)->pluck('pallet_id'))->orderBy('id')->lockForUpdate()->get() as $pallet) {
            $from = $pallet->getAttribute($column);

            if ($from === $status) {
                continue;
            }

            $pallet->forceFill([$column => $status])->save();

            $this->trace->record($this->systemContext($document), $this->traceEvent($phase), $pallet,
                from: [$column => $from instanceof \BackedEnum ? $from->value : $from], to: [$column => $status->value],
                sapRefs: $document->sap_doc_num === null ? null : ['doc_entry' => $document->sap_doc_entry, 'doc_num' => $document->sap_doc_num],
                context: ['request_uuid' => $document->request_uuid]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function note(ProductionReceipt|InventoryTransfer $document, array $values): void
    {
        $this->documents()->whereKey($document->id)->where('status', SapDocumentStatus::PendingReconciliation)
            ->update(['reconciliation' => json_encode([...($document->reconciliation ?? []), ...$values]), 'updated_at' => now()]);
    }

    private function lock(ProductionReceipt|InventoryTransfer $document): ProductionReceipt|InventoryTransfer
    {
        return $this->documents()->lockForUpdate()->findOrFail($document->id);
    }

    private function systemContext(ProductionReceipt|InventoryTransfer $document): TraceContext
    {
        return TraceContext::system(Origin::Job, $document->request_uuid);
    }
}
