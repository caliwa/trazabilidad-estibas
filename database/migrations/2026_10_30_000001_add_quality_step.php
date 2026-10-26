<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quality step before the blind count. Quality may hold a labeled pallet,
     * release it back to the flow, or send it from 04 to the non-conforming
     * warehouse (11C) with a stock transfer.
     *
     * - pallets.quality_status: its own dimension, so the transfer to the
     *   CEDI (transfer_status) keeps meaning only 05. Transitions are checked
     *   by state_transition_allowed('quality', ...), like the others.
     * - quality_holds: each hold, who held and why, and how it ended.
     * - inventory_transfers.purpose: CEDI (04 → 05, from an approved count) or
     *   NO_CONFORME (04 → 11C, from quality, with no count behind it).
     */
    public function up(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->string('quality_status', 20)->default('SIN_NOVEDAD');
            $table->index('quality_status');
        });

        DB::statement("ALTER TABLE pallets ADD CONSTRAINT pallets_quality_status_check CHECK (quality_status IN ('SIN_NOVEDAD', 'RETENIDA', 'LIBERADA', 'NC_SOLICITADO', 'NC_PENDIENTE', 'NO_CONFORME'))");

        DB::unprepared(<<<'SQL'
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
                ('sap_document', 'PENDIENTE_CONCILIACION', 'ENVIANDO'),

                ('quality', 'SIN_NOVEDAD', 'RETENIDA'),
                ('quality', 'RETENIDA', 'LIBERADA'),
                ('quality', 'RETENIDA', 'NC_SOLICITADO'),
                ('quality', 'LIBERADA', 'RETENIDA'),
                ('quality', 'NC_SOLICITADO', 'NO_CONFORME'),
                ('quality', 'NC_SOLICITADO', 'RETENIDA'),
                ('quality', 'NC_SOLICITADO', 'NC_PENDIENTE'),
                ('quality', 'NC_PENDIENTE', 'NO_CONFORME'),
                ('quality', 'NC_PENDIENTE', 'RETENIDA'),
                ('quality', 'NC_PENDIENTE', 'NC_SOLICITADO')
            );
            $$;

            CREATE OR REPLACE FUNCTION pallets_quality_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.quality_status <> 'SIN_NOVEDAD' THEN
                    RAISE EXCEPTION 'pallet_guard: una estiba nueva empieza sin novedad de calidad';
                END IF;

                IF TG_OP = 'UPDATE' AND NOT state_transition_allowed('quality', OLD.quality_status, NEW.quality_status) THEN
                    RAISE EXCEPTION 'pallet_guard: transición de calidad no permitida en %: % -> %', OLD.pallet_code, OLD.quality_status, NEW.quality_status;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER pallets_quality_guard BEFORE INSERT OR UPDATE OF quality_status ON pallets FOR EACH ROW EXECUTE FUNCTION pallets_quality_guard();
        SQL);

        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->string('purpose', 20)->default('CEDI');
            $table->index('purpose');
        });
        DB::statement("ALTER TABLE inventory_transfers ADD CONSTRAINT inventory_transfers_purpose_check CHECK (purpose IN ('CEDI', 'NO_CONFORME'))");
        // A non-conforming transfer has no blind count behind it.
        DB::statement('ALTER TABLE inventory_transfer_pallets ALTER COLUMN pallet_audit_id DROP NOT NULL');

        Schema::create('quality_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('held_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('held_at');
            $table->text('reason');
            $table->string('status', 20);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('inventory_transfer_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'held_at']);
            $table->index('pallet_id');
        });

        DB::statement("ALTER TABLE quality_holds ADD CONSTRAINT quality_holds_status_check CHECK (status IN ('RETENIDA', 'LIBERADA', 'NO_CONFORME'))");
        DB::statement("ALTER TABLE quality_holds ADD CONSTRAINT quality_holds_reason_check CHECK (btrim(reason) <> '')");
        DB::statement("ALTER TABLE quality_holds ADD CONSTRAINT quality_holds_resolution_check CHECK ((status = 'RETENIDA') = (resolved_by IS NULL AND resolved_at IS NULL) AND (status <> 'NO_CONFORME' OR inventory_transfer_id IS NOT NULL))");
        // One open hold per pallet.
        DB::statement("CREATE UNIQUE INDEX quality_holds_one_open ON quality_holds (pallet_id) WHERE status = 'RETENIDA'");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION quality_holds_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status <> 'RETENIDA' THEN
                    RAISE EXCEPTION 'append_only: la retención % de calidad ya se cerró', OLD.id;
                END IF;

                IF (NEW.pallet_id, NEW.held_by, NEW.held_at, NEW.reason) IS DISTINCT FROM (OLD.pallet_id, OLD.held_by, OLD.held_at, OLD.reason) THEN
                    RAISE EXCEPTION 'append_only: el origen de la retención no se modifica';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER quality_holds_guard BEFORE UPDATE ON quality_holds FOR EACH ROW EXECUTE FUNCTION quality_holds_guard();
            CREATE TRIGGER quality_holds_no_delete BEFORE DELETE ON quality_holds FOR EACH ROW EXECUTE FUNCTION forbid_delete();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_holds');
        DB::unprepared('DROP FUNCTION IF EXISTS quality_holds_guard()');
        DB::statement('ALTER TABLE inventory_transfer_pallets ALTER COLUMN pallet_audit_id SET NOT NULL');
        Schema::table('inventory_transfers', fn (Blueprint $table) => $table->dropColumn('purpose'));
        DB::unprepared('DROP TRIGGER IF EXISTS pallets_quality_guard ON pallets; DROP FUNCTION IF EXISTS pallets_quality_guard()');
        Schema::table('pallets', fn (Blueprint $table) => $table->dropColumn('quality_status'));
    }
};
