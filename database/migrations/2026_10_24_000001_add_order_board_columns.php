<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production order board.
 *
 *  - sap_completed_quantity: the "completed" quantity SAP reports for the
 *    order on the last refresh, to compare with what this app received.
 *  - ready_to_close_notified_at: when the "ready to close in SAP" alert was
 *    sent, so it goes out once per order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('sap_completed_quantity', 18, 6)->nullable();
            $table->timestampTz('ready_to_close_notified_at')->nullable();
        });

        DB::statement('ALTER TABLE production_orders ADD CONSTRAINT production_orders_completed_quantity_check CHECK (sap_completed_quantity IS NULL OR sap_completed_quantity >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE production_orders DROP CONSTRAINT IF EXISTS production_orders_completed_quantity_check');

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['sap_completed_quantity', 'ready_to_close_notified_at']);
        });
    }
};
