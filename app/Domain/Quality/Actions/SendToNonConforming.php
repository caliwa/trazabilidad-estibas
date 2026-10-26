<?php

namespace App\Domain\Quality\Actions;

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Quality\Enums\QualityHoldStatus;
use App\Domain\Quality\Enums\QualityStatus;
use App\Domain\Quality\Exceptions\QualityRuleViolation;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferMode;
use App\Domain\Transfers\Enums\TransferPurpose;
use App\Enums\Permission;
use App\Integrations\Sap\Dto\Quantity;
use App\Jobs\SendInventoryTransfer;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\Pallet;
use App\Models\ProductionReceiptPallet;
use App\Models\QualityHold;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Quality sends a held pallet to the non-conforming warehouse: a stock
 * transfer 04 → 11C with exactly what SAP received for it (the quantity and
 * factor of its confirmed receipt line). It is sent to SAP after the
 * commit and confirmed like any transfer (NonConformingTransferLifecycle).
 * The hold closes as NO_CONFORME once SAP confirms; if SAP refuses, the
 * pallet stays held.
 */
class SendToNonConforming
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, string $notes, TraceContext $ctx): InventoryTransfer
    {
        Gate::forUser($user)->authorize(Permission::QualityManage->value);

        $transfer = DB::transaction(function () use ($user, $palletId, $notes, $ctx): InventoryTransfer {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);

            if ($pallet->quality_status !== QualityStatus::Held) {
                throw QualityRuleViolation::notHeld($pallet->pallet_code, $pallet->quality_status->label());
            }

            $receiptLine = ProductionReceiptPallet::query()->where('pallet_id', $pallet->id)->where('hold_status', PalletHold::Confirmed)->first();

            if ($pallet->receipt_status !== ReceiptStatus::Confirmed || $receiptLine === null) {
                throw QualityRuleViolation::notReceived($pallet->pallet_code);
            }

            $hold = QualityHold::query()->where('pallet_id', $pallet->id)->where('status', QualityHoldStatus::Open)->lockForUpdate()->firstOrFail();

            $uuid = (string) Str::uuid();
            $from = config()->string('sap.warehouses.transfer_from');
            $to = config()->string('sap.warehouses.non_conforming');
            $quantity = Quantity::ofBoxes($pallet->boxes_count, $receiptLine->units_per_box)->value;
            $payload = [
                'request_uuid' => $uuid,
                'from_warehouse' => $from,
                'to_warehouse' => $to,
                'lines' => [['item_code' => $pallet->item_code, 'lot_code' => $pallet->lot_code, 'quantity' => $quantity, 'warehouse' => $to]],
            ];

            $transfer = new InventoryTransfer;
            $transfer->forceFill([
                'request_uuid' => $uuid,
                'mode' => TransferMode::Individual,
                'purpose' => TransferPurpose::NonConforming,
                'from_warehouse' => $from,
                'to_warehouse' => $to,
                'status' => SapDocumentStatus::Requested,
                'payload' => $payload,
                'payload_hash' => hash('sha256', (string) json_encode($payload)),
                'requested_by' => $user->id,
                'requested_at' => now(),
            ])->save();

            (new InventoryTransferPallet)->forceFill([
                'inventory_transfer_id' => $transfer->id,
                'pallet_id' => $pallet->id,
                'pallet_audit_id' => null,
                'hold_status' => PalletHold::Active,
                'boxes' => $pallet->boxes_count,
                'box_conversion_factor_id' => $receiptLine->box_conversion_factor_id,
                'units_per_box' => $receiptLine->units_per_box,
                'sap_quantity' => $quantity,
                'item_code' => $pallet->item_code,
                'lot_code' => $pallet->lot_code,
            ])->save();

            // The decision is recorded now; the hold closes when SAP confirms the transfer.
            $hold->forceFill(['inventory_transfer_id' => $transfer->id, 'resolution_notes' => trim($notes)])->save();
            $pallet->forceFill(['quality_status' => QualityStatus::NonConformingRequested])->save();

            $this->trace->record($ctx, TraceEventType::NonConformingRequested, $pallet,
                from: ['quality_status' => QualityStatus::Held->value], to: ['quality_status' => QualityStatus::NonConformingRequested->value],
                reason: trim($notes), context: ['request_uuid' => $uuid, 'quality_hold_id' => $hold->id, 'to_warehouse' => $to, 'quantity' => $quantity]);

            return $transfer;
        });

        SendInventoryTransfer::dispatch($transfer->id)->afterCommit();

        return $transfer;
    }
}
