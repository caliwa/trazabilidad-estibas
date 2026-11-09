<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parameters the administrator edits in the application (app_settings),
     * and the supervisor's go-ahead for an order that reached the receipt
     * limit (receipts_unlocked_*).
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->timestampTz('receipts_unlocked_at')->nullable();
            $table->foreignId('receipts_unlocked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('receipts_unlock_reason', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receipts_unlocked_by');
            $table->dropColumn(['receipts_unlocked_at', 'receipts_unlock_reason']);
        });
        Schema::dropIfExists('app_settings');
    }
};
