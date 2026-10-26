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
 * Creates the stock transfer 04 → 05 of audited pallets, one document per
 * pallet.
 *
 * The normal path is automatic: SubmitBlindCount calls this inside the
 * same transaction that approves the count, so an approved count without
 * its transfer request cannot exist. The manual path is only a retry, for
 * a pallet whose transfer SAP rejected or that was counted before this
 * became automatic.
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
     * @param  bool  $automatic  part of an approved blind count; a manual retry needs transfers.reconcile
     * @return list<InventoryTransfer>
     */
    public function handle(User $user, array $palletIds, TraceContext $ctx, bool $automatic = false): array
    {
        if (! $automatic) {
            Gate::forUser($user)->authorize(Permission::TransfersReconcile->value);
        }

        $palletIds = array_values(array_unique(array_map('intval', $palletIds)));

        if ($palletIds === []) {
            throw TransferRuleViolation::noPallets();
        }

        $transfers = DB::transaction(function () use ($user, $palletIds, $ctx): array {
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

                if ($pallet->quality_status->blocksFlow()) {
                    throw TransferRuleViolation::heldByQuality($pallet->pallet_code, $pallet->quality_status->label());
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

            return array_values($pallets->map(fn (Pallet $p) => $this->create($user, $p, $sources[$p->id], $ctx))->all());
        });

        // After the outermost commit: when called from the blind count, that is the count's.
        foreach ($transfers as $transfer) {
            SendInventoryTransfer::dispatch($transfer->id)->afterCommit();
        }

        return $transfers;
    }

    /**
     * @param  array{ProductionReceiptPallet, PalletAudit}  $source
     */
    private function create(User $user, Pallet $pallet, array $source, TraceContext $ctx): InventoryTransfer
    {
        [$receiptLine, $audit] = $source;
        $uuid = (string) Str::uuid();
        $from = config()->string('sap.warehouses.transfer_from');
        $to = config()->string('sap.warehouses.transfer_to');
        $quantity = Quantity::ofBoxes($pallet->boxes_count, $receiptLine->units_per_box)->value;

        $payload = [
            'request_uuid' => $uuid,
            'from_warehouse' => $from,
            'to_warehouse' => $to,
            'lines' => [[
                'item_code' => $pallet->item_code,
                'lot_code' => $pallet->lot_code,
                'quantity' => $quantity,
                'warehouse' => $to,
            ]],
        ];

        $transfer = new InventoryTransfer;
        $transfer->forceFill([
            'request_uuid' => $uuid,
            'mode' => TransferMode::Individual,
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
            'pallet_audit_id' => $audit->id,
            'hold_status' => PalletHold::Active,
            'boxes' => $pallet->boxes_count,
            'box_conversion_factor_id' => $receiptLine->box_conversion_factor_id,
            'units_per_box' => $receiptLine->units_per_box,
            'sap_quantity' => $quantity,
            'item_code' => $pallet->item_code,
            'lot_code' => $pallet->lot_code,
        ])->save();

        $fromStatus = $pallet->transfer_status;
        $pallet->forceFill(['transfer_status' => TransferStatus::Requested])->save();

        $this->trace->record($ctx, TraceEventType::TransferRequested, $pallet,
            from: ['transfer_status' => $fromStatus->value],
            to: ['transfer_status' => TransferStatus::Requested->value],
            context: ['request_uuid' => $uuid, 'pallet_audit_id' => $audit->id]);

        return $transfer;
    }
}
