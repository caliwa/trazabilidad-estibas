<?php

namespace App\Domain\Orders;

use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\TraceLogger;

/**
 * Local copy of a production order and its product as SAP reports them.
 * Used by the lookup of the box labels screen and by the refresh of the
 * order board. Call it inside a transaction.
 */
final class ProductionOrderCache
{
    public function __construct(private readonly TraceLogger $trace) {}

    /**
     * Stores the order as SAP reports it, in any status. Only released
     * orders stay open for scans, labels and receipts.
     *
     * @throws BoxLabelRuleViolation when SAP moved the order to another product and it already has pallets
     */
    public function store(SapProductionOrder $sapOrder, ?User $user, TraceContext $ctx, TraceEventType $event): ProductionOrder
    {
        $item = Item::query()->where('code', $sapOrder->itemCode)->lockForUpdate()->first() ?? new Item;
        // Shelf life is a local setting; SAP data never overwrites it.
        $item->forceFill([
            'code' => $sapOrder->itemCode,
            'description' => mb_substr($sapOrder->itemDescription, 0, 200),
            'inventory_uom' => $sapOrder->itemInventoryUom ?? $item->inventory_uom,
            'barcode' => $sapOrder->itemBarcode,
            'sales_qty_per_pack_unit' => $sapOrder->itemSalesQtyPerPackUnit,
            'sales_packaging_unit' => $sapOrder->itemSalesPackagingUnit === null ? null : mb_substr($sapOrder->itemSalesPackagingUnit, 0, 30),
            'active' => true,
            'synced_at' => now(),
        ])->save();

        $order = ProductionOrder::query()->where('ref', $sapOrder->docNum)->lockForUpdate()->first() ?? new ProductionOrder;

        if ($order->exists && $order->item_id !== $item->id && Pallet::query()->where('production_order_id', $order->id)->exists()) {
            throw BoxLabelRuleViolation::itemChanged($sapOrder->docNum);
        }

        $header = [
            'doc_entry' => $sapOrder->docEntry,
            'doc_num' => $sapOrder->docNum,
            'type' => $sapOrder->type,
            'status' => $sapOrder->status->value,
            'item_code' => $sapOrder->itemCode,
            'item_description' => $sapOrder->itemDescription,
            'planned_quantity' => $sapOrder->plannedQuantity->value,
            'uom_name' => $sapOrder->uomName,
            'warehouse' => $sapOrder->warehouse,
            'priority' => $sapOrder->priority,
            'order_date' => $sapOrder->orderDate?->toDateString(),
            'start_date' => $sapOrder->startDate?->toDateString(),
            'due_date' => $sapOrder->dueDate?->toDateString(),
            'user' => $sapOrder->userName,
            'origin' => $sapOrder->origin,
            'linked_to' => $sapOrder->linkedTo,
        ];

        $order->forceFill([
            'ref' => $sapOrder->docNum,
            'sap_doc_entry' => $sapOrder->docEntry,
            'item_id' => $item->id,
            'sap_status' => $sapOrder->status->value,
            'is_open' => $sapOrder->status === ProductionOrderStatus::Released,
            'synced_at' => now(),
            'sap_order_type' => $sapOrder->type,
            'planned_quantity' => $sapOrder->plannedQuantity->value,
            'uom_name' => $sapOrder->uomName,
            'warehouse_code' => $sapOrder->warehouse,
            'priority' => $sapOrder->priority,
            'order_date' => $sapOrder->orderDate,
            'start_date' => $sapOrder->startDate,
            'due_date' => $sapOrder->dueDate,
            'sap_user' => $sapOrder->userName,
            'sap_origin' => $sapOrder->origin,
            'sap_linked_to' => $sapOrder->linkedTo,
            'sap_header' => $header,
            'consulted_at' => now(),
            // Null when the console brought it (sap:sync-orders).
            'consulted_by' => $user?->id,
        ])->save();

        $this->trace->record($ctx, $event, $order, context: $header);

        return $order;
    }
}
