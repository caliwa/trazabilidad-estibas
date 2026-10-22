<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\BoxLabelZpl;
use App\Domain\BoxLabels\BoxLot;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\BoxLabels\Itf14;
use App\Domain\BoxLabels\LotDates;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabelBatch;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Generates a batch of identical box labels for a released production
 * order. The operator chooses how many; across all batches an order never
 * gets more labels than its planned quantity fills, at the current units
 * per box (rounding the last box up).
 *
 * The batch keeps the exact QR text and ZPL, so what was printed can
 * always be shown again.
 */
class GenerateBoxLabels
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $productionOrderId, int $labels, CarbonImmutable $productionDate, ?CarbonImmutable $expirationDate, TraceContext $ctx): BoxLabelBatch
    {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        if ($productionDate->startOfDay()->greaterThan(today())) {
            throw BoxLabelRuleViolation::futureProductionDate();
        }

        return DB::transaction(function () use ($user, $productionOrderId, $labels, $productionDate, $expirationDate, $ctx): BoxLabelBatch {
            // The lock serialises batches of one order, so two operators cannot pass the limit together.
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($productionOrderId);

            if ($order->consulted_at === null || $order->planned_quantity === null) {
                throw BoxLabelRuleViolation::notConsulted();
            }

            if ($order->sap_status !== ProductionOrderStatus::Released->value || ! $order->is_open) {
                throw BoxLabelRuleViolation::notReleased($order->ref, ProductionOrderStatus::tryFrom((string) $order->sap_status)?->label() ?? (string) $order->sap_status);
            }

            $item = Item::query()->findOrFail($order->item_id);

            if (blank($item->barcode)) {
                throw BoxLabelRuleViolation::noBarcode($item->code);
            }

            $boxCode = Itf14::forBox($item->barcode, Itf14::indicator())
                ?? throw BoxLabelRuleViolation::invalidBarcode($item->code, (string) $item->barcode);

            $factor = BoxConversionFactor::query()->where('item_id', $item->id)->where('status', MasterStatus::Current)->first();

            if ($factor === null) {
                throw BoxLabelRuleViolation::noFactor($item->code);
            }

            $remaining = self::maxLabels((string) $order->planned_quantity, (string) $factor->units_per_box)
                - (int) BoxLabelBatch::query()->where('production_order_id', $order->id)->sum('labels');

            if ($labels < 1 || $labels > $remaining) {
                throw BoxLabelRuleViolation::invalidQuantity(max($remaining, 0));
            }

            $productionDate = $productionDate->startOfDay();
            $expirationDate = ($expirationDate ?? ($item->shelf_life_days === null ? null : $productionDate->addDays($item->shelf_life_days)))?->startOfDay();

            if ($expirationDate === null || ! $expirationDate->greaterThan($productionDate)) {
                throw BoxLabelRuleViolation::invalidExpiration();
            }

            $lotCode = BoxLot::code($order->ref, $productionDate);
            $lotExpiration = LotDates::expiration($order->id, $lotCode);

            // In SAP a lot has one expiration date: every label set of the lot must carry the same.
            if ($lotExpiration !== null && ! $lotExpiration->equalTo($expirationDate)) {
                throw BoxLabelRuleViolation::lotExpirationMismatch($lotCode, $lotExpiration);
            }

            $payload = new BoxLabelPayload(
                orderRef: $order->ref,
                itemCode: $item->code,
                itemDescription: $item->description,
                unitsPerBox: (new Quantity((string) $factor->units_per_box))->display(),
                uomName: $order->uom_name ?? $item->inventory_uom,
                lotCode: $lotCode,
                barcode: $boxCode,
                expirationDate: $expirationDate,
            );

            $batch = new BoxLabelBatch;
            $batch->forceFill([
                'production_order_id' => $order->id,
                'item_id' => $item->id,
                'box_conversion_factor_id' => $factor->id,
                'production_order_ref' => $order->ref,
                'item_code' => $item->code,
                'item_description' => $item->description,
                'lot_code' => $payload->lotCode,
                'production_date' => $productionDate,
                'expiration_date' => $expirationDate,
                'units_per_box' => $factor->units_per_box,
                'uom_name' => $payload->uomName,
                'barcode' => $payload->barcode,
                'labels' => $labels,
                'qr_payload' => $payload->encode(),
                'zpl' => BoxLabelZpl::render($payload, $productionDate, $labels),
                'generated_by' => $user->id,
                'generated_at' => now(),
            ])->save();

            $this->trace->record($ctx, TraceEventType::BoxLabelsGenerated, $order, context: [
                'box_label_batch_id' => $batch->id,
                'labels' => $labels,
                'lot_code' => $payload->lotCode,
                'expiration_date' => $expirationDate->toDateString(),
                'qr_payload' => $batch->qr_payload,
            ]);

            return $batch;
        });
    }

    /**
     * Boxes the planned quantity fills, the last one possibly partial.
     */
    public static function maxLabels(string $plannedQuantity, string $unitsPerBox): int
    {
        $planned = (new Quantity($plannedQuantity))->micro();
        $perBox = (new Quantity($unitsPerBox))->micro();

        return intdiv($planned + $perBox - 1, $perBox);
    }
}
