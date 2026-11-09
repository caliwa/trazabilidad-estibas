<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Boxes are identified only by the token of their QR. The printed box
     * code ("Caja 35353313-0074") is for people and no longer identifies a
     * read. Reads already tied to their box keep their tie (box_label_id).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION box_label_for_event(event_id text) RETURNS bigint LANGUAGE sql STABLE AS $$
                SELECT id FROM box_labels
                WHERE qr_token = lower(substring(event_id FROM '(?i)(?:^|[^0-9a-f])([0-9a-f]{32})(?:[^0-9a-f]|$)'));
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION box_label_for_event(event_id text) RETURNS bigint LANGUAGE sql STABLE AS $$
                SELECT coalesce(
                    (SELECT id FROM box_labels WHERE code = btrim(event_id)),
                    (SELECT id FROM box_labels WHERE qr_token = lower(substring(event_id FROM '(?i)(?:^|[^0-9a-f])([0-9a-f]{32})(?:[^0-9a-f]|$)')))
                );
            $$;
            SQL);
    }
};
