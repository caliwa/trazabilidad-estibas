<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferMode;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Enums\Permission;
use App\Integrations\Sap\Dto\Quantity;
use App\Jobs\SendInventoryTransfer;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\ProductionReceiptPallet;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Creates transfer requests 04 → 05 for audited pallets.
 *
 * Individual (one document per pallet) is the default. Grouped puts several
 * pallets in one document: only with DP-05 enabled, an explicit
 * confirmation, and pallets of the same item and lot.
 *
 * What is transferred is exactly what SAP received: each line takes the
 * quantity and conversion factor of the pallet's confirmed receipt line,
 * and links the approved audit that made it eligible.
 */
class RequestInventoryTransfers
{
    public function __construct(private readonly TraceLogger $trace) {}

    /**
     * @param  list<int>  $palletIds
     * @param  bool  $automatic  started by the system right after an approved count (traceability.decisions.auto_transfer_after_audit)
     * @return list<InventoryTransfer>
     */
    public function handle(User $user, array $palletIds, TransferMode $mode, bool $groupingConfirmed, TraceContext $ctx, bool $automatic = false): array
    {
        if (! $automatic) {
            Gate::forUser($user)->authorize(Permission::TransfersRequest->value);
        }

        $palletIds = array_values(array_unique(array_map('intval', $palletIds)));

        if ($palletIds === []) {
            throw TransferRuleViolation::noPallets();
        }

        if ($mode === TransferMode::Grouped) {
            if (! config()->boolean('traceability.decisions.grouped_transfers_enabled')) {
                throw TransferRuleViolation::groupingUndecided();
            }

            if (! $groupingConfirmed) {
                throw TransferRuleViolation::groupingNotConfirmed();
            }
        }

        $transfers = DB::transaction(function () use ($user, $palletIds, $mode, $ctx): array {
            // Fixed order so overlapping requests never deadlock.
            $pallets = Pallet::query()->whereKey($palletIds)->orderBy('id')->lockForUpdate()->get();

            if ($pallets->count() !== count($palletIds)) {
                throw TransferRuleViolation::missing();
            }

            $sources = [];
            foreach ($pallets as $pallet) {
                if ($pallet->audit_status !== AuditStatus::Approved) {
                    throw TransferRuleViolation::notApproved($pallet->pallet_code);
                }

                if (! in_array($pallet->transfer_status, [TransferStatus::Ready, TransferStatus::Failed], true)) {
                    throw TransferRuleViolation::notAvailable($pallet->pallet_code, $pallet->transfer_status->label());
                }

                $receiptLine = ProductionReceiptPallet::query()->where('pallet_id', $pallet->id)->where('hold_status', PalletHold::Confirmed)->first();

                if ($pallet->receipt_status !== ReceiptStatus::Confirmed || $receiptLine === null) {
                    throw TransferRuleViolation::noConfirmedReceipt($pallet->pallet_code);
                }

                $audit = PalletAudit::query()->where('pallet_id', $pallet->id)->where('status', CountStatus::Approved)->latest('id')->firstOrFail();
                $sources[$pallet->id] = [$receiptLine, $audit];
            }

            if ($mode === TransferMode::Grouped && $pallets->map(fn (Pallet $p) => "{$p->item_code}|{$p->lot_code}")->unique()->count() !== 1) {
                throw TransferRuleViolation::incompatibleForGrouping();
            }

            $groups = $mode === TransferMode::Individual ? $pallets->map(fn (Pallet $p) => [$p])->all() : [array_values($pallets->all())];

            return array_values(array_map(fn (array $group) => $this->create($user, $group, $sources, $mode, $ctx), $groups));
        });

        foreach ($transfers as $transfer) {
            SendInventoryTransfer::dispatch($transfer->id)->afterCommit();
        }

        return $transfers;
    }

    /**
     * @param  list<Pallet>  $pallets
     * @param  array<int, array{ProductionReceiptPallet, PalletAudit}>  $sources
     */
    private function create(User $user, array $pallets, array $sources, TransferMode $mode, TraceContext $ctx): InventoryTransfer
    {
        $uuid = (string) Str::uuid();
        $from = config()->string('sap.warehouses.transfer_from');
        $to = config()->string('sap.warehouses.transfer_to');

        // One SAP line per item + lot; quantities add up exactly from the received ones.
        $sapLines = [];
        foreach ($pallets as $pallet) {
            $key = "{$pallet->item_code}|{$pallet->lot_code}";
            $boxes = ($sapLines[$key]['boxes'] ?? 0) + $pallet->boxes_count;
            $sapLines[$key] = ['item_code' => $pallet->item_code, 'lot_code' => $pallet->lot_code, 'boxes' => $boxes, 'factor' => $sources[$pallet->id][0]->units_per_box];
        }

        $payload = [
            'request_uuid' => $uuid,
            'from_warehouse' => $from,
            'to_warehouse' => $to,
            'lines' => array_values(array_map(fn (array $l) => [
                'item_code' => $l['item_code'],
                'lot_code' => $l['lot_code'],
                'quantity' => Quantity::ofBoxes($l['boxes'], $l['factor'])->value,
                'warehouse' => $to,
            ], $sapLines)),
        ];

        $transfer = new InventoryTransfer;
        $transfer->forceFill([
            'request_uuid' => $uuid,
            'mode' => $mode,
            'from_warehouse' => $from,
            'to_warehouse' => $to,
            'status' => SapDocumentStatus::Requested,
            'payload' => $payload,
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'requested_by' => $user->id,
            'requested_at' => now(),
        ])->save();

        foreach ($pallets as $pallet) {
            [$receiptLine, $audit] = $sources[$pallet->id];

            (new InventoryTransferPallet)->forceFill([
                'inventory_transfer_id' => $transfer->id,
                'pallet_id' => $pallet->id,
                'pallet_audit_id' => $audit->id,
                'hold_status' => PalletHold::Active,
                'boxes' => $pallet->boxes_count,
                'box_conversion_factor_id' => $receiptLine->box_conversion_factor_id,
                'units_per_box' => $receiptLine->units_per_box,
                'sap_quantity' => Quantity::ofBoxes($pallet->boxes_count, $receiptLine->units_per_box)->value,
                'item_code' => $pallet->item_code,
                'lot_code' => $pallet->lot_code,
            ])->save();

            $fromStatus = $pallet->transfer_status;
            $pallet->forceFill(['transfer_status' => TransferStatus::Requested])->save();

            $this->trace->record($ctx, TraceEventType::TransferRequested, $pallet,
                from: ['transfer_status' => $fromStatus->value],
                to: ['transfer_status' => TransferStatus::Requested->value],
                context: ['request_uuid' => $uuid, 'mode' => $mode->value, 'pallet_audit_id' => $audit->id]);
        }

        return $transfer;
    }
}
