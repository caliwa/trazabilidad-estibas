<?php

namespace App\Domain\Integration;

use App\Domain\Integration\Enums\MasterStatus;
use App\Models\BoxConversionFactor;
use Carbon\CarbonInterface;

/**
 * Current boxes → inventory units factor of an item (DP-02). Never assumed:
 * without a valid current factor there is no SAP quantity.
 */
class ConversionFactors
{
    public function current(int $itemId, CarbonInterface $at): ?BoxConversionFactor
    {
        $factor = BoxConversionFactor::query()->where('item_id', $itemId)->where('status', MasterStatus::Current)->first();

        if ($factor === null || ($factor->valid_from !== null && $factor->valid_from->greaterThan($at)) || ($factor->valid_to !== null && $factor->valid_to->lessThanOrEqualTo($at))) {
            return null;
        }

        return $factor;
    }
}
