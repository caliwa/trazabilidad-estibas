<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A batch of box labels goes to the Zebra once. Printing it again puts
     * a second label with the same QR token on another box, which then can
     * never be counted. Batches already printed more than once keep their
     * history; from now on the database refuses another print of a batch.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION box_label_prints_once() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM box_label_prints WHERE box_label_batch_id = NEW.box_label_batch_id) THEN
                    RAISE EXCEPTION 'box_label_print: la tanda % ya se imprimió; sus etiquetas no se vuelven a imprimir', NEW.box_label_batch_id;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER box_label_prints_once BEFORE INSERT ON box_label_prints FOR EACH ROW EXECUTE FUNCTION box_label_prints_once();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS box_label_prints_once ON box_label_prints; DROP FUNCTION IF EXISTS box_label_prints_once();');
    }
};
