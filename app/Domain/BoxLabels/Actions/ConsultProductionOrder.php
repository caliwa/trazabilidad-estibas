<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Looks a production order up in SAP by its number. When it exists and is
 * released, its header and its product are stored locally (production
 * orders and items are read caches of SAP) and the order is returned.
 * Anything else is refused with the reason, and nothing is stored.
 */
class ConsultProductionOrder
{
    public function __construct(private readonly SapGateway $sap, private readonly TraceLogger $trace) {}

    public function handle(User $user, string $docNum, TraceContext $ctx): ProductionOrder
    {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        $docNum = trim($docNum);

        if (preg_match('/^\d{1,20}$/', $docNum) !== 1) {
            throw BoxLabelRuleViolation::invalidNumber();
        }

        try {
            $sapOrder = $this->sap->findProductionOrder($docNum);
        } catch (SapLookupFailed) {
            throw BoxLabelRuleViolation::sapUnavailable();
        }

        if ($sapOrder === null) {
            throw BoxLabelRuleViolation::notFound($docNum);
        }

        if ($sapOrder->status !== ProductionOrderStatus::Released) {
            throw BoxLabelRuleViolation::notReleased($docNum, $sapOrder->status->label());
        }

        return DB::transaction(fn () => $this->store($user, $sapOrder, $ctx));
    }

    private function store(User $user, SapProductionOrder $sapOrder, TraceContext $ctx): ProductionOrder
    {
        $item = Item::query()->where('code', $sapOrder->itemCode)->lockForUpdate()->first() ?? new Item;
        // Shelf life is a local setting; SAP data never overwrites it.
        $item->forceFill([
            'code' => $sapOrder->itemCode,
            'description' => mb_substr($sapOrder->itemDescription, 0, 200),
            'inventory_uom' => $sapOrder->itemInventoryUom ?? $item->inventory_uom,
            'barcode' => $sapOrder->itemBarcode,
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
            'is_open' => true,
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
            'consulted_by' => $user->id,
        ])->save();

        $this->trace->record($ctx, TraceEventType::ProductionOrderConsulted, $order, context: $header);

        return $order;
    }
}
