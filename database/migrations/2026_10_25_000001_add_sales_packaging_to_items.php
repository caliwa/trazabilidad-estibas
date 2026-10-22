<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales packaging of the product, as SAP's item master has it
 * (Items.SalesQtyPerPackUnit and Items.SalesPackagingUnit), stored when a
 * production order is brought from SAP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('sales_qty_per_pack_unit', 18, 6)->nullable();
            $table->string('sales_packaging_unit', 30)->nullable();
        });

        DB::statement('ALTER TABLE items ADD CONSTRAINT items_sales_qty_per_pack_unit_check CHECK (sales_qty_per_pack_unit IS NULL OR sales_qty_per_pack_unit >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_sales_qty_per_pack_unit_check');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['sales_qty_per_pack_unit', 'sales_packaging_unit']);
        });
    }
};
