<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable list of reasons for closing an incomplete pallet. A reason
     * in use is never deleted, only deactivated, so closed pallets keep it.
     */
    public function up(): void
    {
        Schema::create('pallet_close_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX pallet_close_reasons_name_unique ON pallet_close_reasons (lower(name))');
        DB::statement("ALTER TABLE pallet_close_reasons ADD CONSTRAINT pallet_close_reasons_name_not_blank CHECK (btrim(name) <> '')");

        Schema::table('pallets', function (Blueprint $table) {
            $table->foreignId('close_reason_id')->nullable()->after('closed_by')->constrained('pallet_close_reasons')->restrictOnDelete();
            $table->string('close_note', 300)->nullable()->after('close_reason_id');
        });

        DB::statement("ALTER TABLE pallets ADD CONSTRAINT pallets_close_reason_is_manual CHECK (close_reason_id IS NULL OR close_type = 'MANUAL')");
        DB::statement('ALTER TABLE pallets ADD CONSTRAINT pallets_close_note_needs_reason CHECK (close_note IS NULL OR close_reason_id IS NOT NULL)');

        // Pallets closed by hand before this change have no reason; from now on
        // a manual close needs one, and once recorded it does not change.
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
            CREATE TRIGGER pallets_close_reason_guard BEFORE UPDATE ON pallets FOR EACH ROW EXECUTE FUNCTION pallets_close_reason_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pallets_close_reason_guard ON pallets; DROP FUNCTION IF EXISTS pallets_close_reason_guard();');
        DB::statement('ALTER TABLE pallets DROP CONSTRAINT IF EXISTS pallets_close_reason_is_manual');
        DB::statement('ALTER TABLE pallets DROP CONSTRAINT IF EXISTS pallets_close_note_needs_reason');
        Schema::table('pallets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('close_reason_id');
            $table->dropColumn('close_note');
        });
        Schema::dropIfExists('pallet_close_reasons');
    }
};
