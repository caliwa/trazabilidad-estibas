<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pallets, their scan events and label prints.
     *
     * The application validates every rule first; the constraints and
     * triggers here are the last line of defense, so a bug or a manual
     * UPDATE cannot leave a pallet in an impossible state.
     */
    public function up(): void
    {
        // Gap-free pallet numbering: one row per prefix, locked FOR UPDATE inside the creating transaction.
        Schema::create('pallet_code_counters', function (Blueprint $table) {
            $table->string('prefix', 40)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('pallets', function (Blueprint $table) {
            $table->id();
            $table->string('pallet_code', 30)->unique();
            // Opaque identifier printed in the QR. Not the id, not the code, carries no data.
            $table->char('qr_token', 32)->unique();

            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            // Snapshots of the identity as it was on the floor; masters may be resynced later.
            $table->string('production_order_ref', 50);
            $table->string('item_code', 50);
            $table->string('lot_code', 50);

            $table->foreignId('pallet_standard_id')->constrained()->restrictOnDelete();
            $table->integer('standard_boxes_snapshot');
            $table->integer('boxes_count')->default(0);

            $table->string('physical_status', 30)->default('EN_ESTIBADO');
            $table->string('close_type', 20)->nullable();
            $table->string('receipt_status', 30)->default('SIN_RECIBO');
            $table->string('audit_status', 30)->default('PENDIENTE');
            $table->string('transfer_status', 30)->default('NO_ELEGIBLE');

            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('palletized_at')->nullable();
            $table->foreignId('palletized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('labeled_at')->nullable();
            $table->foreignId('labeled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('label_print_count')->default(0);
            $table->timestampsTz();

            $table->index('production_order_ref');
            $table->index('item_code');
            $table->index('lot_code');
            $table->index(['physical_status', 'started_at']);
            $table->index('receipt_status');
            $table->index('audit_status');
            $table->index('transfer_status');
            $table->index('started_at');
            $table->index('finished_at');
        });

        $checks = [
            'standard_positive' => 'standard_boxes_snapshot > 0',
            'boxes_range' => 'boxes_count >= 0 AND boxes_count <= standard_boxes_snapshot',
            'identity_not_blank' => "btrim(pallet_code) <> '' AND btrim(production_order_ref) <> '' AND btrim(item_code) <> '' AND btrim(lot_code) <> ''",
            'physical_status' => "physical_status IN ('EN_ESTIBADO', 'ESTIBADO_FINALIZADO', 'PALETIZADO', 'ETIQUETADO')",
            'close_type' => "close_type IS NULL OR close_type IN ('AUTOMATICO', 'MANUAL')",
            'receipt_status' => "receipt_status IN ('SIN_RECIBO', 'SOLICITADO', 'PENDIENTE_CONCILIACION', 'CONFIRMADO_04', 'FALLIDO')",
            'audit_status' => "audit_status IN ('PENDIENTE', 'EN_CONTEO', 'APROBADA', 'DISCREPANCIA')",
            'transfer_status' => "transfer_status IN ('NO_ELEGIBLE', 'LISTO', 'SOLICITADO', 'PENDIENTE_CONCILIACION', 'CONFIRMADO_05', 'FALLIDO')",
            // Closed exactly when it left EN_ESTIBADO.
            'closed_when_finished' => "(physical_status = 'EN_ESTIBADO') = (close_type IS NULL AND finished_at IS NULL)",
            'automatic_close_is_full' => "close_type IS DISTINCT FROM 'AUTOMATICO' OR boxes_count = standard_boxes_snapshot",
            'manual_close_is_partial' => "close_type IS DISTINCT FROM 'MANUAL' OR (boxes_count BETWEEN 1 AND standard_boxes_snapshot - 1 AND closed_by IS NOT NULL)",
            'palletized_fields' => "(physical_status IN ('PALETIZADO', 'ETIQUETADO')) = (palletized_at IS NOT NULL AND palletized_by IS NOT NULL)",
            'labeled_fields' => "(physical_status = 'ETIQUETADO') = (labeled_at IS NOT NULL AND labeled_by IS NOT NULL)",
            'dates_in_order' => 'finished_at >= started_at AND palletized_at >= finished_at AND labeled_at >= palletized_at',
            // The dimensions are independent columns, but each one needs the previous process done.
            'receipt_needs_label' => "receipt_status = 'SIN_RECIBO' OR physical_status = 'ETIQUETADO'",
            'audit_needs_receipt' => "audit_status = 'PENDIENTE' OR receipt_status = 'CONFIRMADO_04'",
            'transfer_needs_audit' => "transfer_status = 'NO_ELEGIBLE' OR audit_status = 'APROBADA'",
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE pallets ADD CONSTRAINT pallets_{$name}_check CHECK ({$expression})");
        }

        // At most one open pallet per order + item + lot, whatever the application does.
        DB::statement("CREATE UNIQUE INDEX pallets_one_active ON pallets (production_order_id, item_id, lot_code) WHERE physical_status = 'EN_ESTIBADO'");

        Schema::create('pallet_scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            // Uniqueness scope (per device or global) is DP-01; per device until decided.
            $table->string('external_event_id', 100);
            $table->foreignId('pallet_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('production_order_ref', 50);
            $table->string('item_code', 50);
            $table->string('lot_code', 50);
            $table->timestampTz('event_occurred_at');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('processing_status', 20);
            $table->string('rejection_reason', 60)->nullable();
            $table->char('payload_hash', 64);
            // Occurred before the pallet it was counted in started (arrived out of order).
            $table->boolean('is_late')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['device_id', 'external_event_id']);
            $table->index(['pallet_id', 'event_occurred_at']);
            $table->index(['processing_status', 'received_at']);
        });

        DB::statement("ALTER TABLE pallet_scan_events ADD CONSTRAINT pallet_scan_events_status_check CHECK (processing_status IN ('ACEPTADO', 'RECHAZADO'))");
        DB::statement("ALTER TABLE pallet_scan_events ADD CONSTRAINT pallet_scan_events_outcome_check CHECK ((processing_status = 'ACEPTADO' AND pallet_id IS NOT NULL AND processed_at IS NOT NULL) OR (processing_status = 'RECHAZADO' AND pallet_id IS NULL AND rejection_reason IS NOT NULL))");

        Schema::create('pallet_label_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('print_number');
            $table->string('kind', 20);
            $table->string('reason', 300)->nullable();
            $table->foreignId('printed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('printed_at');

            $table->unique(['pallet_id', 'print_number']);
        });

        DB::statement("ALTER TABLE pallet_label_prints ADD CONSTRAINT pallet_label_prints_kind_check CHECK ((kind = 'ORIGINAL' AND print_number = 1) OR (kind = 'REIMPRESION' AND print_number > 1 AND btrim(coalesce(reason, '')) <> ''))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_update_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'append_only: % no admite %', TG_TABLE_NAME, TG_OP;
            END;
            $$;

            CREATE OR REPLACE FUNCTION forbid_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'no_delete: los registros de % no se borran', TG_TABLE_NAME;
            END;
            $$;

            -- Allowed state changes per dimension. Must match the PHP enums
            -- (App\Domain\...\Enums); StateTransitionsMatchDatabaseTest checks it.
            CREATE OR REPLACE FUNCTION state_transition_allowed(dimension text, from_state text, to_state text)
            RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT from_state = to_state OR (dimension, from_state, to_state) IN (
                    ('physical', 'EN_ESTIBADO', 'ESTIBADO_FINALIZADO'),
                    ('physical', 'ESTIBADO_FINALIZADO', 'PALETIZADO'),
                    ('physical', 'PALETIZADO', 'ETIQUETADO'),

                    ('receipt', 'SIN_RECIBO', 'SOLICITADO'),
                    ('receipt', 'SOLICITADO', 'CONFIRMADO_04'),
                    ('receipt', 'SOLICITADO', 'FALLIDO'),
                    ('receipt', 'SOLICITADO', 'PENDIENTE_CONCILIACION'),
                    ('receipt', 'PENDIENTE_CONCILIACION', 'CONFIRMADO_04'),
                    ('receipt', 'PENDIENTE_CONCILIACION', 'FALLIDO'),
                    ('receipt', 'PENDIENTE_CONCILIACION', 'SOLICITADO'),
                    ('receipt', 'FALLIDO', 'SOLICITADO'),

                    ('audit', 'PENDIENTE', 'EN_CONTEO'),
                    ('audit', 'EN_CONTEO', 'APROBADA'),
                    ('audit', 'EN_CONTEO', 'DISCREPANCIA'),
                    ('audit', 'EN_CONTEO', 'PENDIENTE'),
                    ('audit', 'DISCREPANCIA', 'EN_CONTEO'),

                    ('transfer', 'NO_ELEGIBLE', 'LISTO'),
                    ('transfer', 'LISTO', 'SOLICITADO'),
                    ('transfer', 'SOLICITADO', 'CONFIRMADO_05'),
                    ('transfer', 'SOLICITADO', 'FALLIDO'),
                    ('transfer', 'SOLICITADO', 'PENDIENTE_CONCILIACION'),
                    ('transfer', 'PENDIENTE_CONCILIACION', 'CONFIRMADO_05'),
                    ('transfer', 'PENDIENTE_CONCILIACION', 'FALLIDO'),
                    ('transfer', 'PENDIENTE_CONCILIACION', 'SOLICITADO'),
                    ('transfer', 'FALLIDO', 'SOLICITADO'),

                    ('sap_document', 'SOLICITADO', 'ENVIANDO'),
                    ('sap_document', 'SOLICITADO', 'FALLIDO'),
                    ('sap_document', 'ENVIANDO', 'CONFIRMADO'),
                    ('sap_document', 'ENVIANDO', 'FALLIDO'),
                    ('sap_document', 'ENVIANDO', 'PENDIENTE_CONCILIACION'),
                    ('sap_document', 'PENDIENTE_CONCILIACION', 'CONFIRMADO'),
                    ('sap_document', 'PENDIENTE_CONCILIACION', 'FALLIDO'),
                    ('sap_document', 'PENDIENTE_CONCILIACION', 'ENVIANDO')
                );
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

            CREATE OR REPLACE FUNCTION pallets_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.physical_status <> 'EN_ESTIBADO' OR NEW.receipt_status <> 'SIN_RECIBO'
                   OR NEW.audit_status <> 'PENDIENTE' OR NEW.transfer_status <> 'NO_ELEGIBLE'
                   OR NEW.label_print_count <> 0 THEN
                    RAISE EXCEPTION 'pallet_guard: una estiba nueva empieza en EN_ESTIBADO sin procesos posteriores';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER pallets_guard BEFORE UPDATE ON pallets FOR EACH ROW EXECUTE FUNCTION pallets_guard();
            CREATE TRIGGER pallets_insert_guard BEFORE INSERT ON pallets FOR EACH ROW EXECUTE FUNCTION pallets_insert_guard();
            CREATE TRIGGER pallets_no_delete BEFORE DELETE ON pallets FOR EACH ROW EXECUTE FUNCTION forbid_delete();

            -- A rejected event may be accepted later (reprocessing after fixing a master), exactly once.
            -- Nothing else in an event ever changes.
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

            CREATE TRIGGER pallet_scan_events_guard BEFORE UPDATE ON pallet_scan_events FOR EACH ROW EXECUTE FUNCTION pallet_scan_events_guard();
            CREATE TRIGGER pallet_scan_events_no_delete BEFORE DELETE ON pallet_scan_events FOR EACH ROW EXECUTE FUNCTION forbid_delete();

            CREATE TRIGGER pallet_label_prints_append_only BEFORE UPDATE OR DELETE ON pallet_label_prints FOR EACH ROW EXECUTE FUNCTION forbid_update_delete();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pallet_label_prints');
        Schema::dropIfExists('pallet_scan_events');
        Schema::dropIfExists('pallets');
        Schema::dropIfExists('pallet_code_counters');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS pallet_scan_events_guard();
            DROP FUNCTION IF EXISTS pallets_insert_guard();
            DROP FUNCTION IF EXISTS pallets_guard();
            DROP FUNCTION IF EXISTS state_transition_allowed(text, text, text);
            DROP FUNCTION IF EXISTS forbid_delete();
            DROP FUNCTION IF EXISTS forbid_update_delete();
        SQL);
    }
};
