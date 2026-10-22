<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The box QR carries only an opaque token, like the pallet QR: reading it
 * tells the server which box it is, and everything else (order, product,
 * lot, expiration) comes from the database. Labels printed before have no
 * token and are not readable: they have to be printed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Added without touching existing rows (box_labels is append-only).
        DB::statement('ALTER TABLE box_labels ADD COLUMN qr_token char(32) NULL');
        DB::statement('CREATE UNIQUE INDEX box_labels_qr_token_unique ON box_labels (qr_token) WHERE qr_token IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS box_labels_qr_token_unique');
        DB::statement('ALTER TABLE box_labels DROP COLUMN IF EXISTS qr_token');
    }
};
