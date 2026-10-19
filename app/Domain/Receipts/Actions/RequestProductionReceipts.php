<?php

namespace App\Domain\Receipts\Actions;

use App\Domain\Integration\ConversionFactors;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Receipts\Exceptions\ReceiptRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Dto\Quantity;
use App\Jobs\SendProductionReceipt;
use App\Models\BoxConversionFactor;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Creates production receipt requests for labeled pallets of one order:
 * a single document holding all of them, or one document per pallet.
 *
 * Everything is stored before SAP is called: the request_uuid, the exact
 * payload and its hash, and the pallets held by a line in ACTIVA (the
 * partial unique index makes a second hold impossible). The send job is
 * queued after commit; the sweeper picks up any request whose job was lost.
 */
class RequestProductionReceipts
{
    public function __construct(
        private readonly ConversionFactors $factors,
        private readonly TraceLogger $trace,
    ) {}

    /**
     * @param  list<int>  $palletIds
     * @return list<ProductionReceipt>
     */
    public function handle(User $user, array $palletIds, bool $onePerPallet, TraceContext $ctx): array
    {
        Gate::forUser($user)->authorize(Permission::ReceiptsRequest->value);

        $palletIds = array_values(array_unique(array_map('intval', $palletIds)));

        if ($palletIds === []) {
            throw ReceiptRuleViolation::noPallets();
        }

        $receipts = DB::transaction(function () use ($user, $palletIds, $onePerPallet, $ctx): array {
            // Fixed order so two requests over overlapping pallets never deadlock.
            $pallets = Pallet::query()->whereKey($palletIds)->orderBy('id')->lockForUpdate()->get();

            if ($pallets->count() !== count($palletIds)) {
                throw ReceiptRuleViolation::missing();
            }

            if ($pallets->pluck('production_order_id')->unique()->count() !== 1) {
                throw ReceiptRuleViolation::mixedOrders();
            }

            $order = ProductionOrder::query()->findOrFail($pallets->first()?->production_order_id);

            // Local copy of SAP; whether SAP closes orders after partial receipts is DP-06.
            if (! $order->is_open) {
                throw ReceiptRuleViolation::orderClosed($order->ref);
            }

            $factors = [];
            foreach ($pallets as $pallet) {
                if ($pallet->physical_status !== PhysicalStatus::Labeled) {
                    throw ReceiptRuleViolation::notLabeled($pallet->pallet_code);
                }

                if (! in_array($pallet->receipt_status, [ReceiptStatus::None, ReceiptStatus::Failed], true)) {
                    throw ReceiptRuleViolation::notAvailable($pallet->pallet_code, $pallet->receipt_status->label());
                }

                $factors[$pallet->item_id] ??= $this->factors->current($pallet->item_id, now()) ?? throw ReceiptRuleViolation::missingFactor($pallet->item_code);
            }

            $groups = $onePerPallet ? $pallets->map(fn (Pallet $p) => collect([$p]))->all() : [$pallets];

            return array_map(fn ($group) => $this->create($user, $order, array_values($group->all()), $factors, $ctx), array_values($groups));
        });

        foreach ($receipts as $receipt) {
            SendProductionReceipt::dispatch($receipt->id)->afterCommit();
        }

        return $receipts;
    }

    /**
     * @param  list<Pallet>  $pallets
     * @param  array<int, BoxConversionFactor>  $factors
     */
    private function create(User $user, ProductionOrder $order, array $pallets, array $factors, TraceContext $ctx): ProductionReceipt
    {
        $uuid = (string) Str::uuid();
        $warehouse = config()->string('sap.warehouses.production_receipt');

        // One SAP line per item + lot; each pallet keeps its own quantity in its local line.
        $sapLines = [];
        foreach ($pallets as $pallet) {
            $key = "{$pallet->item_code}|{$pallet->lot_code}";
            $boxes = ($sapLines[$key]['boxes'] ?? 0) + $pallet->boxes_count;
            $sapLines[$key] = ['item_code' => $pallet->item_code, 'lot_code' => $pallet->lot_code, 'boxes' => $boxes, 'factor' => (string) $factors[$pallet->item_id]->units_per_box];
        }

        $payload = [
            'request_uuid' => $uuid,
            'production_order_ref' => $order->ref,
            'lines' => array_values(array_map(fn (array $l) => [
                'item_code' => $l['item_code'],
                'lot_code' => $l['lot_code'],
                'quantity' => Quantity::ofBoxes($l['boxes'], $l['factor'])->value,
                'warehouse' => $warehouse,
            ], $sapLines)),
        ];

        $receipt = new ProductionReceipt;
        $receipt->forceFill([
            'request_uuid' => $uuid,
            'production_order_id' => $order->id,
            'status' => SapDocumentStatus::Requested,
            'payload' => $payload,
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'requested_by' => $user->id,
            'requested_at' => now(),
        ])->save();

        foreach ($pallets as $pallet) {
            $factor = $factors[$pallet->item_id];

            (new ProductionReceiptPallet)->forceFill([
                'production_receipt_id' => $receipt->id,
                'pallet_id' => $pallet->id,
                'hold_status' => PalletHold::Active,
                'boxes' => $pallet->boxes_count,
                'box_conversion_factor_id' => $factor->id,
                'units_per_box' => $factor->units_per_box,
                'sap_quantity' => Quantity::ofBoxes($pallet->boxes_count, (string) $factor->units_per_box)->value,
                'item_code' => $pallet->item_code,
                'lot_code' => $pallet->lot_code,
            ])->save();

            $from = $pallet->receipt_status;
            $pallet->forceFill(['receipt_status' => ReceiptStatus::Requested])->save();

            $this->trace->record($ctx, TraceEventType::ReceiptRequested, $pallet,
                from: ['receipt_status' => $from->value],
                to: ['receipt_status' => ReceiptStatus::Requested->value],
                context: ['request_uuid' => $uuid],
            );
        }

        return $receipt;
    }
}
