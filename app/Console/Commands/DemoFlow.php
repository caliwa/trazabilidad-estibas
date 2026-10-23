<?php

namespace App\Console\Commands;

use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Audits\Data\BlindCountOutcome;
use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxLot;
use App\Domain\Devices\ManageDevices;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Pallets\Actions\CloseManually;
use App\Domain\Pallets\Actions\ConfirmLabeled;
use App\Domain\Pallets\Actions\ConfirmPalletized;
use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Pallets\Actions\ProcessScanEvent;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\PalletQr;
use App\Domain\Receipts\Actions\RequestProductionReceipts;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Fake\FakeSapCatalog;
use App\Integrations\Sap\Fake\FakeSapGateway;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Models\BoxConversionFactor;
use App\Models\Device;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Demo data for looking at the screens: runs the real use cases, from the
 * sensor reads to the transfer 04 → 05, against the SAP simulator.
 *
 * Pending decisions (DP-01, DP-03) are switched on only inside this
 * process, never in the configuration. Refused in production and with a
 * real SAP connector.
 */
#[Signature('demo:flow')]
#[Description('Crea datos de demostración recorriendo el flujo completo con el simulador de SAP (solo fuera de producción).')]
class DemoFlow extends Command
{
    private const PASSWORD = 'password';

    private Device $sensor;

    private int $event = 0;

    private CarbonImmutable $day;

    public function handle(SapGateway $sap): int
    {
        if (app()->isProduction() || ! $sap instanceof FakeSapGateway) {
            $this->error('demo:flow solo corre fuera de producción y con SAP_DRIVER=fake.');

            return self::FAILURE;
        }

        if (Device::query()->where('code', 'SEN-DEMO-L1')->exists()) {
            $this->error('Los datos de demostración ya existen. Para empezar de cero: php artisan migrate:fresh --seed && php artisan demo:flow');

            return self::FAILURE;
        }

        $saved = config()->array('traceability.decisions');
        $savedQueue = config('queue.default');

        // Only for this process: the switches stay off in .env.
        config([
            'traceability.decisions.scan_ingestion_enabled' => true,
            'traceability.decisions.label_confirmation' => 'scan',
            // SAP jobs and notifications run here and now, so the result is visible at once.
            'queue.default' => 'sync',
        ]);

        // A plausible shift on the last finished day, so the history reads like one.
        $this->day = now()->hour >= 16 ? CarbonImmutable::today() : CarbonImmutable::yesterday();

        try {
            $this->simulate($sap);
        } finally {
            Carbon::setTestNow();
            config(['traceability.decisions' => $saved, 'queue.default' => $savedQueue]);
        }

        return self::SUCCESS;
    }

