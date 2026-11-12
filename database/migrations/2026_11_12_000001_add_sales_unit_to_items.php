<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Items.SalesUnit from SAP B1 (the sales unit of measure), shown on the
     * box label next to SalesQtyPerPackUnit and SalesPackagingUnit.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('sales_unit', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('sales_unit');
        });
    }
};
