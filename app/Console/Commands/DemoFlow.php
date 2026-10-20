<?php

namespace App\Console\Commands;

use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Devices\ManageDevices;
use App\Domain\Integration\Enums\MasterStatus;
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
use App\Domain\Transfers\Actions\RequestInventoryTransfers;
use App\Domain\Transfers\Enums\TransferMode;
use App\Enums\Role;
use App\Integrations\Sap\Contracts\SapGateway;
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
 * Pending decisions (DP-01, DP-03, DP-05) are switched on only inside this
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

        if (Item::query()->where('code', 'PT-DEMO-01')->exists()) {
            $this->error('Los datos de demostración ya existen. Para empezar de cero: php artisan migrate:fresh --seed && php artisan demo:flow');

            return self::FAILURE;
        }

        $saved = config()->array('traceability.decisions');
        $savedQueue = config('queue.default');

        // Only for this process: the switches stay off in .env.
        config([
            'traceability.decisions.scan_ingestion_enabled' => true,
            'traceability.decisions.label_confirmation' => 'scan',
            'traceability.decisions.grouped_transfers_enabled' => true,
            'traceability.decisions.auto_transfer_after_audit' => false,
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
        [$oats, $choco, $noStandard] = $this->masters();
        $this->sensor = app(ManageDevices::class)->register('SEN-DEMO-L1', 'Sensor demo línea 1', 'SENSOR', $this->console())[0];

        $this->at('06:00');
        $this->components->info('1. Lecturas del sensor');
        $this->reads($oats, 'L-A1', 35);
        $this->reads($oats, 'L-A2', 10);
        $this->reads($oats, 'L-A3', 10);
        $this->reads($choco, 'L-B1', 19);
        $repeated = $this->reads($choco, 'L-B1', 0, reuse: 3);
        $this->reads($noStandard, 'L-C1', 2);
        $this->unknownOrder();
        $this->line("   74 cajas leídas, {$repeated} reenvíos duplicados (no suman), 3 lecturas rechazadas.");

        $this->at('07:10');
        $this->components->info('2. Cierre manual, paletizado y etiqueta (lectura del QR pegado)');
        $partial = $this->open($oats, 'L-A1');
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
        $a1 = $this->pallets($oats, 'L-A1');
        $a2 = $this->pallets($oats, 'L-A2');
        $a3 = $this->pallets($oats, 'L-A3');
        $b1 = $this->pallets($choco, 'L-B1')->where('physical_status', PhysicalStatus::Labeled);
        $receipts = app(RequestProductionReceipts::class);
        $receipts->handle($people['supervisor'], $this->ids($a1), false, $this->web($people['supervisor']));
        $receipts->handle($people['supervisor'], $this->ids($b1), false, $this->web($people['supervisor']));
        $receipts->handle($people['supervisor'], $this->ids($a2), false, $this->web($people['supervisor']));
        $this->tick(600);
        $sap->queueReceiptScenarios(FakeScenario::CreateThenTimeout);
        $receipts->handle($people['supervisor'], $this->ids($a3), false, $this->web($people['supervisor']));
        $this->line('   L-A1 agrupado, L-A2 y L-B1 confirmados; L-A3 queda pendiente de conciliación (respuesta perdida).');

        $this->at('13:00');
        $this->components->info('4. Conteo ciego en el CEDI (lectura del QR)');
        [$p1, $p2, $p3, $manual] = $a1->values()->all();
        $this->count($people['cedi'], $p1, $p1->boxes_count);
        $this->count($people['cedi'], $p2, $p2->boxes_count);
        $this->count($people['cedi'], $manual, $manual->boxes_count);
        $this->count($people['cedi2'], $p3, $p3->boxes_count - 1);
        $this->count($people['cedi2'], $b1->first(), $b1->first()->boxes_count);
        $this->line("   4 aprobadas, 1 discrepancia en {$p3->pallet_code} (se contó una caja menos).");

        $this->at('15:00');
        $this->components->info('5. Traslados de 04 a 05');
        $transfers = app(RequestInventoryTransfers::class);
        $transfers->handle($people['supervisor'], [$p1->id], TransferMode::Individual, false, $this->web($people['supervisor']));
        $transfers->handle($people['supervisor'], [$p2->id, $manual->id], TransferMode::Grouped, true, $this->web($people['supervisor']));
        $this->tick(300);
        $sap->queueTransferScenarios(FakeScenario::TimeoutWithoutCreating);
        $transfers->handle($people['supervisor'], [$b1->first()->id], TransferMode::Individual, false, $this->web($people['supervisor']));
        $this->line('   Uno individual y uno agrupado confirmados; el de L-B1 queda pendiente (timeout sin crear).');

        $ready = $a2->first();

        $this->newLine();
        $this->components->info('Listo. Para mirar:');
        $this->table(['Pantalla', 'Ruta', 'Usuario'], [
            ['Tablero e historia de cada estiba', '/pallets', 'supervisor@example.com'],
            ['Estación de producción', '/production', 'produccion@example.com'],
            ['Lecturas rechazadas', '/pallets/rejected-scans', 'supervisor@example.com'],
            ['Recibos (con uno pendiente)', '/receipts', 'supervisor@example.com'],
            ['Conteo ciego', '/cedi/count', 'cedi@example.com'],
            ['Discrepancias', '/discrepancies', 'supervisor@example.com'],
            ['Traslados (con uno pendiente)', '/transfers', 'supervisor@example.com'],
            ['Estado operativo', '/operations', 'supervisor@example.com'],
        ]);
        $this->line('Contraseña de todos: '.self::PASSWORD.'. El administrador sigue siendo admin@example.com.');
        $this->newLine();
        $this->line("Para probar el conteo ciego a mano, {$ready->pallet_code} está recibida y sin contar.");
        $this->line('Entrar como cedi@example.com en /cedi/count y pegar lo que leería el escáner:');
        $this->line('   '.PalletQr::payload($ready));
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
     * @return array{ProductionOrder, ProductionOrder, ProductionOrder}
     */
    private function masters(): array
    {
        $order = function (string $code, string $description, string $ref, ?int $boxes, ?string $units): ProductionOrder {
            $item = (new Item)->forceFill(['code' => $code, 'description' => $description, 'inventory_uom' => 'UND', 'active' => true, 'synced_at' => now()]);
            $item->save();

            if ($boxes !== null) {
                (new PalletStandard)->forceFill(['item_id' => $item->id, 'version' => 1, 'boxes_per_pallet' => $boxes, 'sap_source_key' => 'DEMO', 'status' => MasterStatus::Current, 'synced_at' => now()])->save();
            }

            if ($units !== null) {
                (new BoxConversionFactor)->forceFill(['item_id' => $item->id, 'version' => 1, 'units_per_box' => $units, 'sap_uom_code' => 'UND', 'sap_source_key' => 'DEMO', 'status' => MasterStatus::Current, 'synced_at' => now()])->save();
            }

            $order = (new ProductionOrder)->forceFill(['ref' => $ref, 'item_id' => $item->id, 'is_open' => true, 'synced_at' => now()]);
            $order->save();

            return $order;
        };

        return [
            $order('PT-DEMO-01', 'Galleta de avena caja x12', 'OP-DEMO-1001', 10, '12'),
            $order('PT-DEMO-02', 'Galleta de chocolate caja x24', 'OP-DEMO-1002', 8, '24'),
            $order('PT-DEMO-03', 'Galleta sin estándar cargado', 'OP-DEMO-1003', null, null),
        ];
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
        app(ProcessScanEvent::class)->handle($this->sensor, $this->read('OP-NO-EXISTE', 'PT-DEMO-01', 'L-X', $this->event), TraceContext::forDevice($this->sensor, (string) Str::uuid()));
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
        app(SubmitBlindCount::class)->handle($counter, $view->auditId, $boxes, $this->web($counter));
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
