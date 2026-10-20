<?php

namespace Tests\Feature\Operations;

use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Operations\Alert;
use App\Domain\Operations\AlertLevel;
use App\Domain\Operations\Heartbeat;
use App\Domain\Operations\OperationalChecks;
use App\Domain\Pallets\PalletQr;
use App\Domain\Receipts\ProductionReceiptLifecycle;
use App\Enums\Role;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Jobs\RecordWorkerHeartbeat;
use App\Models\Device;
use App\Models\Item;
use App\Models\PalletScanEvent;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Notifications\OperationalAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsProductionMasters;
use Tests\Concerns\BuildsTransfers;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Section 13 alerts: rejected events, abnormal duplicates, missing
 * standards, SAP errors, pending reconciliations, open discrepancies,
 * queue backlog and worker failures.
 */
class OperationalChecksTest extends TestCase
{
    use BuildsProductionMasters, BuildsTransfers, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
        $this->allProcessesAlive();
    }

    private function allProcessesAlive(): void
    {
        Heartbeat::record(Heartbeat::SCHEDULER);
        foreach (config()->array('operations.worker_queues') as $queue) {
            RecordWorkerHeartbeat::dispatchSync($queue);
        }
    }

    /**
     * @return array<string, Alert>
     */
    private function alerts(): array
    {
        return collect(app(OperationalChecks::class)->run())->keyBy('key')->all();
    }

    public function test_a_quiet_plant_has_no_alerts(): void
    {
        $this->assertSame([], $this->alerts());
    }

    public function test_a_dead_scheduler_or_worker_is_critical(): void
    {
        $this->travel(config()->integer('operations.heartbeat_max_age_minutes') + 1)->minutes();
        RecordWorkerHeartbeat::dispatchSync('sap');

        $alerts = $this->alerts();

        $this->assertSame(AlertLevel::Critical, $alerts['scheduler']->level);
        $this->assertSame(AlertLevel::Critical, $alerts['worker:default']->level);
        $this->assertArrayNotHasKey('worker:sap', $alerts);
    }

    public function test_the_heartbeat_job_runs_on_the_queue_it_watches(): void
    {
        Queue::fake();
        RecordWorkerHeartbeat::dispatch('sap');

        Queue::assertPushedOn('sap', RecordWorkerHeartbeat::class);
    }

    public function test_rejected_scans_above_the_threshold_are_reported(): void
    {
        config(['operations.rejected_scans.threshold' => 3]);
        PalletScanEvent::factory()->count(2)->create(['rejection_reason' => 'LOTE_INVALIDO']);
        $this->assertArrayNotHasKey('rejected_scans', $this->alerts());

        PalletScanEvent::factory()->create(['rejection_reason' => 'LOTE_INVALIDO']);
        $this->assertSame(3, $this->alerts()['rejected_scans']->count);

        $this->travel(config()->integer('operations.rejected_scans.window') + 1)->minutes();
        $this->allProcessesAlive();
        $this->assertArrayNotHasKey('rejected_scans', $this->alerts());
    }

    public function test_abnormal_duplicates_are_reported(): void
    {
        config(['operations.duplicate_scans.threshold' => 3]);
        $this->enableScanIngestion();
        $device = Device::factory()->create();
        $scan = $this->scan($this->orderWithStandard(boxes: 10), eventId: 'E-1');

        foreach (range(1, 4) as $i) {
            $this->process($device, $scan);
        }

        $this->assertSame(3, $this->alerts()['duplicate_scans']->count);
    }

    public function test_missing_standards_are_critical_and_open_orders_without_one_are_flagged(): void
    {
        PalletScanEvent::factory()->create(['rejection_reason' => 'ESTANDAR_AUSENTE']);
        ProductionOrder::factory()->for(Item::factory())->create();

        $alerts = $this->alerts();

        $this->assertSame(AlertLevel::Critical, $alerts['missing_standard']->level);
        $this->assertSame(1, $alerts['orders_without_standard']->count);
    }

    public function test_sap_errors_pending_reconciliations_and_mismatches_are_reported(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating, FakeScenario::CreateThenTimeout);
        $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));
        [$altered] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-2']));
        $this->assertArrayHasKey('sap_errors', $this->alerts());

        $this->sap()->alterReceipt($altered->request_uuid, fn (SapDocument $d) => new SapDocument($d->docEntry, $d->docNum, $d->correlationId, [], $d->productionOrderRef));
        $this->travel(config()->integer('sap.reconciliation.check_every_minutes') + 1)->minutes();
        app(ProductionReceiptLifecycle::class)->reconcile($altered->id);

        $this->travel(config()->integer('operations.pending_reconciliation_minutes'))->minutes();
        $this->allProcessesAlive();
        $alerts = $this->alerts();

        $this->assertSame(2, $alerts['pending_reconciliation']->count);
        $this->assertSame(AlertLevel::Critical, $alerts['sap_mismatch']->level);
        $this->assertSame(1, $alerts['sap_mismatch']->count);
    }

    public function test_open_discrepancies_are_reported_without_quantities(): void
    {
        $counter = $this->userWithRole(Role::LogisticsOperator);
        [$pallet] = $this->labeledPallets(['L-1'])->all();
        $this->requestReceipts($this->admin, [$pallet]);
        $view = app(StartBlindCount::class)->handle($counter, PalletQr::payload($pallet->refresh()), $this->transferCtx($counter));
        app(SubmitBlindCount::class)->handle($counter, $view->auditId, $pallet->boxes_count - 1, $this->transferCtx($counter));

        $alert = $this->alerts()['open_discrepancies'];

        $this->assertSame(1, $alert->count);
        $this->assertStringNotContainsString((string) $pallet->boxes_count, $alert->title.$alert->detail);
    }

    public function test_queue_backlog_and_failed_jobs_are_reported(): void
    {
        DB::table('jobs')->insert(['queue' => 'sap', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(config()->integer('operations.queue.max_wait_minutes') + 1)->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'sap', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $alerts = $this->alerts();

        $this->assertSame(AlertLevel::Critical, $alerts['queue_backlog']->level);
        $this->assertSame(1, $alerts['failed_jobs']->count);
    }

    public function test_the_check_notifies_ops_people_once_per_cooldown_and_never_the_operators(): void
    {
        Notification::fake();
        $operator = $this->userWithRole(Role::LogisticsOperator);
        PalletScanEvent::factory()->create(['rejection_reason' => 'ESTANDAR_AUSENTE']);

        Artisan::call('ops:check');
        Artisan::call('ops:check');

        Notification::assertSentToTimes($this->admin, OperationalAlert::class, 1);
        Notification::assertNothingSentTo($operator);
        $this->assertStringContainsString('estándar', Artisan::output());

        $this->travel(config()->integer('operations.notify_cooldown_minutes') + 1)->minutes();
        $this->allProcessesAlive();
        PalletScanEvent::factory()->create(['rejection_reason' => 'ESTANDAR_AUSENTE']);
        Artisan::call('ops:check');

        Notification::assertSentToTimes($this->admin, OperationalAlert::class, 2);
    }

    public function test_only_ops_people_open_the_operations_page(): void
    {
        PalletScanEvent::factory()->create(['rejection_reason' => 'ESTANDAR_AUSENTE']);

        $this->actingAs($this->admin)->get(route('operations.index'))->assertOk()->assertSee('Lecturas rechazadas por estándar');
        Livewire::actingAs($this->admin)->test('pages::operations.index')->assertSee('Worker cola sap');

        foreach ([Role::ProductionOperator, Role::LogisticsOperator, Role::Viewer] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('operations.index'))->assertForbidden();
        }
    }
}
