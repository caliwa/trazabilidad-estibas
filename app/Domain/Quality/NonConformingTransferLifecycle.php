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
 * Stock transfers 04 → 11C (non-conforming warehouse) requested by quality.
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
            // SAP refused it: the pallet is held again, to retry or release.
            DocumentPhase::Failed => QualityStatus::Held,
        };
    }

    /**
     * SAP confirmed it: the hold that asked for it closes as non-conforming.
     */
    protected function afterConfirmed(ProductionReceipt|InventoryTransfer $document): void
    {
        QualityHold::query()
            ->where('inventory_transfer_id', $document->id)
            ->where('status', QualityHoldStatus::Open)
            ->update(['status' => QualityHoldStatus::NonConforming, 'resolved_by' => $document->requested_by, 'resolved_at' => now(), 'updated_at' => now()]);
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
