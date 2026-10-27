<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Items.U_TEC_CajMstXPall: real boxes per pallet of each product, as SAP
     * has it. Every sync also makes it the product's current pallet standard.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('boxes_per_pallet')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('boxes_per_pallet');
        });
    }
};
