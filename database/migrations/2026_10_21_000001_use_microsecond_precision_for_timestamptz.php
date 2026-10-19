<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The phase 1 tables were first created with timestamptz(0), Laravel's
     * default, which rounds to whole seconds. New databases already get
     * timestamptz(6) (see AppServiceProvider); this fixes databases that
     * were migrated before. Values already stored keep their seconds.
     */
    public function up(): void
    {
        foreach ($this->columns(0) as $column) {
            DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE timestamp(6) with time zone', $column->table_name, $column->column_name));
        }
    }

    public function down(): void
    {
        // Going back to whole seconds would round stored values; left as is on purpose.
    }

    /**
     * @return list<object{table_name: string, column_name: string}>
     */
    private function columns(int $precision): array
    {
        /** @var list<object{table_name: string, column_name: string}> */
        return DB::select(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND data_type = 'timestamp with time zone' AND datetime_precision = ?
             ORDER BY table_name, column_name",
            [$precision],
        );
    }
};
