<?php

namespace App\Domain\Pallets;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pallet numbers without gaps or reuse. The upsert locks the prefix row
 * until the surrounding transaction ends: concurrent creators wait in
 * line, and a rollback gives the number back because the increment rolls
 * back with it.
 */
class PalletCodeGenerator
{
    public function next(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El consecutivo de estiba se genera dentro de la transacción que crea la estiba.');
        }

        $prefix = config()->string('traceability.pallet_code.prefix');

        $value = (int) DB::scalar(
            'INSERT INTO pallet_code_counters (prefix, last_value, updated_at) VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE SET last_value = pallet_code_counters.last_value + 1, updated_at = now()
             RETURNING last_value',
            [$prefix],
        );

        return $prefix.'-'.str_pad((string) $value, config()->integer('traceability.pallet_code.digits'), '0', STR_PAD_LEFT);
    }
}
