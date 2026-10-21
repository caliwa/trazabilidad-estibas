<?php

namespace App\Domain\BoxLabels;

use App\Models\BoxLabelBatch;
use Carbon\CarbonImmutable;

/**
 * Dates of a lot as printed on its box labels. The box QR gives the lot to
 * the pallet; the box label generation of that order and lot gives its
 * expiration. One lot has one expiration (GenerateBoxLabels enforces it).
 */
final class LotDates
{
    public static function expiration(int $productionOrderId, string $lotCode): ?CarbonImmutable
    {
        $date = BoxLabelBatch::query()
            ->where('production_order_id', $productionOrderId)
            ->where('lot_code', $lotCode)
            ->orderBy('id')
            ->value('expiration_date');

        return $date === null ? null : CarbonImmutable::parse((string) $date)->startOfDay();
    }
}
