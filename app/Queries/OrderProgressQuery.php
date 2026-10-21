<?php

namespace App\Queries;

use App\Domain\Integration\ConversionFactors;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Orders\OrderProgress;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Models\ProductionOrder;
use App\Support\Quantities;
use Illuminate\Support\Facades\DB;

/**
 * Progress of several production orders in a fixed number of queries.
 * Received and transferred units are the exact quantities of the confirmed
 * receipt lines, the same ones SAP got.
 */
class OrderProgressQuery
{
    public function __construct(private readonly ConversionFactors $factors) {}

    /**
     * @param  list<int>  $orderIds
     * @return array<int, OrderProgress>
     */
    public function for(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $orders = ProductionOrder::query()->whereKey($orderIds)->get(['id', 'item_id', 'sap_status', 'planned_quantity'])->keyBy('id');
        $in = implode(',', array_fill(0, count($orderIds), '?'));

        $received = $this->keyed(
            "SELECT r.production_order_id AS id,
                sum(l.sap_quantity) AS received,
                coalesce(sum(l.sap_quantity) FILTER (WHERE p.transfer_status = ?), 0) AS transferred
             FROM production_receipt_pallets l
             JOIN production_receipts r ON r.id = l.production_receipt_id
             JOIN pallets p ON p.id = l.pallet_id
             WHERE l.hold_status = ? AND r.production_order_id IN ({$in})
             GROUP BY r.production_order_id",
            [TransferStatus::Confirmed->value, PalletHold::Confirmed->value, ...$orderIds],
        );

        $pending = $this->keyed(
            "SELECT id, sum(n) AS pending FROM (
                SELECT production_order_id AS id, count(*) AS n FROM production_receipts
                WHERE status = ? AND production_order_id IN ({$in}) GROUP BY production_order_id
                UNION ALL
                SELECT p.production_order_id, count(DISTINCT t.id) FROM inventory_transfers t
                JOIN inventory_transfer_pallets tp ON tp.inventory_transfer_id = t.id
                JOIN pallets p ON p.id = tp.pallet_id
                WHERE t.status = ? AND p.production_order_id IN ({$in}) GROUP BY p.production_order_id
             ) x GROUP BY id",
            [SapDocumentStatus::PendingReconciliation->value, ...$orderIds, SapDocumentStatus::PendingReconciliation->value, ...$orderIds],
        );

        $factors = [];
        $result = [];
        foreach ($orderIds as $id) {
            $order = $orders->get($id);

            if ($order === null) {
                continue;
            }

            $factors[$order->item_id] ??= $this->factors->current($order->item_id, now())?->units_per_box;

            $result[$id] = new OrderProgress(
                orderId: $id,
                sapStatus: $order->sap_status,
                plannedMicro: $order->planned_quantity === null ? null : Quantities::toMicro($order->planned_quantity),
                unitsPerBoxMicro: $factors[$order->item_id] === null ? null : Quantities::toMicro($factors[$order->item_id]),
                receivedMicro: Quantities::toMicro($received[$id]->received ?? null),
                transferredMicro: Quantities::toMicro($received[$id]->transferred ?? null),
                pendingInSap: (int) ($pending[$id]->pending ?? 0),
            );
        }

        return $result;
    }

    public function forOrder(int $orderId): ?OrderProgress
    {
        return $this->for([$orderId])[$orderId] ?? null;
    }

    /**
     * @param  list<int|string>  $bindings
     * @return array<int, object>
     */
    private function keyed(string $sql, array $bindings): array
    {
        $rows = [];
        foreach (DB::select($sql, $bindings) as $row) {
            $rows[(int) $row->id] = $row;
        }

        return $rows;
    }
}
