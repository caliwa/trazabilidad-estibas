<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Box labels (the QR stuck on each box of product).
 *
 * Adds, without touching existing columns:
 *  - the production order header as read from SAP when someone looks the
 *    order up (all nullable: orders created before keep working);
 *  - the item data a box label needs (barcode, shelf life);
 *  - box_label_batches: one row per generated set of labels, append-only,
 *    as evidence of exactly what was printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('sap_order_type', 30)->nullable();
            $table->decimal('planned_quantity', 18, 6)->nullable();
            $table->string('uom_name', 30)->nullable();
            $table->string('warehouse_code', 8)->nullable();
            $table->integer('priority')->nullable();
            $table->date('order_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('sap_user', 100)->nullable();
            $table->string('sap_origin', 50)->nullable();
            $table->string('sap_linked_to', 50)->nullable();
            // Exactly what SAP answered on the last lookup.
            $table->jsonb('sap_header')->nullable();
            $table->timestampTz('consulted_at')->nullable();
            $table->foreignId('consulted_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE production_orders ADD CONSTRAINT production_orders_planned_quantity_check CHECK (planned_quantity IS NULL OR planned_quantity > 0)');

        Schema::table('items', function (Blueprint $table) {
            $table->string('barcode', 20)->nullable();
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
        });

        Schema::create('box_label_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('box_conversion_factor_id')->constrained()->restrictOnDelete();
            $table->string('production_order_ref', 50);
            $table->string('item_code', 50);
            $table->string('item_description', 200);
            $table->string('lot_code', 50);
            $table->date('production_date');
            $table->date('expiration_date');
            $table->decimal('units_per_box', 18, 6);
            $table->string('uom_name', 30)->nullable();
            $table->string('barcode', 20);
            $table->unsignedInteger('labels');
            // The exact text inside the QR and the exact ZPL sent to the printer.
            $table->string('qr_payload', 300);
            $table->text('zpl');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('generated_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['production_order_id', 'generated_at']);
        });

        DB::statement('ALTER TABLE box_label_batches ADD CONSTRAINT box_label_batches_labels_check CHECK (labels > 0)');
        DB::statement('ALTER TABLE box_label_batches ADD CONSTRAINT box_label_batches_units_check CHECK (units_per_box > 0)');
        DB::statement('ALTER TABLE box_label_batches ADD CONSTRAINT box_label_batches_dates_check CHECK (expiration_date > production_date)');
        DB::unprepared('CREATE TRIGGER box_label_batches_append_only BEFORE UPDATE OR DELETE ON box_label_batches FOR EACH ROW EXECUTE FUNCTION forbid_update_delete()');
    }

    public function down(): void
    {
        Schema::dropIfExists('box_label_batches');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['barcode', 'shelf_life_days']);
        });

        DB::statement('ALTER TABLE production_orders DROP CONSTRAINT IF EXISTS production_orders_planned_quantity_check');

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consulted_by');
            $table->dropColumn([
                'sap_order_type', 'planned_quantity', 'uom_name', 'warehouse_code', 'priority', 'order_date', 'start_date',
                'due_date', 'sap_user', 'sap_origin', 'sap_linked_to', 'sap_header', 'consulted_at',
            ]);
        });
    }
};
