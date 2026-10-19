<?php

namespace App\Domain\Pallets;

use Illuminate\Support\Facades\DB;

/**
 * Transaction-scoped advisory locks. Every operation that may open, fill
 * or close the pallet of an order + item + lot takes this lock first, so
 * scans and a manual close never interleave. Hash collisions only make two
 * unrelated combinations wait for each other; they never mix them.
 */
final class PalletLocks
{
    /** First key of the two-key advisory lock, reserved for pallet combinations. */
    private const NAMESPACE = 41_001;

    public static function combination(int $productionOrderId, int $itemId, string $lotCode): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::NAMESPACE, "{$productionOrderId}|{$itemId}|{$lotCode}"]);
    }
}
