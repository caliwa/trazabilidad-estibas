<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Boxes with more than one counted read. Since the one-read-per-token rule
 * the database refuses them; this finds the ones counted before it, so a
 * person can review them (and void the extra read where it applies).
 */
#[Signature('boxes:duplicates')]
#[Description('Lista las cajas que tienen más de una lectura contada (anteriores a la regla de un token, una lectura).')]
class BoxDuplicates extends Command
{
    public function handle(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT b.code AS box, count(*) AS reads,
                   string_agg(e.id || ' ' || d.code || ' ' || coalesce(p.pallet_code, '-') || ' ' || to_char(e.received_at, 'YYYY-MM-DD HH24:MI:SS'), ' | ' ORDER BY e.id) AS detail
            FROM pallet_scan_events e
            -- Reads from before the token rule may name the box by its code: this report looks for those too.
            JOIN box_labels b ON b.id = coalesce(e.box_label_id, box_label_for_event(e.external_event_id), (SELECT id FROM box_labels WHERE code = e.external_event_id))
            JOIN devices d ON d.id = e.device_id
            LEFT JOIN pallets p ON p.id = e.pallet_id
            WHERE e.processing_status = 'ACEPTADO'
              AND NOT EXISTS (SELECT 1 FROM box_voids v WHERE v.pallet_scan_event_id = e.id)
            GROUP BY b.code
            HAVING count(*) > 1
            ORDER BY b.code
            SQL);

        if ($rows === []) {
            $this->info('Ninguna caja tiene más de una lectura contada.');

            return self::SUCCESS;
        }

        $this->warn(count($rows).' caja(s) con más de una lectura contada:');
        $this->table(['Caja', 'Lecturas', 'Detalle (evento, dispositivo, estiba, recibida)'], array_map(fn ($r) => [(string) data_get($r, 'box'), (int) data_get($r, 'reads'), (string) data_get($r, 'detail')], $rows));

        return self::FAILURE;
    }
}
