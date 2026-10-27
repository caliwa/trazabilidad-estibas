<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Holding a pallet now moves it to the non-conforming warehouse at once:
     * "held by quality" means "in 11C". Releasing it does not move it in SAP;
     * the blind count's transfer then goes 11C → 05.
     *
     * Pallet quality states: SIN_NOVEDAD, NC_SOLICITADO / NC_PENDIENTE
     * (transfer to 11C on its way), NO_CONFORME (held, in 11C), LIBERADA
     * (released from 11C, waits for the count). RETENIDA (held without a
     * transfer) goes away: those pallets return to SIN_NOVEDAD and their
     * holds are closed as ANULADA, with the reason.
     *
     * Quality holds: RETENIDA (transfer to 11C on its way), NO_CONFORME
     * (in 11C, still open), LIBERADA (released) and ANULADA (SAP refused
     * the transfer, or the migration above).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE quality_holds DROP CONSTRAINT quality_holds_status_check');
        DB::statement('ALTER TABLE quality_holds DROP CONSTRAINT quality_holds_resolution_check');
        DB::statement('DROP INDEX quality_holds_one_open');
        DB::statement('ALTER TABLE quality_holds ADD COLUMN released_by bigint NULL REFERENCES users(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE quality_holds ADD COLUMN released_at timestamptz NULL');
        DB::statement('ALTER TABLE quality_holds ADD COLUMN release_notes text NULL');

        // Held without a transfer, under the previous rule: back to no news, hold closed as cancelled.
        DB::statement(<<<'SQL'
            UPDATE quality_holds h SET status = 'ANULADA', resolved_by = h.held_by, resolved_at = now(), updated_at = now(),
                resolution_notes = 'Anulada al cambiar el proceso: retener ahora traslada la estiba a no conforme (11C). Si sigue no conforme, vuelve a retenerla.'
            FROM pallets p WHERE p.id = h.pallet_id AND p.quality_status = 'RETENIDA' AND h.status = 'RETENIDA'
        SQL);
        DB::statement('ALTER TABLE pallets DISABLE TRIGGER pallets_quality_guard');
        DB::statement("UPDATE pallets SET quality_status = 'SIN_NOVEDAD', updated_at = now() WHERE quality_status = 'RETENIDA'");
        DB::statement('ALTER TABLE pallets ENABLE TRIGGER pallets_quality_guard');

        DB::statement('ALTER TABLE pallets DROP CONSTRAINT pallets_quality_status_check');
        DB::statement("ALTER TABLE pallets ADD CONSTRAINT pallets_quality_status_check CHECK (quality_status IN ('SIN_NOVEDAD', 'NC_SOLICITADO', 'NC_PENDIENTE', 'NO_CONFORME', 'LIBERADA'))");

        DB::statement("ALTER TABLE quality_holds ADD CONSTRAINT quality_holds_status_check CHECK (status IN ('RETENIDA', 'NO_CONFORME', 'LIBERADA', 'ANULADA'))");
        DB::statement(<<<'SQL'
            ALTER TABLE quality_holds ADD CONSTRAINT quality_holds_resolution_check CHECK (
                (status = 'RETENIDA') = (resolved_by IS NULL AND resolved_at IS NULL)
                AND (status NOT IN ('NO_CONFORME', 'LIBERADA') OR inventory_transfer_id IS NOT NULL)
                AND ((status = 'LIBERADA') = (released_by IS NOT NULL AND released_at IS NOT NULL))
            )
        SQL);
        // One open hold per pallet: on its way to 11C, or in 11C.
        DB::statement("CREATE UNIQUE INDEX quality_holds_one_open ON quality_holds (pallet_id) WHERE status IN ('RETENIDA', 'NO_CONFORME')");

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

                ('quality', 'SIN_NOVEDAD', 'NC_SOLICITADO'),
                ('quality', 'NC_SOLICITADO', 'NO_CONFORME'),
                ('quality', 'NC_SOLICITADO', 'NC_PENDIENTE'),
                ('quality', 'NC_SOLICITADO', 'SIN_NOVEDAD'),
                ('quality', 'NC_PENDIENTE', 'NO_CONFORME'),
                ('quality', 'NC_PENDIENTE', 'NC_SOLICITADO'),
                ('quality', 'NC_PENDIENTE', 'SIN_NOVEDAD'),
                ('quality', 'NO_CONFORME', 'LIBERADA'),
                ('quality', 'LIBERADA', 'NO_CONFORME')
            );
            $$;

            -- A hold changes only while open: RETENIDA to any end, NO_CONFORME only to LIBERADA.
            CREATE OR REPLACE FUNCTION quality_holds_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('LIBERADA', 'ANULADA') OR (OLD.status = 'NO_CONFORME' AND NEW.status <> 'LIBERADA') THEN
                    RAISE EXCEPTION 'append_only: la retención % de calidad ya se cerró', OLD.id;
                END IF;

                IF OLD.status = 'NO_CONFORME' AND (NEW.resolved_by, NEW.resolved_at, NEW.resolution_notes, NEW.inventory_transfer_id)
                   IS DISTINCT FROM (OLD.resolved_by, OLD.resolved_at, OLD.resolution_notes, OLD.inventory_transfer_id) THEN
                    RAISE EXCEPTION 'append_only: el traslado a no conforme de la retención % ya quedó registrado', OLD.id;
                END IF;

                IF (NEW.pallet_id, NEW.held_by, NEW.held_at, NEW.reason) IS DISTINCT FROM (OLD.pallet_id, OLD.held_by, OLD.held_at, OLD.reason) THEN
                    RAISE EXCEPTION 'append_only: el origen de la retención no se modifica';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        // Forward only: the previous rule cannot hold a pallet that is in 11C and released.
    }
};
