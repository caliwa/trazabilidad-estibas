<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices that send scan events, and the masters read from SAP.
     *
     * The master tables are a local, versioned read cache of SAP (DP-02):
     * SAP stays the source; the scanner must not depend on SAP answering in
     * the middle of a production run. Pallets freeze the version they used.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('kind', 20);
            // sha256 of the device token; the token itself is shown once and never stored.
            $table->char('token_hash', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('sap_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);
            $table->string('status', 20);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('items_read')->default(0);
            $table->unsignedInteger('items_changed')->default(0);
            $table->unsignedInteger('items_invalid')->default(0);
            $table->string('error_message', 500)->nullable();
            $table->timestampsTz();

            $table->index(['kind', 'started_at']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('description', 200);
            $table->string('inventory_uom', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            // Reference printed on the floor (which SAP field it maps to is part of DP-02).
            $table->string('ref', 50)->unique();
            $table->integer('sap_doc_entry')->nullable()->unique();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            // Raw SAP status, kept as received; is_open is what the app decides on.
            $table->string('sap_status', 30)->nullable();
            $table->boolean('is_open')->default(true);
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['item_id', 'is_open']);
        });

        Schema::create('pallet_standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            // Null only on rows SAP sent without a usable value (status INVALIDO).
            $table->integer('boxes_per_pallet')->nullable();
            $table->string('sap_source_key', 100);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 20);
            $table->string('invalid_reason', 300)->nullable();
            $table->foreignId('sap_sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('synced_at');
            $table->timestampsTz();

            $table->unique(['item_id', 'version']);
        });

        Schema::create('box_conversion_factors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            // Inventory units in one box. Exact decimal, never float.
            $table->decimal('units_per_box', 18, 6)->nullable();
            $table->string('sap_uom_code', 20)->nullable();
            $table->string('sap_source_key', 100);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 20);
            $table->string('invalid_reason', 300)->nullable();
            $table->foreignId('sap_sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('synced_at');
            $table->timestampsTz();

            $table->unique(['item_id', 'version']);
        });

        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_kind_check CHECK (kind IN ('SENSOR', 'LECTOR'))");
        DB::statement("ALTER TABLE sap_sync_runs ADD CONSTRAINT sap_sync_runs_status_check CHECK (status IN ('EN_CURSO', 'OK', 'ERROR'))");

        foreach (['pallet_standards' => 'boxes_per_pallet', 'box_conversion_factors' => 'units_per_box'] as $table => $value) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (status IN ('VIGENTE', 'REEMPLAZADO', 'INVALIDO'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_value_check CHECK (status = 'INVALIDO' OR {$value} > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_invalid_reason_check CHECK ((status = 'INVALIDO') = (invalid_reason IS NOT NULL))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_validity_check CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to > valid_from)");
            // One current version per item.
            DB::statement("CREATE UNIQUE INDEX {$table}_one_current ON {$table} (item_id) WHERE status = 'VIGENTE'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('box_conversion_factors');
        Schema::dropIfExists('pallet_standards');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('items');
        Schema::dropIfExists('sap_sync_runs');
        Schema::dropIfExists('devices');
    }
};
