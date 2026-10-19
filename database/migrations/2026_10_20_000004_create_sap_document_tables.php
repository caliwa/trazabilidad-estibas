<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local requests for SAP documents (production receipts into 04 and
     * inventory transfers 04 → 05), the pallets each one holds, and every
     * call made to SAP for them.
     *
     * A request is persisted with its request_uuid and payload hash before
     * anything is sent. CONFIRMADO is only written after the document was
     * verified in SAP, so it always carries DocEntry and DocNum.
     */
    public function up(): void
    {
        Schema::create('production_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $this->documentColumns($table);
        });

        Schema::create('production_receipt_pallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('pallet_id')->constrained()->restrictOnDelete();
            $this->lineColumns($table);

            $table->unique(['production_receipt_id', 'pallet_id']);
        });

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->string('mode', 20);
            // Real warehouse codes come from configuration after validating them in SAP (DP-09).
            $table->string('from_warehouse', 8);
            $table->string('to_warehouse', 8);
            $this->documentColumns($table);
        });

        Schema::create('inventory_transfer_pallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('pallet_id')->constrained()->restrictOnDelete();
            // The approved count that made the pallet eligible.
            $table->foreignId('pallet_audit_id')->constrained()->restrictOnDelete();
            $this->lineColumns($table);

            $table->unique(['inventory_transfer_id', 'pallet_id']);
        });

        Schema::create('integration_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->uuid('request_uuid');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('operation', 20);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            $table->string('outcome', 30);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->char('request_hash', 64)->nullable();
            // Sanitized: never cookies, credentials or full SAP payloads.
            $table->jsonb('response_summary')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['document_type', 'document_id', 'attempt_number']);
            $table->index('request_uuid');
            $table->index(['outcome', 'started_at']);
        });

        foreach (['production_receipts', 'inventory_transfers'] as $table) {
            $checks = [
                'status' => "status IN ('SOLICITADO', 'ENVIANDO', 'PENDIENTE_CONCILIACION', 'CONFIRMADO', 'FALLIDO')",
                'confirmed_has_document' => "(status = 'CONFIRMADO') = (sap_doc_entry IS NOT NULL AND sap_doc_num IS NOT NULL AND confirmed_at IS NOT NULL)",
                'failed_has_date' => "(status = 'FALLIDO') = (failed_at IS NOT NULL)",
                'sent_has_date' => "status IN ('SOLICITADO', 'FALLIDO') OR sent_at IS NOT NULL",
            ];

            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name}_check CHECK ({$expression})");
            }

            DB::statement("CREATE UNIQUE INDEX {$table}_sap_doc_entry_unique ON {$table} (sap_doc_entry) WHERE sap_doc_entry IS NOT NULL");
        }

        DB::statement("ALTER TABLE inventory_transfers ADD CONSTRAINT inventory_transfers_mode_check CHECK (mode IN ('INDIVIDUAL', 'AGRUPADO'))");
        DB::statement('ALTER TABLE inventory_transfers ADD CONSTRAINT inventory_transfers_warehouses_check CHECK (from_warehouse <> to_warehouse)');

        foreach (['production_receipt_pallets', 'inventory_transfer_pallets'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_hold_status_check CHECK (hold_status IN ('ACTIVA', 'CONFIRMADA', 'LIBERADA'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_quantities_check CHECK (boxes > 0 AND units_per_box > 0 AND sap_quantity = boxes * units_per_box)");
            // The core double-receipt / double-transfer guard.
            DB::statement("CREATE UNIQUE INDEX {$table}_one_hold ON {$table} (pallet_id) WHERE hold_status IN ('ACTIVA', 'CONFIRMADA')");
        }

        DB::statement("ALTER TABLE integration_attempts ADD CONSTRAINT integration_attempts_outcome_check CHECK (outcome IN ('EXITO', 'ERROR_NEGOCIO', 'INCIERTO', 'CONSULTA_FALLIDA'))");
        DB::statement("ALTER TABLE integration_attempts ADD CONSTRAINT integration_attempts_operation_check CHECK (operation IN ('CREAR', 'CONSULTAR'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sap_documents_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('CONFIRMADO', 'FALLIDO') THEN
                    RAISE EXCEPTION 'append_only: el documento % ya está cerrado (%)', OLD.request_uuid, OLD.status;
                END IF;

                IF NEW.request_uuid IS DISTINCT FROM OLD.request_uuid OR NEW.payload_hash IS DISTINCT FROM OLD.payload_hash
                   OR NEW.requested_by IS DISTINCT FROM OLD.requested_by OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
                    RAISE EXCEPTION 'append_only: la solicitud % no se modifica, solo su estado', OLD.request_uuid;
                END IF;

                IF NOT state_transition_allowed('sap_document', OLD.status, NEW.status) THEN
                    RAISE EXCEPTION 'sap_document_guard: transición no permitida en %: % -> %', OLD.request_uuid, OLD.status, NEW.status;
                END IF;

                RETURN NEW;
            END;
            $$;

            -- A line only changes its hold (ACTIVA -> CONFIRMADA | LIBERADA) and its SAP line number.
            CREATE OR REPLACE FUNCTION sap_document_lines_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.hold_status <> 'ACTIVA' THEN
                    RAISE EXCEPTION 'append_only: la línea de la estiba % ya está %', OLD.pallet_id, OLD.hold_status;
                END IF;

                IF (NEW.pallet_id, NEW.boxes, NEW.units_per_box, NEW.sap_quantity, NEW.box_conversion_factor_id, NEW.item_code, NEW.lot_code)
                   IS DISTINCT FROM
                   (OLD.pallet_id, OLD.boxes, OLD.units_per_box, OLD.sap_quantity, OLD.box_conversion_factor_id, OLD.item_code, OLD.lot_code) THEN
                    RAISE EXCEPTION 'append_only: las cantidades de la línea no se modifican';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER production_receipts_guard BEFORE UPDATE ON production_receipts FOR EACH ROW EXECUTE FUNCTION sap_documents_guard();
            CREATE TRIGGER production_receipts_no_delete BEFORE DELETE ON production_receipts FOR EACH ROW EXECUTE FUNCTION forbid_delete();
            CREATE TRIGGER inventory_transfers_guard BEFORE UPDATE ON inventory_transfers FOR EACH ROW EXECUTE FUNCTION sap_documents_guard();
            CREATE TRIGGER inventory_transfers_no_delete BEFORE DELETE ON inventory_transfers FOR EACH ROW EXECUTE FUNCTION forbid_delete();

            CREATE TRIGGER production_receipt_pallets_guard BEFORE UPDATE ON production_receipt_pallets FOR EACH ROW EXECUTE FUNCTION sap_document_lines_guard();
            CREATE TRIGGER production_receipt_pallets_no_delete BEFORE DELETE ON production_receipt_pallets FOR EACH ROW EXECUTE FUNCTION forbid_delete();
            CREATE TRIGGER inventory_transfer_pallets_guard BEFORE UPDATE ON inventory_transfer_pallets FOR EACH ROW EXECUTE FUNCTION sap_document_lines_guard();
            CREATE TRIGGER inventory_transfer_pallets_no_delete BEFORE DELETE ON inventory_transfer_pallets FOR EACH ROW EXECUTE FUNCTION forbid_delete();

            CREATE TRIGGER integration_attempts_append_only BEFORE UPDATE OR DELETE ON integration_attempts FOR EACH ROW EXECUTE FUNCTION forbid_update_delete();
        SQL);
    }

    private function documentColumns(Blueprint $table): void
    {
        $table->string('status', 30)->default('SOLICITADO');
        // Exact body that is (or would be) sent, sanitized, and its sha256.
        $table->jsonb('payload');
        $table->char('payload_hash', 64);
        $table->integer('sap_doc_entry')->nullable();
        $table->integer('sap_doc_num')->nullable();
        $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
        $table->timestampTz('requested_at');
        $table->timestampTz('sent_at')->nullable();
        $table->timestampTz('confirmed_at')->nullable();
        $table->timestampTz('failed_at')->nullable();
        $table->string('last_error_code', 60)->nullable();
        $table->string('last_error_message', 500)->nullable();
        // What reconciliation found in SAP, and when.
        $table->jsonb('reconciliation')->nullable();
        $table->unsignedSmallInteger('attempts')->default(0);
        $table->timestampsTz();

        $table->index(['status', 'requested_at']);
    }

    private function lineColumns(Blueprint $table): void
    {
        $table->string('hold_status', 20)->default('ACTIVA');
        $table->integer('boxes');
        $table->foreignId('box_conversion_factor_id')->constrained()->restrictOnDelete();
        $table->decimal('units_per_box', 18, 6);
        $table->decimal('sap_quantity', 18, 6);
        $table->string('item_code', 50);
        $table->string('lot_code', 50);
        // Line of the SAP document holding this pallet, known after confirmation.
        $table->integer('sap_line_num')->nullable();
        $table->timestampsTz();
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_attempts');
        Schema::dropIfExists('inventory_transfer_pallets');
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('production_receipt_pallets');
        Schema::dropIfExists('production_receipts');

        DB::unprepared('DROP FUNCTION IF EXISTS sap_document_lines_guard(); DROP FUNCTION IF EXISTS sap_documents_guard();');
    }
};
