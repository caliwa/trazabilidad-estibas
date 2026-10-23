<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each number an operator typed in a blind count. A count has up to
     * traceability.count_tries tries before a discrepancy opens; the wrong
     * ones are kept here as evidence and never change.
     */
    public function up(): void
    {
        Schema::create('pallet_audit_tries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_audit_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('try_number');
            $table->integer('counted_boxes');
            $table->boolean('matches');
            $table->timestampTz('created_at');

            $table->unique(['pallet_audit_id', 'try_number']);
        });

        DB::statement('ALTER TABLE pallet_audit_tries ADD CONSTRAINT pallet_audit_tries_values_check CHECK (try_number >= 1 AND counted_boxes >= 0)');
        DB::unprepared('CREATE TRIGGER pallet_audit_tries_append_only BEFORE UPDATE OR DELETE ON pallet_audit_tries FOR EACH ROW EXECUTE FUNCTION forbid_update_delete()');
    }

    public function down(): void
    {
        Schema::dropIfExists('pallet_audit_tries');
    }
};
