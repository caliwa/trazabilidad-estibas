<?php

namespace App\Domain\BoxLabels;

use Carbon\CarbonInterface;

/**
 * Lot of the boxes: the production order number followed by the Julian day
 * (day of the year, three digits) of the production date. Order 33128
 * produced on 20 October 2026 (day 293) is lot 33128293.
 */
final class BoxLot
{
    public static function code(string $orderRef, CarbonInterface $productionDate): string
    {
        return $orderRef.str_pad((string) $productionDate->dayOfYear, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The code of one box: lot and a 4-digit sequence within the lot, e.g.
     * 33128293-0007. Unique, because the lot already carries the order.
     */
    public static function boxCode(string $lotCode, int $sequence): string
    {
        return $lotCode.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
