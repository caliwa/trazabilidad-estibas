<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A pallet quality held and released goes 04 → 11C and later 11C → 05:
     * two live transfer lines. The "one active or confirmed transfer per
     * pallet" rule now applies per purpose, which each line carries.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE inventory_transfer_pallets ADD COLUMN purpose varchar(20) NOT NULL DEFAULT 'CEDI'");
        DB::statement('UPDATE inventory_transfer_pallets l SET purpose = t.purpose FROM inventory_transfers t WHERE t.id = l.inventory_transfer_id AND t.purpose <> l.purpose');
        DB::statement("ALTER TABLE inventory_transfer_pallets ADD CONSTRAINT inventory_transfer_pallets_purpose_check CHECK (purpose IN ('CEDI', 'NO_CONFORME'))");
        DB::statement('DROP INDEX inventory_transfer_pallets_one_hold');
        DB::statement("CREATE UNIQUE INDEX inventory_transfer_pallets_one_hold ON inventory_transfer_pallets (pallet_id, purpose) WHERE hold_status IN ('ACTIVA', 'CONFIRMADA')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX inventory_transfer_pallets_one_hold');
        DB::statement("CREATE UNIQUE INDEX inventory_transfer_pallets_one_hold ON inventory_transfer_pallets (pallet_id) WHERE hold_status IN ('ACTIVA', 'CONFIRMADA')");
        DB::statement('ALTER TABLE inventory_transfer_pallets DROP COLUMN purpose');
    }
};
