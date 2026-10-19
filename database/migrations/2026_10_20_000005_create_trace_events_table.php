<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Traceability log: one row per critical operation, written by the use
     * cases inside their own transaction. Append-only, enforced by trigger
     * (application-level immutability; a database superuser can still bypass
     * it, which is what backups and access control are for).
     *
     * audit_logs stays for user and role administration.
     */
    public function up(): void
    {
        Schema::create('trace_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('event', 80);
            $table->string('actor_type', 20);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('from_state')->nullable();
            $table->jsonb('to_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('origin', 20);
            $table->uuid('correlation_id');
            $table->string('result', 20);
            $table->jsonb('sap_refs')->nullable();
            // Sanitized extra data. Never secrets, cookies or tokens.
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
            $table->index('correlation_id');
            $table->index('occurred_at');
        });

        DB::statement("ALTER TABLE trace_events ADD CONSTRAINT trace_events_actor_type_check CHECK (actor_type IN ('USUARIO', 'DISPOSITIVO', 'SISTEMA'))");
        DB::statement("ALTER TABLE trace_events ADD CONSTRAINT trace_events_actor_check CHECK ((actor_type = 'USUARIO' AND user_id IS NOT NULL) OR (actor_type = 'DISPOSITIVO' AND device_id IS NOT NULL) OR actor_type = 'SISTEMA')");
        DB::statement("ALTER TABLE trace_events ADD CONSTRAINT trace_events_origin_check CHECK (origin IN ('WEB', 'API', 'JOB', 'CONSOLA'))");
        DB::statement("ALTER TABLE trace_events ADD CONSTRAINT trace_events_result_check CHECK (result IN ('OK', 'RECHAZADO', 'ERROR'))");
        DB::statement('ALTER TABLE trace_events ADD CONSTRAINT trace_events_subject_check CHECK ((subject_type IS NULL) = (subject_id IS NULL))');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trace_events_append_only BEFORE UPDATE OR DELETE ON trace_events FOR EACH ROW EXECUTE FUNCTION forbid_update_delete();
            CREATE TRIGGER trace_events_no_truncate BEFORE TRUNCATE ON trace_events FOR EACH STATEMENT EXECUTE FUNCTION forbid_update_delete();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_events');
    }
};
