<?php

namespace App\Console\Commands;

use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Models\ProductionOrder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Same as the "Actualizar desde SAP" button of the order board, from the
 * console: brings every production order released in SAP, with its
 * product, and closes here the ones SAP no longer has released.
 */
#[Signature('sap:sync-orders')]
#[Description('Trae desde SAP todas las órdenes de fabricación liberadas (ProductionOrders).')]
class SapSyncOrders extends Command
{
    public function handle(SyncReleasedOrders $sync): int
    {
        $result = $sync->handle(null, TraceContext::system(Origin::Console, (string) Str::uuid()));

        if ($result->error !== null) {
            $this->error($result->error);

            return self::FAILURE;
        }

        $this->info("SAP tiene {$result->released} órdenes de fabricación liberadas. Guardadas en la aplicación.");

        $orders = ProductionOrder::query()->where('sap_status', 'LIBERADO')->with('item:id,code,description')->orderBy('ref')->get();
        $this->table(['OF', 'DocEntry', 'Producto', 'Planificado', 'Fin'], $orders->map(fn (ProductionOrder $o) => [
            $o->ref,
            $o->sap_doc_entry,
            $o->item->code.' '.$o->item->description,
            rtrim(rtrim((string) $o->planned_quantity, '0'), '.').' '.$o->uom_name,
            $o->due_date?->format('d/m/Y'),
        ])->all());

        foreach ($result->problems as $ref => $problem) {
            $this->warn("OF {$ref}: {$problem}");
        }

        return $result->problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