    private function simulate(FakeSapGateway $sap): void
    {
        $this->at('05:45');
        $people = $this->people();
        [$oats, $choco, $noStandard, $complete] = $this->masters($people['produccion']);
        $lotA1 = BoxLot::code($oats->ref, $this->day);
        $lotA2 = BoxLot::code($oats->ref, $this->day->subDay());
        $lotA3 = BoxLot::code($oats->ref, $this->day->subDays(2));
        $lotB1 = BoxLot::code($choco->ref, $this->day);
        $lotC1 = BoxLot::code($noStandard->ref, $this->day);
        $this->sensor = app(ManageDevices::class)->register('SEN-DEMO-L1', 'Sensor demo línea 1', 'SENSOR', $this->console())[0];

        // The box labels go first: the receipt takes each lot's expiration from them.
        $this->labels($people['produccion'], $oats, 35, $this->day);
        $this->labels($people['produccion'], $oats, 10, $this->day->subDay());
        $this->labels($people['produccion'], $oats, 10, $this->day->subDays(2));
        $this->labels($people['produccion'], $choco, 19, $this->day);
        $this->labels($people['produccion'], $noStandard, 2, $this->day);

        $this->at('06:00');
        $this->components->info('1. Lecturas del sensor');
        $this->reads($oats, $lotA1, 35);
        $this->reads($oats, $lotA2, 10);
        $this->reads($oats, $lotA3, 10);
        $this->reads($choco, $lotB1, 19);
        $repeated = $this->reads($choco, $lotB1, 0, reuse: 3);
        $this->reads($noStandard, $lotC1, 2);
        $this->unknownOrder();
        $this->line("   74 cajas leídas, {$repeated} reenvíos duplicados (no suman), 3 lecturas rechazadas.");

        $this->at('07:10');
        $this->components->info('2. Cierre manual, paletizado y etiqueta (lectura del QR pegado)');
        $partial = $this->open($oats, $lotA1);
        app(CloseManually::class)->handle($people['produccion'], $partial->id, 'Fin de turno', $this->web($people['produccion']));

        $finished = Pallet::query()->where('physical_status', PhysicalStatus::Finished)->orderBy('id')->get();
        $unlabeled = $finished->last();
        foreach ($finished as $pallet) {
            $this->tick(180);
            app(ConfirmPalletized::class)->handle($people['produccion'], $pallet->id, $this->web($people['produccion']));

            if ($pallet->is($unlabeled)) {
                continue;
            }

            $this->tick(60);
            app(PrintLabel::class)->handle($people['produccion'], $pallet->id, null, $this->web($people['produccion']));
            app(ConfirmLabeled::class)->handle($people['produccion'], $pallet->id, PalletQr::payload($pallet->refresh()), $this->web($people['produccion']));
        }
        $this->line('   '.($finished->count() - 1).' estibas etiquetadas; '.$unlabeled->pallet_code.' queda paletizada sin etiqueta.');

        $this->at('09:30');
        $this->components->info('3. Recibos de producción en 04');
        $a1 = $this->pallets($oats, $lotA1);
        $a2 = $this->pallets($oats, $lotA2);
        $a3 = $this->pallets($oats, $lotA3);
        $b1 = $this->pallets($choco, $lotB1)->where('physical_status', PhysicalStatus::Labeled);
        $receipts = app(RequestProductionReceipts::class);
        $receipts->handle($people['supervisor'], $this->ids($a1), false, $this->web($people['supervisor']));
        $receipts->handle($people['supervisor'], $this->ids($b1), false, $this->web($people['supervisor']));
        $receipts->handle($people['supervisor'], $this->ids($a2), false, $this->web($people['supervisor']));
        $this->tick(600);
        $sap->queueReceiptScenarios(FakeScenario::CreateThenTimeout);
        $receipts->handle($people['supervisor'], $this->ids($a3), false, $this->web($people['supervisor']));
        $this->line("   Lote {$lotA1} agrupado, {$lotA2} y {$lotB1} confirmados; {$lotA3} queda pendiente de conciliación (respuesta perdida).");

        $this->at('13:00');
        $this->components->info('4. Conteo ciego en el CEDI (lectura del QR) y transferencia de 04 a 05');
        [$p1, $p2, $p3, $manual] = $a1->values()->all();
        $this->count($people['cedi'], $p1, $p1->boxes_count);
        $this->count($people['cedi'], $p2, $p2->boxes_count);
        $this->count($people['cedi'], $manual, $manual->boxes_count);
        $this->count($people['cedi2'], $p3, $p3->boxes_count - 1);
        $sap->queueTransferScenarios(FakeScenario::TimeoutWithoutCreating);
        $this->count($people['cedi2'], $b1->first(), $b1->first()->boxes_count);
        $this->line("   4 aprobadas: cada una pidió su transferencia a 05 al aprobarse. 3 confirmadas en SAP; la de {$b1->first()->pallet_code} queda pendiente (timeout sin crear).");
        $this->line("   1 discrepancia en {$p3->pallet_code} (se contó una caja menos): no se mueve.");

        $this->at('14:00');
        $this->components->info('5. Una OF completa: recibos por todo lo planificado');
        $lotD1 = BoxLot::code($complete->ref, $this->day);
        $this->labels($people['produccion'], $complete, 10, $this->day);
        $this->reads($complete, $lotD1, 10);
        foreach (Pallet::query()->where('production_order_id', $complete->id)->where('physical_status', PhysicalStatus::Finished)->orderBy('id')->get() as $pallet) {
            $this->tick(120);
            app(ConfirmPalletized::class)->handle($people['produccion'], $pallet->id, $this->web($people['produccion']));
            app(PrintLabel::class)->handle($people['produccion'], $pallet->id, null, $this->web($people['produccion']));
            app(ConfirmLabeled::class)->handle($people['produccion'], $pallet->id, PalletQr::payload($pallet->refresh()), $this->web($people['produccion']));
        }
        $this->tick(300);
        $receipts->handle($people['supervisor'], $this->ids($this->pallets($complete, $lotD1)), false, $this->web($people['supervisor']));
        foreach ($this->pallets($complete, $lotD1) as $pallet) {
            $this->count($people['cedi'], $pallet, $pallet->boxes_count);
        }
        $this->line("   OF {$complete->ref}: 240 de 240 unidades recibidas y transferidas a CEDI. Queda lista para cerrar en SAP y llega la alerta a los supervisores.");

        $this->at('16:00');
        $this->components->info('6. Tablero de OF actualizado desde SAP');
        $sync = app(SyncReleasedOrders::class)->handle($people['supervisor'], $this->web($people['supervisor']));
        $this->line("   {$sync->released} órdenes liberadas en SAP en el tablero.");

        $ready = $a2->first();

        $this->newLine();
        $this->components->info('Listo. Para mirar:');
        $this->table(['Pantalla', 'Ruta', 'Usuario'], [
            ['Tablero de OF (KPIs; 33134 lista para cerrar)', '/orders', 'supervisor@example.com'],
            ['Tablero e historia de cada estiba', '/pallets', 'supervisor@example.com'],
            ['Etiquetas de caja (consultar OF 33128)', '/box-labels', 'produccion@example.com'],
            ['Estación de producción', '/production', 'produccion@example.com'],
            ['Lecturas rechazadas', '/pallets/rejected-scans', 'supervisor@example.com'],
            ['Recibos (con uno pendiente)', '/receipts', 'supervisor@example.com'],
            ['Conteo ciego', '/cedi/count', 'cedi@example.com'],
            ['Discrepancias', '/discrepancies', 'supervisor@example.com'],
            ['Transferencia pendiente (reintentar o conciliar)', '/pallets/'.$b1->first()->id, 'supervisor@example.com'],
            ['Estado operativo', '/operations', 'supervisor@example.com'],
        ]);
        $this->line('Contraseña de todos: '.self::PASSWORD.'. El administrador sigue siendo admin@example.com.');
        $this->newLine();
        $this->line("Para probar el conteo ciego a mano, {$ready->pallet_code} está recibida y sin contar.");
        $this->line('Entrar como cedi@example.com en /cedi/count y pegar lo que leería el escáner:');
        $this->line('   '.PalletQr::payload($ready));
        $this->line('Con 10 cajas el conteo se aprueba y la transferencia de 04 a 05 sale para SAP (necesita un worker: composer dev).');
        $this->warn('El simulador de SAP vive en memoria: al conciliar desde la pantalla los pendientes no aparecen en SAP y, pasado el tiempo de gracia, se reenvían.');
    }

