<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One read per box token, whatever the device or the way it was read
     * (by hand, PDA, camera, fixed line reader or the device API).
     *
     * Every scan event that names a printed box (its code, or the token of
     * its QR, in the event id) is tied to that box label, filled in by the
     * database itself on insert, and a box label can be tied to one event
     * only. A second read of the same token is refused by the database even
     * if every check in the application were skipped.
     *
     * Existing events are tied in the same way. If a box already had more
     * than one read, only its first one is tied; the others stay as they
     * were (history is not rewritten) and `boxes:duplicates` lists them.
     */
    public function up(): void
    {
        Schema::table('pallet_scan_events', function (Blueprint $table) {
            $table->foreignId('box_label_id')->nullable()->constrained()->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            -- The box an event id names: a box code, or a 32-character box token (inside the QR text or alone).
            CREATE OR REPLACE FUNCTION box_label_for_event(event_id text) RETURNS bigint LANGUAGE sql STABLE AS $$
                SELECT coalesce(
                    (SELECT id FROM box_labels WHERE code = btrim(event_id)),
                    (SELECT id FROM box_labels WHERE qr_token = lower(substring(event_id FROM '(?i)(?:^|[^0-9a-f])([0-9a-f]{32})(?:[^0-9a-f]|$)')))
                );
            $$;

            CREATE OR REPLACE FUNCTION pallet_scan_events_box_link() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.box_label_id IS NULL THEN
                    NEW.box_label_id := box_label_for_event(NEW.external_event_id);
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER pallet_scan_events_box_link BEFORE INSERT ON pallet_scan_events FOR EACH ROW EXECUTE FUNCTION pallet_scan_events_box_link();

            -- Same rule as before; the box an event belongs to never changes either.
            CREATE OR REPLACE FUNCTION pallet_scan_events_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.processing_status = 'RECHAZADO' AND NEW.processing_status = 'ACEPTADO'
                   AND (NEW.device_id, NEW.external_event_id, NEW.production_order_ref, NEW.item_code, NEW.lot_code,
                        NEW.event_occurred_at, NEW.received_at, NEW.payload_hash, NEW.created_at, NEW.box_label_id)
                       IS NOT DISTINCT FROM
                       (OLD.device_id, OLD.external_event_id, OLD.production_order_ref, OLD.item_code, OLD.lot_code,
                        OLD.event_occurred_at, OLD.received_at, OLD.payload_hash, OLD.created_at, OLD.box_label_id) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'append_only: el evento % no se puede modificar', OLD.external_event_id;
            END;
            $$;
            SQL);

        // Tie the events already stored: the first read of each box (accepted first). The append-only
        // guard is paused only for this one statement, which fills the new column and nothing else.
        DB::statement('ALTER TABLE pallet_scan_events DISABLE TRIGGER pallet_scan_events_guard');
        DB::statement(<<<'SQL'
            UPDATE pallet_scan_events e SET box_label_id = r.box_label_id
            FROM (
                SELECT id, box_label_id, row_number() OVER (PARTITION BY box_label_id ORDER BY (processing_status = 'ACEPTADO') DESC, id) AS n
                FROM (SELECT id, processing_status, box_label_for_event(external_event_id) AS box_label_id FROM pallet_scan_events) x
                WHERE box_label_id IS NOT NULL
            ) r
            WHERE e.id = r.id AND r.n = 1
            SQL);
        DB::statement('ALTER TABLE pallet_scan_events ENABLE TRIGGER pallet_scan_events_guard');

        DB::statement('CREATE UNIQUE INDEX pallet_scan_events_one_per_box ON pallet_scan_events (box_label_id) WHERE box_label_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS pallet_scan_events_box_link ON pallet_scan_events;
            DROP FUNCTION IF EXISTS pallet_scan_events_box_link();
            CREATE OR REPLACE FUNCTION pallet_scan_events_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.processing_status = 'RECHAZADO' AND NEW.processing_status = 'ACEPTADO'
                   AND (NEW.device_id, NEW.external_event_id, NEW.production_order_ref, NEW.item_code, NEW.lot_code,
                        NEW.event_occurred_at, NEW.received_at, NEW.payload_hash, NEW.created_at)
                       IS NOT DISTINCT FROM
                       (OLD.device_id, OLD.external_event_id, OLD.production_order_ref, OLD.item_code, OLD.lot_code,
                        OLD.event_occurred_at, OLD.received_at, OLD.payload_hash, OLD.created_at) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'append_only: el evento % no se puede modificar', OLD.external_event_id;
            END;
            $$;
            SQL);
        DB::statement('DROP INDEX IF EXISTS pallet_scan_events_one_per_box');
        Schema::table('pallet_scan_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('box_label_id');
        });
        DB::unprepared('DROP FUNCTION IF EXISTS box_label_for_event(text);');
    }
};
