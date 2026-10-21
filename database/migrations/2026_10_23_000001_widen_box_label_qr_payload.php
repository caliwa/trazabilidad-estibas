<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The box QR now carries the readable lines of the label, product
 * description included, which can pass 300 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('box_label_batches', function (Blueprint $table) {
            $table->text('qr_payload')->change();
        });
    }

    public function down(): void
    {
        Schema::table('box_label_batches', function (Blueprint $table) {
            $table->string('qr_payload', 300)->change();
        });
    }
};