    /**
     * @return array<string, User>
     */
    private function people(): array
    {
        $people = [];
        foreach ([
            'produccion' => ['Operario de producción demo', Role::ProductionOperator],
            'cedi' => ['Operario CEDI demo', Role::LogisticsOperator],
            'cedi2' => ['Segundo operario CEDI demo', Role::LogisticsOperator],
            'supervisor' => ['Supervisor de trazabilidad demo', Role::TraceabilityAdmin],
        ] as $key => [$name, $role]) {
            $user = User::query()->firstOrCreate(['email' => "{$key}@example.com"], ['name' => $name, 'password' => self::PASSWORD]);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            $user->syncRoles([$role->value]);
            $people[$key] = $user;
        }

        return $people;
    }

    /**
     * The 40 finished products of the SAP catalog, and four released
     * orders looked up in SAP the same way the box labels screen does it.
     *
     * @return array{ProductionOrder, ProductionOrder, ProductionOrder, ProductionOrder}
     */
    private function masters(User $consultant): array
    {
        foreach (FakeSapCatalog::ITEMS as $code => $description) {
            $item = Item::query()->where('code', $code)->first() ?? (new Item)->forceFill(['code' => $code]);
            // Shelf life is a demo value: the real one per product is still to be defined.
            $item->forceFill(['description' => $description, 'inventory_uom' => 'UNIDAD', 'barcode' => FakeSapCatalog::barcode($code), 'shelf_life_days' => 730, 'active' => true, 'synced_at' => now()])->save();
        }

        $consult = app(ConsultProductionOrder::class);
        $order = function (string $docNum, ?int $boxes, string $units) use ($consult, $consultant): ProductionOrder {
            $order = $consult->handle($consultant, $docNum, $this->web($consultant));

            if ($boxes !== null) {
                (new PalletStandard)->forceFill(['item_id' => $order->item_id, 'version' => 1, 'boxes_per_pallet' => $boxes, 'sap_source_key' => 'DEMO', 'status' => MasterStatus::Current, 'synced_at' => now()])->save();
            }

            (new BoxConversionFactor)->forceFill(['item_id' => $order->item_id, 'version' => 1, 'units_per_box' => $units, 'sap_uom_code' => 'UNIDAD', 'sap_source_key' => 'DEMO', 'status' => MasterStatus::Current, 'synced_at' => now()])->save();

            return $order;
        };

        return [
            // GELATINA OLIMPICA SIN SABOR 30 GR, 12 per box so its 800 units cover the 55 boxes read
            $order('33128', 10, '12'),
            // GELATINA ALKOSTO SIN SABOR 30 GR
            $order('33131', 8, '24'),
            // GELATINA AZUCARADA BOOM LIMON 1000 GR, without a pallet standard on purpose
            $order('33132', null, '12'),
            // GELATINA MERCALDAS SIN SABOR 30 GR: 240 units, 10 boxes, 2 pallets, received in full
            $order('33134', 5, '24'),
        ];
    }

