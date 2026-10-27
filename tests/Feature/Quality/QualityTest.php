<?php

namespace Tests\Feature\Quality;

use App\Domain\Audits\Data\BlindCountOutcome;
use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\PalletQr;
use App\Domain\Quality\Actions\HoldPallet;
use App\Domain\Quality\Actions\ReleasePallet;
use App\Domain\Quality\Enums\QualityHoldStatus;
use App\Domain\Quality\Enums\QualityStatus;
use App\Domain\Quality\Exceptions\QualityRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferPurpose;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Enums\Role;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Jobs\ReconcileInventoryTransfer;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\QualityHold;
use App\Models\TraceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsTransfers;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Quality before the blind count. Holding a pallet moves it from 04 to the
 * non-conforming warehouse (11C) with a SAP transfer verified like any
 * other; held means in 11C. Released, it goes on to the count, whose
 * transfer then leaves from 11C to 05. A held pallet is neither counted
 * nor sent to 05.
 */
class QualityTest extends TestCase
{
    use BuildsTransfers, CreatesUsers, RefreshDatabase;

    private User $quality;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->quality = $this->userWithRole(Role::Quality);
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->quality, Origin::Web, (string) Str::uuid());
    }

    private function hold(Pallet $pallet, string $reason = 'Cajas golpeadas'): QualityHold
    {
        return app(HoldPallet::class)->handle($this->quality, $pallet->id, $reason, $this->ctx());
    }

    private function release(Pallet $pallet, string $notes = 'Revisada, está conforme'): QualityHold
    {
        return app(ReleasePallet::class)->handle($this->quality, $pallet->id, $notes, $this->ctx());
    }

    private function refused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('Debía rechazarse: '.$message);
        } catch (QualityRuleViolation|AuditRuleViolation|TransferRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function transferOfPurpose(Pallet $pallet, TransferPurpose $purpose): InventoryTransfer
    {
        return InventoryTransfer::query()->where('purpose', $purpose)->whereHas('lines', fn ($q) => $q->where('pallet_id', $pallet->id))->latest('id')->firstOrFail();
    }

    public function test_holding_moves_the_pallet_from_04_to_11c_with_what_sap_received(): void
    {
        $pallet = $this->receivedPallets(['L-1'], unitsPerBox: '12')->sole();

        $hold = $this->hold($pallet);

        $transfer = $this->transferOfPurpose($pallet, TransferPurpose::NonConforming);
        $this->assertSame(['04', '11C', SapDocumentStatus::Confirmed], [$transfer->from_warehouse, $transfer->to_warehouse, $transfer->status]);
        $this->assertSame((string) ($pallet->boxes_count * 12), $transfer->payload['lines'][0]['quantity']);
        $this->assertNotNull($transfer->sap_doc_num);

        // Held means in 11C. The transfer to the CEDI is untouched.
        $pallet->refresh();
        $this->assertSame([QualityStatus::NonConforming, TransferStatus::NotEligible], [$pallet->quality_status, $pallet->transfer_status]);
        $hold->refresh();
        $this->assertSame([QualityHoldStatus::NonConforming, 'Cajas golpeadas', $this->quality->id, $transfer->id], [$hold->status, $hold->reason, $hold->held_by, $hold->inventory_transfer_id]);
        $this->assertSame(['04', '11C'], [collect($this->sap()->transfers())->sole()->fromWarehouse, collect($this->sap()->transfers())->sole()->lines[0]->warehouse]);
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::QualityHeld->value)->where('subject_id', $pallet->id)->count());
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::NonConformingConfirmed->value)->where('subject_id', $pallet->id)->count());

        // In 11C it is not counted.
        $this->refused(fn () => $this->countPallet($pallet), 'no se cuenta hasta que calidad la libere');
        $this->assertSame(AuditStatus::Pending, $pallet->refresh()->audit_status);
    }

    public function test_a_released_pallet_is_counted_and_goes_from_11c_to_05(): void
    {
        $pallet = $this->receivedPallets(['L-1'], unitsPerBox: '12')->sole();
        $this->hold($pallet);

        $hold = $this->release($pallet);

        // Releasing moves nothing in SAP: it waits in 11C for the count.
        $this->assertSame([QualityHoldStatus::Released, $this->quality->id, 'Revisada, está conforme'], [$hold->status, $hold->released_by, $hold->release_notes]);
        $this->assertSame(QualityStatus::Released, $pallet->refresh()->quality_status);
        $this->assertCount(1, $this->sap()->transfers());

        $this->assertSame(BlindCountOutcome::Approved, $this->countPallet($pallet));

        $toCedi = $this->transferOfPurpose($pallet, TransferPurpose::Cedi);
        $this->assertSame(['11C', '05', SapDocumentStatus::Confirmed], [$toCedi->from_warehouse, $toCedi->to_warehouse, $toCedi->status]);
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);
        $this->assertSame('11C', collect($this->sap()->transfers())->last()->fromWarehouse);
    }

    public function test_a_pallet_quality_never_touched_still_goes_from_04_to_05(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();

        $this->assertSame(BlindCountOutcome::Approved, $this->countPallet($pallet));

        $this->assertSame(['04', '05'], [$this->transferOfPurpose($pallet, TransferPurpose::Cedi)->from_warehouse, $this->transferOfPurpose($pallet, TransferPurpose::Cedi)->to_warehouse]);
    }

    public function test_holding_a_released_pallet_again_moves_nothing(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();
        $first = $this->hold($pallet);
        $this->release($pallet);

        $again = $this->hold($pallet, 'Apareció otra novedad');

        $this->assertSame([QualityHoldStatus::NonConforming, $first->refresh()->inventory_transfer_id], [$again->status, $again->inventory_transfer_id]);
        $this->assertSame(QualityStatus::NonConforming, $pallet->refresh()->quality_status);
        $this->assertCount(1, $this->sap()->transfers());
        $this->assertSame(1, InventoryTransfer::query()->where('purpose', TransferPurpose::NonConforming)->count());
    }

    public function test_when_sap_refuses_the_transfer_nothing_moved_and_it_can_be_held_again(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();
        $this->sap()->queueTransferScenarios(FakeScenario::Reject);

        $hold = $this->hold($pallet);

        $this->assertSame(QualityStatus::None, $pallet->refresh()->quality_status);
        $hold->refresh();
        $this->assertSame(QualityHoldStatus::Cancelled, $hold->status);
        $this->assertStringContainsString('SAP no hizo el traslado', (string) $hold->resolution_notes);
        $this->refused(fn () => $this->release($pallet), 'solo se libera una estiba retenida');

        $this->hold($pallet, 'Otra vez');
        $this->assertSame(QualityStatus::NonConforming, $pallet->refresh()->quality_status);
    }

    public function test_a_transfer_without_an_answer_is_reconciled_by_its_own_lifecycle(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout);

        $this->hold($pallet);
        $this->assertSame(QualityStatus::NonConformingPending, $pallet->refresh()->quality_status);
        $this->refused(fn () => $this->countPallet($pallet), 'no se cuenta');

        ReconcileInventoryTransfer::dispatchSync($this->transferOfPurpose($pallet, TransferPurpose::NonConforming)->id);

        $this->assertSame(QualityStatus::NonConforming, $pallet->refresh()->quality_status);
        $this->assertSame(TransferStatus::NotEligible, $pallet->transfer_status);
        $this->assertSame(QualityHoldStatus::NonConforming, QualityHold::sole()->status);
    }

    public function test_quality_acts_on_received_pallets_before_the_count(): void
    {
        $labeled = $this->labeledPallets(['L-1'])->sole();
        $this->refused(fn () => $this->hold($labeled), 'no tiene recibo de producción confirmado');

        $notLabeled = Pallet::factory()->palletized()->create();
        $this->refused(fn () => $this->hold($notLabeled), 'todavía no tiene la etiqueta confirmada');

        $counted = $this->auditedPallets(['L-2'])->sole();
        $this->refused(fn () => $this->hold($counted), 'ya pasó el conteo ciego');

        $this->assertSame(0, InventoryTransfer::query()->where('purpose', TransferPurpose::NonConforming)->count());
        $this->assertSame(0, QualityHold::count());
    }

    public function test_only_quality_holds_and_quality_never_counts(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();

        try {
            app(HoldPallet::class)->handle($this->userWithRole(Role::LogisticsOperator), $pallet->id, 'x', $this->ctx());
            $this->fail('Solo calidad retiene');
        } catch (AuthorizationException) {
        }

        $this->assertFalse($this->quality->can('audits.count'));
        $this->assertTrue($this->userWithRole(Role::Admin)->can('quality.manage'));
        $this->actingAs($this->userWithRole(Role::LogisticsOperator))->get(route('quality.index'))->assertForbidden();
    }

    public function test_the_page_reads_several_pallets_and_holds_and_releases_them_together(): void
    {
        [$a, $b] = $this->receivedPallets(['L-1', 'L-2'], unitsPerBox: '12')->all();
        $notReceived = $this->labeledPallets(['L-3'])->sole();
        $units = Quantity::ofBoxes($a->boxes_count, '12')->display();

        $page = Livewire::actingAs($this->quality)->test('pages::quality.index')
            ->call('scanFrom', 'basura', (string) Str::uuid())
            ->assertSee('no es el QR de una estiba')
            ->call('scanFrom', PalletQr::payload($a), (string) Str::uuid())
            ->call('scanFrom', PalletQr::payload($b), (string) Str::uuid())
            ->call('scanFrom', PalletQr::payload($a), (string) Str::uuid())
            ->call('scanFrom', PalletQr::payload($notReceived), (string) Str::uuid())
            ->assertSet('selected', [$a->id, $b->id, $notReceived->id])
            ->assertSee('Estibas leídas (3)')
            ->assertSee($units)
            ->assertSee('12 por caja, según el recibo')
            ->call('hold')->assertHasErrors(['reason' => 'required'])
            ->set('reason', 'Sello de calidad roto')->call('hold')->assertHasNoErrors()
            // Each on its own: the one without a receipt says why, the others are held.
            ->assertSee('no tiene recibo de producción confirmado')
            ->assertSee('Retenida por calidad (en no conforme)');

        $this->assertSame([QualityStatus::NonConforming, QualityStatus::NonConforming, QualityStatus::None], [$a->refresh()->quality_status, $b->refresh()->quality_status, $notReceived->refresh()->quality_status]);
        $this->assertSame(2, InventoryTransfer::query()->where('purpose', TransferPurpose::NonConforming)->count());

        $page->call('clear')->assertSet('selected', [])
            ->call('addAllHeld')->assertSet('selected', [$b->id, $a->id])
            ->set('reason', 'Revisadas, conformes')->call('release')->assertHasNoErrors()
            ->assertSee('Liberada: sigue al conteo ciego.');

        $this->assertSame([QualityStatus::Released, QualityStatus::Released], [$a->refresh()->quality_status, $b->refresh()->quality_status]);
    }
}
