<?php

namespace App\Domain\Receipts;

use App\Domain\Integration\Enums\DocumentPhase;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Integration\SapDocumentLifecycle;
use App\Domain\Orders\OrderClosureWatch;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Receipts\Exceptions\ReceiptRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Integrations\Sap\Dto\DocumentLine;
use App\Integrations\Sap\Dto\ProductionReceiptRequest;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Dto\SapWriteResult;
use App\Models\InventoryTransfer;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Production receipts into warehouse 04. The SAP rules live in
 * SapDocumentLifecycle; this says which tables and calls apply.
 */
class ProductionReceiptLifecycle extends SapDocumentLifecycle
{
    /**
     * A person closes a pending receipt as failed, only with proof that SAP has no such document.
     *
     * @throws ReceiptRuleViolation
     */
    public function failAfterProof(int $receiptId, TraceContext $ctx, string $reason): void
    {
        if (ProductionReceipt::query()->findOrFail($receiptId)->status !== SapDocumentStatus::PendingReconciliation) {
            throw ReceiptRuleViolation::notPending();
        }

        if (! $this->failIfProvedAbsent($receiptId, $ctx, $reason)) {
            throw ReceiptRuleViolation::documentMayExist();
        }
    }

    protected function documents(): Builder
    {
        return ProductionReceipt::query();
    }

    protected function linesOf(ProductionReceipt|InventoryTransfer $document): Builder
    {
        return ProductionReceiptPallet::query()->where('production_receipt_id', $document->id);
    }

    protected function create(ProductionReceipt|InventoryTransfer $document): SapWriteResult
    {
        $payload = $document->payload;

        $date = fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value);

        return $this->sap->createProductionReceipt(new ProductionReceiptRequest(
            $document->request_uuid,
            $payload['production_order_ref'],
            array_values(array_map(
                fn (array $l) => new DocumentLine($l['item_code'], $l['lot_code'], new Quantity($l['quantity']), $l['warehouse'], $date($l['expiration_date'] ?? null), $date($l['manufacturing_date'] ?? null)),
                $payload['lines'],
            )),
            $date($payload['posting_date'] ?? null),
            isset($payload['production_order_doc_entry']) ? (int) $payload['production_order_doc_entry'] : null,
        ));
    }

    protected function find(string $requestUuid): ?SapDocument
    {
        return $this->sap->findProductionReceiptByCorrelation($requestUuid);
    }

    protected function findByDocEntry(int $docEntry): ?SapDocument
    {
        return $this->sap->findProductionReceipt($docEntry);
    }

    protected function headerDifferences(ProductionReceipt|InventoryTransfer $document, SapDocument $found): array
    {
        $ref = $document->payload['production_order_ref'] ?? null;
        $date = $document->payload['posting_date'] ?? null;
        $differences = [];

        if ($found->productionOrderRef !== null && $found->productionOrderRef !== $ref) {
            $differences[] = "Orden distinta en SAP: {$found->productionOrderRef}";
        }

        $docEntry = $document->payload['production_order_doc_entry'] ?? null;
        if ($docEntry !== null && $found->productionOrderDocEntry !== null && $found->productionOrderDocEntry !== (int) $docEntry) {
            $differences[] = "Orden base distinta en SAP: DocEntry {$found->productionOrderDocEntry}, pedida {$docEntry}";
        }

        // The batch's manufacturing date is this same date, so SAP must have posted it on the requested day.
        if ($date !== null && $found->postingDate?->toDateString() !== $date) {
            $differences[] = "Fecha de contabilización distinta: pedida {$date}, en SAP ".($found->postingDate?->toDateString() ?? 'vacía');
        }

        return $differences;
    }

    protected function afterConfirmed(ProductionReceipt|InventoryTransfer $document): void
    {
        $orderId = $document instanceof ProductionReceipt ? $document->production_order_id : null;

        // After commit, and never able to undo the confirmation: the alert is a side effect.
        DB::afterCommit(function () use ($orderId): void {
            try {
                if ($orderId !== null) {
                    app(OrderClosureWatch::class)->check($orderId);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    protected function palletColumn(): string
    {
        return 'receipt_status';
    }

    protected function palletStatus(DocumentPhase $phase): ReceiptStatus|TransferStatus
    {
        return match ($phase) {
            DocumentPhase::Requested => ReceiptStatus::Requested,
            DocumentPhase::Pending => ReceiptStatus::PendingReconciliation,
            DocumentPhase::Confirmed => ReceiptStatus::Confirmed,
            DocumentPhase::Failed => ReceiptStatus::Failed,
        };
    }

    protected function traceEvent(DocumentPhase $phase): TraceEventType
    {
        return match ($phase) {
            DocumentPhase::Requested => TraceEventType::ReceiptRequested,
            DocumentPhase::Pending => TraceEventType::ReceiptPendingReconciliation,
            DocumentPhase::Confirmed => TraceEventType::ReceiptConfirmed,
            DocumentPhase::Failed => TraceEventType::ReceiptFailed,
        };
    }
}
