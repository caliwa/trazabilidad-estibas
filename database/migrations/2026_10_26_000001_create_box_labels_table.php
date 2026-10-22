<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per printed box label, with its own code (lot + sequence, e.g.
 * 33128293-0007). The code goes in the box QR, so a box can be counted
 * only once. Append-only, like the batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('box_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('box_label_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $table->string('lot_code', 50);
            $table->unsignedInteger('sequence');
            $table->string('code', 60)->unique();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['production_order_id', 'lot_code', 'sequence']);
        });

        DB::statement('ALTER TABLE box_labels ADD CONSTRAINT box_labels_sequence_check CHECK (sequence > 0)');
        DB::unprepared('CREATE TRIGGER box_labels_append_only BEFORE UPDATE OR DELETE ON box_labels FOR EACH ROW EXECUTE FUNCTION forbid_update_delete()');
    }

    public function down(): void
    {
        Schema::dropIfExists('box_labels');
    }
};
