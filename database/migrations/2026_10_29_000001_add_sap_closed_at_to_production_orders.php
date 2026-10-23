<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the application saw the order closed in SAP (on a refresh of
     * the order board). The board lists, under "Cerrada en SAP", the closed
     * orders that reached "ready to close" here.
     */
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->timestampTz('sap_closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('sap_closed_at');
        });
    }
};
