<?php

namespace App\Domain\Transfers;

use App\Domain\Integration\Enums\DocumentPhase;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Integration\SapDocumentLifecycle;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Integrations\Sap\Dto\DocumentLine;
use App\Integrations\Sap\Dto\InventoryTransferRequest;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Dto\SapWriteResult;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\ProductionReceipt;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inventory transfers 04 → 05. Same SAP rules as receipts
 * (SapDocumentLifecycle): CONFIRMADO_05 only after the document was
 * verified in SAP.
 */
class InventoryTransferLifecycle extends SapDocumentLifecycle
{
    /**
     * @throws TransferRuleViolation
     */
    public function failAfterProof(int $transferId, TraceContext $ctx, string $reason): void
    {
        if (InventoryTransfer::query()->findOrFail($transferId)->status !== SapDocumentStatus::PendingReconciliation) {
            throw TransferRuleViolation::notPending();
        }

        if (! $this->failIfProvedAbsent($transferId, $ctx, $reason)) {
            throw TransferRuleViolation::documentMayExist();
        }
    }

    protected function documents(): Builder
    {
        return InventoryTransfer::query();
    }

    protected function linesOf(ProductionReceipt|InventoryTransfer $document): Builder
    {
        return InventoryTransferPallet::query()->where('inventory_transfer_id', $document->id);
    }

    protected function create(ProductionReceipt|InventoryTransfer $document): SapWriteResult
    {
        $payload = $document->payload;

        return $this->sap->createInventoryTransfer(new InventoryTransferRequest($document->request_uuid, $payload['from_warehouse'], $payload['to_warehouse'], array_values(array_map(
            fn (array $l) => new DocumentLine($l['item_code'], $l['lot_code'], new Quantity($l['quantity']), $l['warehouse']),
            $payload['lines'],
        ))));
    }

    protected function find(string $requestUuid): ?SapDocument
    {
        return $this->sap->findInventoryTransferByCorrelation($requestUuid);
    }

    protected function findByDocEntry(int $docEntry): ?SapDocument
    {
        return $this->sap->findInventoryTransfer($docEntry);
    }

    protected function headerDifferences(ProductionReceipt|InventoryTransfer $document, SapDocument $found): array
    {
        $from = $document->payload['from_warehouse'] ?? null;

        return $found->fromWarehouse !== null && $found->fromWarehouse !== $from ? ["Almacén de origen distinto en SAP: {$found->fromWarehouse}"] : [];
    }

    protected function palletColumn(): string
    {
        return 'transfer_status';
    }

    protected function palletStatus(DocumentPhase $phase): ReceiptStatus|TransferStatus
    {
        return match ($phase) {
            DocumentPhase::Requested => TransferStatus::Requested,
            DocumentPhase::Pending => TransferStatus::PendingReconciliation,
            DocumentPhase::Confirmed => TransferStatus::Confirmed,
            DocumentPhase::Failed => TransferStatus::Failed,
        };
    }

    protected function traceEvent(DocumentPhase $phase): TraceEventType
    {
        return match ($phase) {
            DocumentPhase::Requested => TraceEventType::TransferRequested,
            DocumentPhase::Pending => TraceEventType::TransferPendingReconciliation,
            DocumentPhase::Confirmed => TraceEventType::TransferConfirmed,
            DocumentPhase::Failed => TraceEventType::TransferFailed,
        };
    }
}
