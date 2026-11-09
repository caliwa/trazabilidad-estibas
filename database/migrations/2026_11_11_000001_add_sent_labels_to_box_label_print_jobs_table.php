<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('box_label_print_jobs', 'sent_labels')) {
            Schema::table('box_label_print_jobs', function (Blueprint $table) {
                $table->unsignedInteger('sent_labels')->default(0)->after('printed_labels');
            });

            DB::statement('UPDATE box_label_print_jobs SET sent_labels = printed_labels');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('box_label_print_jobs', 'sent_labels')) {
            Schema::table('box_label_print_jobs', function (Blueprint $table) {
                $table->dropColumn('sent_labels');
            });
        }
    }
};
