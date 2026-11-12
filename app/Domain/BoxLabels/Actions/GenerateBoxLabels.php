<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\BoxLabelZpl;
use App\Domain\BoxLabels\BoxLabelZplOverride;
use App\Domain\BoxLabels\BoxLot;
use App\Domain\BoxLabels\BoxQr;
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
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\BoxLabelPrint;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Generates a batch of identical box labels for a released production
 * order. The operator chooses how many, with no limit per order: labels
 * get misprinted and labelled boxes get damaged. maxLabels() is only the
 * suggestion (the boxes the planned quantity fills).
 *
 * The lot is OF + Julian day of the production date. A supervisor
 * (box_labels.change_lot) may use another one while the default lot has no
 * Zebra prints; a lot never belongs to two orders.
 *
 * The batch keeps the exact QR text and ZPL, so what was printed can
 * always be shown again.
 */
class GenerateBoxLabels
{
    public function __construct(private readonly TraceLogger $trace) {}

    public const LOT_PATTERN = '/^[A-Za-z0-9._\/-]{1,40}$/';

    public const MAX_LABELS = 3000;

    public function handle(
        User $user,
        int $productionOrderId,
        int $labels,
        CarbonImmutable $productionDate,
        ?CarbonImmutable $expirationDate,
        TraceContext $ctx,
        ?string $lotCode = null,
        bool $restartSequence = false,
        ?string $customItemCode = null,
        ?string $customItemDescription = null,
        ?string $extraText = null,
        ?string $customUnitsText = null,
    ): BoxLabelBatch {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        if ($productionDate->startOfDay()->greaterThan(today())) {
            throw BoxLabelRuleViolation::futureProductionDate();
        }

        if ($labels < 1) {
            throw BoxLabelRuleViolation::invalidQuantity();
        }

        if ($labels > self::MAX_LABELS) {
            throw BoxLabelRuleViolation::quantityExceedsMaximum(self::MAX_LABELS);
        }

        $lotCode = $lotCode === null ? null : trim($lotCode);
        $customItemCode = $customItemCode === null ? null : trim($customItemCode);
        $customItemDescription = $customItemDescription === null ? null : trim($customItemDescription);
        $extraText = $extraText === null ? null : trim($extraText);
        $customUnitsText = $customUnitsText === null ? null : trim($customUnitsText);

        return DB::transaction(function () use ($user, $productionOrderId, $labels, $productionDate, $expirationDate, $ctx, $lotCode, $restartSequence, $customItemCode, $customItemDescription, $extraText, $customUnitsText): BoxLabelBatch {
            // The lock serialises batches of one order: box codes continue the lot without gaps or repeats.
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

            $suggested = self::maxLabels((string) $order->planned_quantity, (string) $factor->units_per_box);
            $generated = (int) BoxLabelBatch::query()->where('production_order_id', $order->id)->sum('labels');

            $productionDate = $productionDate->startOfDay();
            $expirationDate = ($expirationDate ?? ($item->shelf_life_days === null ? null : $productionDate->addDays($item->shelf_life_days)))?->startOfDay();

            if ($expirationDate === null || ! $expirationDate->greaterThan($productionDate)) {
                throw BoxLabelRuleViolation::invalidExpiration();
            }

            $defaultLot = BoxLot::code($order->ref, $productionDate);

            if ($lotCode !== null && $lotCode !== '' && $lotCode !== $defaultLot) {
                if (! Gate::forUser($user)->allows(Permission::BoxLabelsChangeLot->value)) {
                    throw BoxLabelRuleViolation::lotChangeNotAllowed();
                }

                if (preg_match(self::LOT_PATTERN, $lotCode) !== 1) {
                    throw BoxLabelRuleViolation::invalidLot();
                }

                if (BoxLabelPrint::lotPrinted($order->id, $defaultLot)) {
                    throw BoxLabelRuleViolation::lotAlreadyPrinted($defaultLot);
                }
            } else {
                $lotCode = $defaultLot;
            }

            // Box codes carry the lot: a lot of another order would mix both orders' boxes.
            $owner = BoxLabelBatch::query()->where('lot_code', $lotCode)->where('production_order_id', '!=', $order->id)->value('production_order_ref');
            if ($owner !== null) {
                throw BoxLabelRuleViolation::lotOfAnotherOrder($lotCode, (string) $owner);
            }

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

            // Control de corrida y consecutivo de cajas (trazabilidad de faltantes y reinicio)
            $currentRun = (int) ($order->current_box_run ?? 1);
            if ($currentRun < 1) {
                $currentRun = 1;
            }

            if ($restartSequence) {
                $maxLotRun = (int) BoxLabel::query()->where('lot_code', $lotCode)->max('run_number');
                $hasExisting = $maxLotRun > 0
                    || BoxLabel::query()->where('production_order_id', $order->id)->where('lot_code', $lotCode)->exists();
                $currentRun = $hasExisting ? max($currentRun + 1, $maxLotRun + 1) : 1;
                $first = 1;
                $isReset = $hasExisting;
            } else {
                $maxSeq = (int) BoxLabel::query()
                    ->where('production_order_id', $order->id)
                    ->where('lot_code', $lotCode)
                    ->where('run_number', $currentRun)
                    ->max('sequence');
                // Si la corrida no tiene registros aun pero hay de corridas anteriores y no es reset
                if ($maxSeq === 0) {
                    $maxSeq = (int) BoxLabel::query()->where('production_order_id', $order->id)->where('lot_code', $lotCode)->max('sequence');
                }
                $first = $maxSeq + 1;
                $isReset = false;
            }

            $lastSeq = $first + $labels - 1;
            $boxes = [];
            $seenTokens = [];
            for ($n = $first; $n <= $lastSeq; $n++) {
                do {
                    $token = BoxQr::newToken();
                } while (isset($seenTokens[$token]));
                $seenTokens[$token] = true;
                $boxes[] = ['code' => BoxLot::boxCode($lotCode, $n), 'token' => $token];
            }

            // Garantizar 100% unicidad de qr_token contra etiquetas ya existentes en la base de datos
            foreach (array_chunk(array_keys($seenTokens), 500) as $tokenChunk) {
                $collisions = BoxLabel::query()->whereIn('qr_token', $tokenChunk)->pluck('qr_token')->all();
                if ($collisions !== []) {
                    $collisionSet = array_flip($collisions);
                    foreach ($boxes as $idx => $boxEntry) {
                        if (isset($collisionSet[$boxEntry['token']])) {
                            do {
                                $candidate = BoxQr::newToken();
                            } while (isset($seenTokens[$candidate]) || BoxLabel::query()->where('qr_token', $candidate)->exists());
                            $seenTokens[$candidate] = true;
                            $boxes[$idx]['token'] = $candidate;
                        }
                    }
                }
            }

            $unitsDisplay = (new Quantity((string) $factor->units_per_box))->display();
            $defaultUnitsLine = 'CAJA : '.$unitsDisplay.' UNIDADES';
            $effectiveItemCode = ($customItemCode !== null && $customItemCode !== '') ? $customItemCode : $item->code;
            $effectiveItemDescription = ($customItemDescription !== null && $customItemDescription !== '') ? $customItemDescription : $item->description;
            $effectiveUnitsText = ($customUnitsText !== null && $customUnitsText !== '' && $customUnitsText !== $defaultUnitsLine) ? $customUnitsText : null;
            $effectiveExtraText = ($extraText !== null && $extraText !== '') ? $extraText : null;

            $zplPayload = new BoxLabelPayload(
                orderRef: $order->ref,
                itemCode: $effectiveItemCode,
                itemDescription: $effectiveItemDescription,
                unitsPerBox: $unitsDisplay,
                uomName: $order->uom_name ?? $item->inventory_uom,
                lotCode: $lotCode,
                barcode: $boxCode,
                expirationDate: $expirationDate,
                extraText: $effectiveExtraText,
                unitsText: $effectiveUnitsText,
            );
            $renderedZpl = BoxLabelZpl::renderBoxes($zplPayload, $productionDate, $boxes);

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
                'first_sequence' => $first,
                'last_sequence' => $lastSeq,
                'run_number' => $currentRun,
                'is_reset' => $isReset,
                // The printed text every label of the batch shares (each adds its box code). The QR
                // itself carries only each box's token.
                'qr_payload' => $payload->encode(),
                'zpl' => $renderedZpl,
                'generated_by' => $user->id,
                'generated_at' => now(),
            ])->save();

            if ($effectiveItemCode !== $item->code || $effectiveItemDescription !== $item->description || $effectiveExtraText !== null || $effectiveUnitsText !== null) {
                BoxLabelZplOverride::save(
                    $batch->id,
                    $effectiveItemCode,
                    $effectiveItemDescription,
                    $effectiveUnitsText ?? $defaultUnitsLine,
                    $effectiveExtraText ?? '',
                    $renderedZpl,
                );
            }

            $now = now();
            $boxRows = [];
            foreach ($boxes as $i => $box) {
                $boxRows[] = [
                    'box_label_batch_id' => $batch->id,
                    'production_order_id' => $order->id,
                    'lot_code' => $lotCode,
                    'sequence' => $first + $i,
                    'run_number' => $currentRun,
                    'code' => $box['code'],
                    'qr_token' => $box['token'],
                    'created_at' => $now,
                ];
            }

            foreach (array_chunk($boxRows, 500) as $chunk) {
                BoxLabel::query()->insert($chunk);
            }

            $order->forceFill([
                'last_box_sequence' => $lastSeq,
                'current_box_run' => $currentRun,
                'last_box_labels_at' => now(),
            ])->save();

            $this->trace->record($ctx, TraceEventType::BoxLabelsGenerated, $order, context: [
                'box_codes' => [$boxes[0]['code'], $boxes[count($boxes) - 1]['code']],
                'box_label_batch_id' => $batch->id,
                'labels' => $labels,
                'first_sequence' => $first,
                'last_sequence' => $lastSeq,
                'run_number' => $currentRun,
                'is_reset' => $isReset,
                'lot_code' => $payload->lotCode,
                ...($lotCode === $defaultLot ? [] : ['default_lot_code' => $defaultLot]),
                // Over the suggestion is allowed (misprints, damaged boxes) and stays on record.
                'suggested_labels' => $suggested,
                'labels_before' => $generated,
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
