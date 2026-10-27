<?php

namespace App\Domain\Quality\Actions;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
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
 * Quality holds a pallet before the blind count, and holding it moves it
 * to the non-conforming warehouse: a stock transfer 04 → 11C with exactly
 * what SAP received for it (its confirmed receipt line), sent after the
 * commit and verified like any transfer (NonConformingTransferLifecycle).
 * While held it is neither counted nor transferred to 05.
 *
 * A pallet quality already released is still in 11C (it leaves only with
 * the count's transfer to 05): holding it again moves nothing in SAP.
 */
class HoldPallet
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, string $reason, TraceContext $ctx): QualityHold
    {
        Gate::forUser($user)->authorize(Permission::QualityManage->value);

        [$hold, $transfer] = DB::transaction(function () use ($user, $palletId, $reason, $ctx): array {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);

            if ($pallet->physical_status !== PhysicalStatus::Labeled) {
                throw QualityRuleViolation::notLabeled($pallet->pallet_code);
            }

            match ($pallet->audit_status) {
                AuditStatus::Counting => throw QualityRuleViolation::beingCounted($pallet->pallet_code),
                AuditStatus::Approved => throw QualityRuleViolation::alreadyCounted($pallet->pallet_code),
                AuditStatus::Pending, AuditStatus::Discrepancy => null,
            };

            return match ($pallet->quality_status) {
                QualityStatus::None => $this->moveToNonConforming($user, $pallet, trim($reason), $ctx),
                QualityStatus::Released => [$this->holdAgainInPlace($user, $pallet, trim($reason), $ctx), null],
                default => throw QualityRuleViolation::notHoldable($pallet->pallet_code, $pallet->quality_status->label()),
            };
        });

        if ($transfer instanceof InventoryTransfer) {
            SendInventoryTransfer::dispatch($transfer->id)->afterCommit();
        }

        return $hold;
    }

    /**
     * @return array{QualityHold, InventoryTransfer}
     */
    private function moveToNonConforming(User $user, Pallet $pallet, string $reason, TraceContext $ctx): array
    {
        $receiptLine = ProductionReceiptPallet::query()->where('pallet_id', $pallet->id)->where('hold_status', PalletHold::Confirmed)->first();

        if ($pallet->receipt_status !== ReceiptStatus::Confirmed || $receiptLine === null) {
            throw QualityRuleViolation::notReceived($pallet->pallet_code);
        }

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
            'purpose' => TransferPurpose::NonConforming,
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

        $hold = new QualityHold;
        $hold->forceFill([
            'pallet_id' => $pallet->id,
            'held_by' => $user->id,
            'held_at' => now(),
            'reason' => $reason,
            'status' => QualityHoldStatus::Requested,
            'inventory_transfer_id' => $transfer->id,
        ])->save();

        $pallet->forceFill(['quality_status' => QualityStatus::NonConformingRequested])->save();

        $this->trace->record($ctx, TraceEventType::QualityHeld, $pallet,
            from: ['quality_status' => QualityStatus::None->value], to: ['quality_status' => QualityStatus::NonConformingRequested->value],
            reason: $reason, context: ['quality_hold_id' => $hold->id, 'request_uuid' => $uuid, 'from_warehouse' => $from, 'to_warehouse' => $to, 'quantity' => $quantity]);

        return [$hold, $transfer];
    }

    private function holdAgainInPlace(User $user, Pallet $pallet, string $reason, TraceContext $ctx): QualityHold
    {
        // It never left 11C: the transfer that took it there backs this hold too.
        $transferId = (int) QualityHold::query()->where('pallet_id', $pallet->id)->where('status', QualityHoldStatus::Released)->latest('id')->value('inventory_transfer_id');

        $hold = new QualityHold;
        $hold->forceFill([
            'pallet_id' => $pallet->id,
            'held_by' => $user->id,
            'held_at' => now(),
            'reason' => $reason,
            'status' => QualityHoldStatus::NonConforming,
            'inventory_transfer_id' => $transferId,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ])->save();

        $pallet->forceFill(['quality_status' => QualityStatus::NonConforming])->save();

        $this->trace->record($ctx, TraceEventType::QualityHeld, $pallet,
            from: ['quality_status' => QualityStatus::Released->value], to: ['quality_status' => QualityStatus::NonConforming->value],
            reason: $reason, context: ['quality_hold_id' => $hold->id, 'note' => 'Sigue en no conforme: no hay traslado en SAP.']);

        return $hold;
    }
}
