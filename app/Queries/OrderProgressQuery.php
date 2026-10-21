<?php

namespace App\Queries;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Integration\ConversionFactors;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Orders\OrderProgress;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Models\ProductionOrder;
use App\Support\Quantities;
use Illuminate\Support\Facades\DB;

/**
 * Progress of several production orders in a fixed number of queries:
 * labels, reads, pallets, receipts, counts and transfers, each added up
 * per order in SQL.
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

        $orders = ProductionOrder::query()->whereKey($orderIds)->get(['id', 'item_id', 'sap_status', 'planned_quantity', 'uom_name', 'sap_completed_quantity'])->keyBy('id');
        $in = implode(',', array_fill(0, count($orderIds), '?'));

        $labels = $this->keyed("SELECT production_order_id AS id, sum(labels) AS labels FROM box_label_batches WHERE production_order_id IN ({$in}) GROUP BY production_order_id", $orderIds);

        $pallets = $this->keyed(
            "SELECT production_order_id AS id,
                sum(boxes_count) AS boxes_read,
                count(*) FILTER (WHERE physical_status = ?) AS stacking,
                count(*) FILTER (WHERE physical_status IN (?, ?)) AS finished,
                count(*) FILTER (WHERE physical_status = ?) AS labeled,
                count(*) FILTER (WHERE physical_status = ? AND receipt_status IN (?, ?)) AS labeled_not_received,
                coalesce(sum(boxes_count) FILTER (WHERE receipt_status = ?), 0) AS received_boxes,
                coalesce(sum(boxes_count) FILTER (WHERE audit_status = ?), 0) AS approved_boxes,
                coalesce(sum(boxes_count) FILTER (WHERE transfer_status = ?), 0) AS transferred_boxes
             FROM pallets WHERE production_order_id IN ({$in}) GROUP BY production_order_id",
            [
                PhysicalStatus::Stacking->value, PhysicalStatus::Finished->value, PhysicalStatus::Palletized->value, PhysicalStatus::Labeled->value,
                PhysicalStatus::Labeled->value, ReceiptStatus::None->value, ReceiptStatus::Failed->value,
                ReceiptStatus::Confirmed->value, AuditStatus::Approved->value, TransferStatus::Confirmed->value,
                ...$orderIds,
            ],
        );

        // Units as SAP received them: the exact quantity of each confirmed receipt line.
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

        $receipts = $this->keyed(
            "SELECT production_order_id AS id,
                count(*) FILTER (WHERE status IN (?, ?)) AS in_flight,
                count(*) FILTER (WHERE status = ?) AS pending
             FROM production_receipts WHERE production_order_id IN ({$in}) GROUP BY production_order_id",
            [SapDocumentStatus::Requested->value, SapDocumentStatus::Sending->value, SapDocumentStatus::PendingReconciliation->value, ...$orderIds],
        );

        $transfers = $this->keyed(
            "SELECT p.production_order_id AS id, count(DISTINCT t.id) AS pending
             FROM inventory_transfers t
             JOIN inventory_transfer_pallets tp ON tp.inventory_transfer_id = t.id
             JOIN pallets p ON p.id = tp.pallet_id
             WHERE t.status = ? AND p.production_order_id IN ({$in})
             GROUP BY p.production_order_id",
            [SapDocumentStatus::PendingReconciliation->value, ...$orderIds],
        );

        $discrepancies = $this->keyed(
            "SELECT p.production_order_id AS id, count(*) AS open
             FROM discrepancy_cases d JOIN pallets p ON p.id = d.pallet_id
             WHERE d.status <> ? AND p.production_order_id IN ({$in})
             GROUP BY p.production_order_id",
            [DiscrepancyStatus::Resolved->value, ...$orderIds],
        );

        $factors = [];
        $result = [];
        foreach ($orderIds as $id) {
            $order = $orders->get($id);

            if ($order === null) {
                continue;
            }

            $factors[$order->item_id] ??= $this->factors->current($order->item_id, now())?->units_per_box;
            $int = fn (array $rows, string $key): int => (int) ($rows[$id]->{$key} ?? 0);
            $micro = fn (array $rows, string $key): int => Quantities::toMicro($rows[$id]->{$key} ?? null);

            $result[$id] = new OrderProgress(
                orderId: $id,
                sapStatus: $order->sap_status,
                plannedMicro: $order->planned_quantity === null ? null : Quantities::toMicro($order->planned_quantity),
                uom: $order->uom_name,
                unitsPerBoxMicro: $factors[$order->item_id] === null ? null : Quantities::toMicro($factors[$order->item_id]),
                sapCompletedMicro: $order->sap_completed_quantity === null ? null : Quantities::toMicro($order->sap_completed_quantity),
                labels: $int($labels, 'labels'),
                boxesRead: $int($pallets, 'boxes_read'),
                palletsStacking: $int($pallets, 'stacking'),
                palletsFinished: $int($pallets, 'finished'),
                palletsLabeled: $int($pallets, 'labeled'),
                receivedBoxes: $int($pallets, 'received_boxes'),
                receivedMicro: $micro($received, 'received'),
                approvedBoxes: $int($pallets, 'approved_boxes'),
                transferredBoxes: $int($pallets, 'transferred_boxes'),
                transferredMicro: $micro($received, 'transferred'),
                receiptsInFlight: $int($receipts, 'in_flight'),
                receiptsPending: $int($receipts, 'pending'),
                transfersPending: $int($transfers, 'pending'),
                discrepanciesOpen: $int($discrepancies, 'open'),
                labeledNotReceived: $int($pallets, 'labeled_not_received'),
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
