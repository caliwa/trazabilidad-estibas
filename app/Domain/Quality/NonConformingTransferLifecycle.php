<?php

namespace App\Domain\Quality;

use App\Domain\Integration\Enums\DocumentPhase;
use App\Domain\Quality\Enums\QualityHoldStatus;
use App\Domain\Quality\Enums\QualityStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferPurpose;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\InventoryTransferLifecycle;
use App\Models\InventoryTransfer;
use App\Models\ProductionReceipt;
use App\Models\QualityHold;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock transfers 04 → 11C (non-conforming warehouse): holding a pallet.
 * Same SAP document and the same rules as the transfer to the CEDI: it is
 * confirmed only after the document SAP has was verified. What it moves is
 * the pallet's quality status, never transfer_status, so the CEDI keeps
 * counting only what reached 05.
 */
class NonConformingTransferLifecycle extends InventoryTransferLifecycle
{
    protected function documents(): Builder
    {
        return InventoryTransfer::query()->where('purpose', TransferPurpose::NonConforming);
    }

    protected function palletColumn(): string
    {
        return 'quality_status';
    }

    protected function palletStatus(DocumentPhase $phase): ReceiptStatus|TransferStatus|QualityStatus
    {
        return match ($phase) {
            DocumentPhase::Requested => QualityStatus::NonConformingRequested,
            DocumentPhase::Pending => QualityStatus::NonConformingPending,
            DocumentPhase::Confirmed => QualityStatus::NonConforming,
            // SAP refused it: nothing moved.
            DocumentPhase::Failed => QualityStatus::None,
        };
    }

    /**
     * SAP confirmed it: the pallet is held in the non-conforming warehouse.
     */
    protected function afterConfirmed(ProductionReceipt|InventoryTransfer $document): void
    {
        QualityHold::query()
            ->where('inventory_transfer_id', $document->id)
            ->where('status', QualityHoldStatus::Requested)
            ->update(['status' => QualityHoldStatus::NonConforming, 'resolved_by' => $document->requested_by, 'resolved_at' => now(), 'updated_at' => now()]);
    }

    /**
     * SAP refused it: nothing moved, so the hold is cancelled and the pallet is as it was.
     */
    protected function afterFailed(ProductionReceipt|InventoryTransfer $document, string $why): void
    {
        QualityHold::query()
            ->where('inventory_transfer_id', $document->id)
            ->where('status', QualityHoldStatus::Requested)
            ->update(['status' => QualityHoldStatus::Cancelled, 'resolved_by' => $document->requested_by, 'resolved_at' => now(), 'resolution_notes' => mb_substr('SAP no hizo el traslado: '.$why, 0, 1000), 'updated_at' => now()]);
    }

    protected function traceEvent(DocumentPhase $phase): TraceEventType
    {
        return match ($phase) {
            DocumentPhase::Requested => TraceEventType::NonConformingRequested,
            DocumentPhase::Pending => TraceEventType::NonConformingPending,
            DocumentPhase::Confirmed => TraceEventType::NonConformingConfirmed,
            DocumentPhase::Failed => TraceEventType::NonConformingFailed,
        };
    }
}
