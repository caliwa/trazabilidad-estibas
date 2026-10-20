<?php

namespace App\Domain\Receipts;

use App\Domain\Integration\Enums\DocumentPhase;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Integration\SapDocumentLifecycle;
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
use Illuminate\Database\Eloquent\Builder;

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

        return $this->sap->createProductionReceipt(new ProductionReceiptRequest($document->request_uuid, $payload['production_order_ref'], array_values(array_map(
            fn (array $l) => new DocumentLine($l['item_code'], $l['lot_code'], new Quantity($l['quantity']), $l['warehouse']),
            $payload['lines'],
        ))));
    }

    protected function find(string $requestUuid): ?SapDocument
    {
        return $this->sap->findProductionReceiptByCorrelation($requestUuid);
    }

    protected function headerDifferences(ProductionReceipt|InventoryTransfer $document, SapDocument $found): array
    {
        $ref = $document->payload['production_order_ref'] ?? null;

        return $found->productionOrderRef !== null && $found->productionOrderRef !== $ref ? ["Orden distinta en SAP: {$found->productionOrderRef}"] : [];
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
