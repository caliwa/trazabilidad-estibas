<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Box reads by hand and by fixed readers share one device so a box is
     * counted once whoever reads it. This keeps which fixed reader read it.
     */
    public function up(): void
    {
        Schema::table('pallet_scan_events', function (Blueprint $table) {
            $table->foreignId('read_by_device_id')->nullable()->constrained('devices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pallet_scan_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('read_by_device_id');
        });
    }
};
