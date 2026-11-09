<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voiding a box already counted (damaged, misread). The read stays on
     * record; a box_voids row says it no longer counts, who voided it and
     * why, and what happened to its pallet. The pallet loses one box and,
     * if it was full and closed automatically, opens again.
     *
     * The pallet guards still refuse a lower count, a reopened pallet or a
     * changed close, except inside the transaction that recorded the void
     * for that pallet: the only door is this audited record.
     */
    public function up(): void
    {
        Schema::create('box_void_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX box_void_reasons_name_unique ON box_void_reasons (lower(name))');
        DB::statement("ALTER TABLE box_void_reasons ADD CONSTRAINT box_void_reasons_name_not_blank CHECK (btrim(name) <> '')");

        Schema::create('box_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_scan_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('pallet_id')->constrained()->restrictOnDelete();
            $table->string('box_code', 100);
            $table->foreignId('box_void_reason_id')->constrained()->restrictOnDelete();
            $table->string('note', 300)->nullable();
            $table->foreignId('voided_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('voided_at');
            // The pallet before and after: boxes, physical status and close type.
            $table->jsonb('pallet_before');
            $table->jsonb('pallet_after');
            // Transaction that recorded it: the pallet guards only let a box go
            // down (or the pallet reopen) inside it.
            $table->bigInteger('txid')->default(DB::raw('txid_current()'));

            $table->index(['pallet_id', 'txid']);
            $table->index('voided_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION box_voids_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.txid <> txid_current() THEN
                    RAISE EXCEPTION 'box_void_guard: la anulación se registra en su propia transacción';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pallet_scan_events e
                               WHERE e.id = NEW.pallet_scan_event_id AND e.processing_status = 'ACEPTADO' AND e.pallet_id = NEW.pallet_id) THEN
                    RAISE EXCEPTION 'box_void_guard: solo se anula una lectura aceptada, en la estiba donde se contó';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER box_voids_insert_guard BEFORE INSERT ON box_voids FOR EACH ROW EXECUTE FUNCTION box_voids_insert_guard();
            CREATE TRIGGER box_voids_append_only BEFORE UPDATE OR DELETE ON box_voids FOR EACH ROW EXECUTE FUNCTION forbid_update_delete();

            -- Same rules as before, plus the void door (see box_voids).
            CREATE OR REPLACE FUNCTION pallets_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                voiding boolean := OLD.palletized_at IS NULL
                    AND OLD.physical_status IN ('EN_ESTIBADO', 'ESTIBADO_FINALIZADO')
                    AND EXISTS (SELECT 1 FROM box_voids v WHERE v.pallet_id = OLD.id AND v.txid = txid_current());
            BEGIN
                IF (NEW.pallet_code, NEW.qr_token, NEW.production_order_id, NEW.item_id, NEW.production_order_ref,
                    NEW.item_code, NEW.lot_code, NEW.pallet_standard_id, NEW.standard_boxes_snapshot, NEW.started_at)
                   IS DISTINCT FROM
                   (OLD.pallet_code, OLD.qr_token, OLD.production_order_id, OLD.item_id, OLD.production_order_ref,
                    OLD.item_code, OLD.lot_code, OLD.pallet_standard_id, OLD.standard_boxes_snapshot, OLD.started_at) THEN
                    RAISE EXCEPTION 'pallet_guard: la identidad de la estiba % es inmutable', OLD.pallet_code;
                END IF;

                -- A void takes exactly one box off, even from a closed pallet.
                IF NEW.boxes_count < OLD.boxes_count AND NOT (voiding AND NEW.boxes_count = OLD.boxes_count - 1) THEN
                    RAISE EXCEPTION 'pallet_guard: la cantidad de cajas de % no puede disminuir', OLD.pallet_code;
                END IF;

                IF NEW.boxes_count > OLD.boxes_count AND OLD.physical_status <> 'EN_ESTIBADO' THEN
                    RAISE EXCEPTION 'pallet_guard: la estiba % está cerrada y no acepta cajas', OLD.pallet_code;
                END IF;

                IF (NEW.close_type, NEW.finished_at, NEW.closed_by) IS DISTINCT FROM (OLD.close_type, OLD.finished_at, OLD.closed_by)
                   AND OLD.physical_status <> 'EN_ESTIBADO' AND NOT voiding THEN
                    RAISE EXCEPTION 'pallet_guard: el cierre de % ya quedó registrado', OLD.pallet_code;
                END IF;

                IF (NEW.palletized_at, NEW.palletized_by) IS DISTINCT FROM (OLD.palletized_at, OLD.palletized_by)
                   AND OLD.palletized_at IS NOT NULL THEN
                    RAISE EXCEPTION 'pallet_guard: el paletizado de % ya quedó registrado', OLD.pallet_code;
                END IF;

                IF (NEW.labeled_at, NEW.labeled_by) IS DISTINCT FROM (OLD.labeled_at, OLD.labeled_by)
                   AND OLD.labeled_at IS NOT NULL THEN
                    RAISE EXCEPTION 'pallet_guard: el etiquetado de % ya quedó registrado', OLD.pallet_code;
                END IF;

                IF NEW.label_print_count < OLD.label_print_count THEN
                    RAISE EXCEPTION 'pallet_guard: el contador de impresiones de % no puede disminuir', OLD.pallet_code;
                END IF;

                IF (NOT state_transition_allowed('physical', OLD.physical_status, NEW.physical_status)
                    AND NOT (voiding AND OLD.physical_status = 'ESTIBADO_FINALIZADO' AND NEW.physical_status = 'EN_ESTIBADO'))
                   OR NOT state_transition_allowed('receipt', OLD.receipt_status, NEW.receipt_status)
                   OR NOT state_transition_allowed('audit', OLD.audit_status, NEW.audit_status)
                   OR NOT state_transition_allowed('transfer', OLD.transfer_status, NEW.transfer_status) THEN
                    RAISE EXCEPTION 'pallet_guard: transición no permitida en %: (%, %, %, %) -> (%, %, %, %)', OLD.pallet_code,
                        OLD.physical_status, OLD.receipt_status, OLD.audit_status, OLD.transfer_status,
                        NEW.physical_status, NEW.receipt_status, NEW.audit_status, NEW.transfer_status;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION pallets_close_reason_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.close_type IS NULL AND NEW.close_type = 'MANUAL' AND NEW.close_reason_id IS NULL THEN
                    RAISE EXCEPTION 'pallet_guard: el cierre manual de % necesita un motivo', OLD.pallet_code;
                END IF;
                IF OLD.close_type IS NOT NULL
                   AND (NEW.close_reason_id, NEW.close_note) IS DISTINCT FROM (OLD.close_reason_id, OLD.close_note)
                   AND NOT (NEW.close_type IS NULL AND NEW.close_reason_id IS NULL AND NEW.close_note IS NULL
                            AND EXISTS (SELECT 1 FROM box_voids v WHERE v.pallet_id = OLD.id AND v.txid = txid_current())) THEN
                    RAISE EXCEPTION 'pallet_guard: el motivo de cierre de % ya quedó registrado', OLD.pallet_code;
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        // Restores the guards without the void door.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pallets_close_reason_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.close_type IS NULL AND NEW.close_type = 'MANUAL' AND NEW.close_reason_id IS NULL THEN
                    RAISE EXCEPTION 'pallet_guard: el cierre manual de % necesita un motivo', OLD.pallet_code;
                END IF;
                IF OLD.close_type IS NOT NULL
                   AND (NEW.close_reason_id, NEW.close_note) IS DISTINCT FROM (OLD.close_reason_id, OLD.close_note) THEN
                    RAISE EXCEPTION 'pallet_guard: el motivo de cierre de % ya quedó registrado', OLD.pallet_code;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION pallets_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.pallet_code, NEW.qr_token, NEW.production_order_id, NEW.item_id, NEW.production_order_ref,
                    NEW.item_code, NEW.lot_code, NEW.pallet_standard_id, NEW.standard_boxes_snapshot, NEW.started_at)
                   IS DISTINCT FROM
                   (OLD.pallet_code, OLD.qr_token, OLD.production_order_id, OLD.item_id, OLD.production_order_ref,
                    OLD.item_code, OLD.lot_code, OLD.pallet_standard_id, OLD.standard_boxes_snapshot, OLD.started_at) THEN
                    RAISE EXCEPTION 'pallet_guard: la identidad de la estiba % es inmutable', OLD.pallet_code;
                END IF;
                IF NEW.boxes_count < OLD.boxes_count THEN
                    RAISE EXCEPTION 'pallet_guard: la cantidad de cajas de % no puede disminuir', OLD.pallet_code;
                END IF;
                IF NEW.boxes_count <> OLD.boxes_count AND OLD.physical_status <> 'EN_ESTIBADO' THEN
                    RAISE EXCEPTION 'pallet_guard: la estiba % está cerrada y no acepta cajas', OLD.pallet_code;
                END IF;
                IF (NEW.close_type, NEW.finished_at, NEW.closed_by) IS DISTINCT FROM (OLD.close_type, OLD.finished_at, OLD.closed_by)
                   AND OLD.physical_status <> 'EN_ESTIBADO' THEN
                    RAISE EXCEPTION 'pallet_guard: el cierre de % ya quedó registrado', OLD.pallet_code;
                END IF;
                IF (NEW.palletized_at, NEW.palletized_by) IS DISTINCT FROM (OLD.palletized_at, OLD.palletized_by)
                   AND OLD.palletized_at IS NOT NULL THEN
                    RAISE EXCEPTION 'pallet_guard: el paletizado de % ya quedó registrado', OLD.pallet_code;
                END IF;
                IF (NEW.labeled_at, NEW.labeled_by) IS DISTINCT FROM (OLD.labeled_at, OLD.labeled_by)
                   AND OLD.labeled_at IS NOT NULL THEN
                    RAISE EXCEPTION 'pallet_guard: el etiquetado de % ya quedó registrado', OLD.pallet_code;
                END IF;
                IF NEW.label_print_count < OLD.label_print_count THEN
                    RAISE EXCEPTION 'pallet_guard: el contador de impresiones de % no puede disminuir', OLD.pallet_code;
                END IF;
                IF NOT state_transition_allowed('physical', OLD.physical_status, NEW.physical_status)
                   OR NOT state_transition_allowed('receipt', OLD.receipt_status, NEW.receipt_status)
                   OR NOT state_transition_allowed('audit', OLD.audit_status, NEW.audit_status)
                   OR NOT state_transition_allowed('transfer', OLD.transfer_status, NEW.transfer_status) THEN
                    RAISE EXCEPTION 'pallet_guard: transición no permitida en %: (%, %, %, %) -> (%, %, %, %)', OLD.pallet_code,
                        OLD.physical_status, OLD.receipt_status, OLD.audit_status, OLD.transfer_status,
                        NEW.physical_status, NEW.receipt_status, NEW.audit_status, NEW.transfer_status;
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);

        Schema::dropIfExists('box_voids');
        Schema::dropIfExists('box_void_reasons');
        DB::unprepared('DROP FUNCTION IF EXISTS box_voids_insert_guard();');
    }
};
