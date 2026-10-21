<?php

namespace App\Console\Commands;

use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Integrations\Sap\ServiceLayer\ServiceLayerGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks the connection with SAP: logs in to Service Layer and reads the
 * released production orders, or one order by number. Only reads; never
 * prints credentials or session data.
 */
#[Signature('sap:check {of? : Número de una OF para consultarla}')]
#[Description('Prueba la conexión con SAP Service Layer (solo lectura).')]
class SapCheck extends Command
{
    public function handle(SapGateway $sap): int
    {
        if (! $sap instanceof ServiceLayerGateway) {
            $this->error('SAP_DRIVER no es service_layer: no hay conexión real que probar.');

            return self::FAILURE;
        }

        $this->line('Ambiente SAP: '.config('sap.environment').' · '.config('sap.service_layer.base_url').' · compañía '.config('sap.service_layer.company_db'));

        try {
            $of = $this->argument('of');

            if (is_string($of) && $of !== '') {
                $order = $sap->findProductionOrder($of);

                if ($order === null) {
                    $this->warn("SAP no tiene la OF {$of}.");

                    return self::SUCCESS;
                }

                $this->table(['OF', 'DocEntry', 'Estado', 'Producto', 'Planificado', 'Almacén'], [[
                    $order->docNum, $order->docEntry, $order->status->label(), "{$order->itemCode} {$order->itemDescription}", $order->plannedQuantity->display().' '.$order->uomName, $order->warehouse,
                ]]);

                return self::SUCCESS;
            }

            $orders = $sap->releasedProductionOrders();
            $this->info('Conexión correcta. OF liberadas en SAP: '.count($orders));
            $this->table(['OF', 'DocEntry', 'Producto', 'Planificado'], array_map(
                fn ($o) => [$o->docNum, $o->docEntry, "{$o->itemCode} {$o->itemDescription}", $o->plannedQuantity->display().' '.$o->uomName],
                array_slice($orders, 0, 20),
            ));
        } catch (SapLookupFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Error al probar SAP: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
