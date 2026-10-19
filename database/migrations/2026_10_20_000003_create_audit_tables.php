<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blind counts at the warehouse and the discrepancy cases they open.
     *
     * expected_boxes is written by the server when the count is compared,
     * as evidence for the administrator. It never travels to the operator.
     */
    public function up(): void
    {
        Schema::create('pallet_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->integer('counted_boxes')->nullable();
            $table->integer('expected_boxes')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('reaudit_of_id')->nullable()->constrained('pallet_audits')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['pallet_id', 'attempt']);
            $table->index(['status', 'started_at']);
            $table->index('operator_id');
        });

        $checks = [
            'status' => "status IN ('EN_CONTEO', 'APROBADA', 'DISCREPANCIA', 'CANCELADA')",
            'counts_not_negative' => 'counted_boxes >= 0 AND expected_boxes >= 0',
            'open_has_no_result' => "status <> 'EN_CONTEO' OR (counted_boxes IS NULL AND expected_boxes IS NULL AND completed_at IS NULL)",
            'cancelled_has_no_result' => "status <> 'CANCELADA' OR (counted_boxes IS NULL AND expected_boxes IS NULL AND completed_at IS NOT NULL)",
            'closed_has_result' => "status NOT IN ('APROBADA', 'DISCREPANCIA') OR (counted_boxes IS NOT NULL AND expected_boxes IS NOT NULL AND completed_at IS NOT NULL)",
            'approved_matches' => "status <> 'APROBADA' OR counted_boxes = expected_boxes",
            'discrepancy_differs' => "status <> 'DISCREPANCIA' OR counted_boxes <> expected_boxes",
            'dates_in_order' => 'completed_at >= started_at',
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE pallet_audits ADD CONSTRAINT pallet_audits_{$name}_check CHECK ({$expression})");
        }

        // One count in progress per pallet.
        DB::statement("CREATE UNIQUE INDEX pallet_audits_one_open ON pallet_audits (pallet_id) WHERE status = 'EN_CONTEO'");

        Schema::create('discrepancy_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('pallet_audit_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->timestampTz('opened_at');
            // Allowed actions are DP-04; free text until the policy is approved.
            $table->string('resolution_action', 40)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('reaudit_id')->nullable()->constrained('pallet_audits')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'opened_at']);
            $table->index('pallet_id');
        });

        DB::statement("ALTER TABLE discrepancy_cases ADD CONSTRAINT discrepancy_cases_status_check CHECK (status IN ('ABIERTA', 'EN_REVISION', 'RESUELTA'))");
        DB::statement("ALTER TABLE discrepancy_cases ADD CONSTRAINT discrepancy_cases_resolution_check CHECK ((status = 'RESUELTA') = (resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND resolution_action IS NOT NULL AND btrim(coalesce(resolution_notes, '')) <> ''))");

        DB::unprepared(<<<'SQL'
            -- A finished count is evidence: it never changes afterwards.
            CREATE OR REPLACE FUNCTION pallet_audits_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status <> 'EN_CONTEO' THEN
                    RAISE EXCEPTION 'append_only: el conteo % de la estiba % ya terminó', OLD.attempt, OLD.pallet_id;
                END IF;

                IF (NEW.pallet_id, NEW.attempt, NEW.operator_id, NEW.started_at, NEW.reaudit_of_id)
                   IS DISTINCT FROM (OLD.pallet_id, OLD.attempt, OLD.operator_id, OLD.started_at, OLD.reaudit_of_id) THEN
                    RAISE EXCEPTION 'append_only: la identidad del conteo no se modifica';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION discrepancy_cases_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'RESUELTA' THEN
                    RAISE EXCEPTION 'append_only: la discrepancia % ya está resuelta', OLD.id;
                END IF;

                IF (NEW.pallet_id, NEW.pallet_audit_id, NEW.opened_at) IS DISTINCT FROM (OLD.pallet_id, OLD.pallet_audit_id, OLD.opened_at) THEN
                    RAISE EXCEPTION 'append_only: el origen de la discrepancia no se modifica';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER pallet_audits_guard BEFORE UPDATE ON pallet_audits FOR EACH ROW EXECUTE FUNCTION pallet_audits_guard();
            CREATE TRIGGER pallet_audits_no_delete BEFORE DELETE ON pallet_audits FOR EACH ROW EXECUTE FUNCTION forbid_delete();
            CREATE TRIGGER discrepancy_cases_guard BEFORE UPDATE ON discrepancy_cases FOR EACH ROW EXECUTE FUNCTION discrepancy_cases_guard();
            CREATE TRIGGER discrepancy_cases_no_delete BEFORE DELETE ON discrepancy_cases FOR EACH ROW EXECUTE FUNCTION forbid_delete();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('discrepancy_cases');
        Schema::dropIfExists('pallet_audits');

        DB::unprepared('DROP FUNCTION IF EXISTS discrepancy_cases_guard(); DROP FUNCTION IF EXISTS pallet_audits_guard();');
    }
};