    /**
     * Box labels for one lot, with the expiration proposed from the shelf life.
     */
    private function labels(User $operator, ProductionOrder $order, int $labels, CarbonImmutable $productionDate): void
    {
        $this->tick(30);
        app(GenerateBoxLabels::class)->handle($operator, $order->id, $labels, $productionDate, null, $this->web($operator));
    }

    /**
     * Sends $boxes new reads, and resends the last $reuse ones (duplicates).
     */
    private function reads(ProductionOrder $order, string $lot, int $boxes, int $reuse = 0): int
    {
        $process = app(ProcessScanEvent::class);
        $item = Item::query()->findOrFail($order->item_id);

        for ($i = 0; $i < $boxes; $i++) {
            $this->tick(45);
            $this->event++;
            $process->handle($this->sensor, $this->read($order->ref, $item->code, $lot, $this->event), TraceContext::forDevice($this->sensor, (string) Str::uuid()));
        }

        for ($i = 0; $i < $reuse; $i++) {
            // Same id and same content as an earlier read: answered as duplicate, not counted.
            $process->handle($this->sensor, $this->read($order->ref, $item->code, $lot, $this->event - $i), TraceContext::forDevice($this->sensor, (string) Str::uuid()));
        }

        return $reuse;
    }

    private function unknownOrder(): void
    {
        $this->tick(45);
        $this->event++;
        app(ProcessScanEvent::class)->handle($this->sensor, $this->read('99999', 'PT1350023', '99999293', $this->event), TraceContext::forDevice($this->sensor, (string) Str::uuid()));
    }

    private function read(string $order, string $item, string $lot, int $n): ScanEventData
    {
        // Read n happened 45 s × n after 06:00, so a resend carries exactly the same payload.
        return new ScanEventData('DEMO-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), $order, $item, $lot, $this->day->setTime(6, 0)->addSeconds(45 * $n));
    }

    private function open(ProductionOrder $order, string $lot): Pallet
    {
        return Pallet::query()->where('production_order_id', $order->id)->where('lot_code', $lot)->where('physical_status', PhysicalStatus::Stacking)->sole();
    }

    /**
     * @return Collection<int, Pallet>
     */
    private function pallets(ProductionOrder $order, string $lot): Collection
    {
        return Pallet::query()->where('production_order_id', $order->id)->where('lot_code', $lot)->where('physical_status', PhysicalStatus::Labeled)->orderBy('id')->get()->collect();
    }

    /**
     * @param  Collection<int, Pallet>  $pallets
     * @return list<int>
     */
    private function ids(Collection $pallets): array
    {
        return array_values(array_map(fn (Pallet $p) => $p->id, $pallets->all()));
    }

    private function count(User $counter, Pallet $pallet, int $boxes): void
    {
        $this->tick(120);
        $view = app(StartBlindCount::class)->handle($counter, PalletQr::payload($pallet), $this->web($counter));
        $this->tick(240);
        // A wrong number is counted again on every try, so it ends in a discrepancy.
        $outcome = app(SubmitBlindCount::class)->handle($counter, $view->auditId, $boxes, $this->web($counter));
        while ($outcome === BlindCountOutcome::Retry) {
            $this->tick(30);
            $outcome = app(SubmitBlindCount::class)->handle($counter, $view->auditId, $boxes, $this->web($counter));
        }
    }

    private function at(string $time): void
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        Carbon::setTestNow($this->day->setTime($hour, $minute));
    }

    private function tick(int $seconds): void
    {
        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    private function web(User $user): TraceContext
    {
        return TraceContext::forUser($user, Origin::Console, (string) Str::uuid());
    }

    private function console(): TraceContext
    {
        return TraceContext::system(Origin::Console, (string) Str::uuid());
    }
}
