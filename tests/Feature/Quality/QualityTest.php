<?php

namespace Tests\Feature\Quality;

use App\Domain\Audits\Data\BlindCountOutcome;
use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\PalletQr;
use App\Domain\Quality\Actions\HoldPallet;
use App\Domain\Quality\Actions\ReleasePallet;
use App\Domain\Quality\Actions\SendToNonConforming;
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
 * Quality before the blind count: hold a labeled pallet, release it, or send
 * it from 04 to the non-conforming warehouse (11C) with a SAP transfer that
 * is verified like any other. A held pallet is neither counted nor sent to 05.
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

    private function refused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('Debía rechazarse: '.$message);
        } catch (QualityRuleViolation|AuditRuleViolation|TransferRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_held_pallet_is_neither_counted_nor_sent_to_05(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();

        $hold = $this->hold($pallet);

        $this->assertSame(QualityStatus::Held, $pallet->refresh()->quality_status);
        $this->assertSame([QualityHoldStatus::Open, 'Cajas golpeadas', $this->quality->id], [$hold->status, $hold->reason, $hold->held_by]);
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::QualityHeld->value)->where('subject_id', $pallet->id)->count());

        $this->refused(fn () => $this->countPallet($pallet), 'no se cuenta hasta que calidad la libere');
        $this->assertSame(AuditStatus::Pending, $pallet->refresh()->audit_status);
    }

    public function test_a_released_pallet_goes_on_to_the_count_and_to_05(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();
        $this->hold($pallet);

        $hold = app(ReleasePallet::class)->handle($this->quality, $pallet->id, 'Revisada, está bien', $this->ctx());

        $this->assertSame([QualityHoldStatus::Released, $this->quality->id, 'Revisada, está bien'], [$hold->status, $hold->resolved_by, $hold->resolution_notes]);
        $this->assertSame(QualityStatus::Released, $pallet->refresh()->quality_status);
        $this->assertSame(BlindCountOutcome::Approved, $this->countPallet($pallet));
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);
    }

    public function test_a_held_pallet_goes_from_04_to_11c_with_what_sap_received(): void
    {
        $pallet = $this->receivedPallets(['L-1'], unitsPerBox: '12')->sole();
        $this->hold($pallet);

        $transfer = app(SendToNonConforming::class)->handle($this->quality, $pallet->id, 'Producto fuera de especificación', $this->ctx());

        $transfer->refresh();
        $this->assertSame([TransferPurpose::NonConforming, '04', '11C', SapDocumentStatus::Confirmed], [$transfer->purpose, $transfer->from_warehouse, $transfer->to_warehouse, $transfer->status]);
        $this->assertSame((string) ($pallet->boxes_count * 12), $transfer->payload['lines'][0]['quantity']);
        $this->assertSame('11C', $transfer->payload['lines'][0]['warehouse']);
        $this->assertNotNull($transfer->sap_doc_num);

        // The pallet is in 11C; the transfer to the CEDI never happened.
        $pallet->refresh();
        $this->assertSame([QualityStatus::NonConforming, TransferStatus::NotEligible], [$pallet->quality_status, $pallet->transfer_status]);

        $hold = QualityHold::sole();
        $this->assertSame([QualityHoldStatus::NonConforming, $this->quality->id, $transfer->id, 'Producto fuera de especificación'], [$hold->status, $hold->resolved_by, $hold->inventory_transfer_id, $hold->resolution_notes]);
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::NonConformingConfirmed->value)->where('subject_id', $pallet->id)->count());

        $sent = collect($this->sap()->transfers())->sole();
        $this->assertSame(['04', '11C'], [$sent->fromWarehouse, $sent->lines[0]->warehouse]);

        // In 11C it is out of the flow: no count, no release, no second hold.
        $this->refused(fn () => $this->countPallet($pallet), 'En no conforme');
        $this->refused(fn () => app(ReleasePallet::class)->handle($this->quality, $pallet->id, 'x', $this->ctx()), 'solo se libera');
        $this->refused(fn () => $this->hold($pallet), 'no se puede retener');
    }

    public function test_when_sap_refuses_the_transfer_the_pallet_stays_held(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();
        $this->hold($pallet);
        $this->sap()->queueTransferScenarios(FakeScenario::Reject);

        $transfer = app(SendToNonConforming::class)->handle($this->quality, $pallet->id, 'No conforme', $this->ctx());

        $this->assertSame(SapDocumentStatus::Failed, $transfer->refresh()->status);
        $this->assertSame(QualityStatus::Held, $pallet->refresh()->quality_status);
        $this->assertSame(QualityHoldStatus::Open, QualityHold::sole()->status);

        // Still held: it can be released, or sent again.
        $again = app(SendToNonConforming::class)->handle($this->quality, $pallet->id, 'Otra vez', $this->ctx());
        $this->assertSame(SapDocumentStatus::Confirmed, $again->refresh()->status);
        $this->assertSame(QualityStatus::NonConforming, $pallet->refresh()->quality_status);
    }

    public function test_a_transfer_without_an_answer_is_reconciled_by_its_own_lifecycle(): void
    {
        $pallet = $this->receivedPallets(['L-1'])->sole();
        $this->hold($pallet);
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout);

        $transfer = app(SendToNonConforming::class)->handle($this->quality, $pallet->id, 'No conforme', $this->ctx());
        $this->assertSame(QualityStatus::NonConformingPending, $pallet->refresh()->quality_status);

        ReconcileInventoryTransfer::dispatchSync($transfer->id);

        $this->assertSame(SapDocumentStatus::Confirmed, $transfer->refresh()->status);
        $this->assertSame(QualityStatus::NonConforming, $pallet->refresh()->quality_status);
        $this->assertSame(TransferStatus::NotEligible, $pallet->transfer_status);
    }

    public function test_quality_acts_after_the_label_and_before_the_count(): void
    {
        // Labeled but without a receipt yet: it can be held, but not moved (it is not in 04).
        $labeled = $this->labeledPallets(['L-1'])->sole();
        $this->hold($labeled);
        $this->refused(fn () => app(SendToNonConforming::class)->handle($this->quality, $labeled->id, 'x', $this->ctx()), 'no tiene recibo de producción confirmado');

        $notLabeled = Pallet::factory()->palletized()->create();
        $this->refused(fn () => $this->hold($notLabeled), 'todavía no tiene la etiqueta confirmada');

        $counted = $this->auditedPallets(['L-2'])->sole();
        $this->refused(fn () => $this->hold($counted), 'ya pasó el conteo ciego');

        $this->assertSame(0, InventoryTransfer::query()->where('purpose', TransferPurpose::NonConforming)->count());
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

    public function test_the_page_reads_the_qr_shows_boxes_and_units_and_holds(): void
    {
        $pallet = $this->receivedPallets(['L-1'], unitsPerBox: '12')->sole();
        $units = Quantity::ofBoxes($pallet->boxes_count, '12')->display();

        $page = Livewire::actingAs($this->quality)->test('pages::quality.index')
            ->call('scanFrom', 'basura', (string) Str::uuid())
            ->assertSee('no es el QR de una estiba')
            ->call('scanFrom', PalletQr::payload($pallet), (string) Str::uuid())
            ->assertSee($pallet->pallet_code)
            ->assertSee((string) $pallet->boxes_count)
            ->assertSee($units)
            ->assertSee('12 por caja, según el recibo en SAP')
            ->assertSee('Sin novedad')
            ->call('hold')->assertHasErrors(['reason' => 'required'])
            ->set('reason', 'Empaque roto')->call('hold')->assertHasNoErrors()
            ->assertSee('Retenida por calidad')
            ->assertSee('Empaque roto')
            ->assertSee('Enviar a no conforme (11C)');

        $this->assertSame(QualityStatus::Held, $pallet->refresh()->quality_status);

        $page->set('reason', 'No conforme')->call('sendToNonConforming')->assertHasNoErrors()
            ->assertSee('En no conforme')
            ->assertSee('04 → 11C');
    }
}
