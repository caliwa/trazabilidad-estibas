<?php

namespace Tests\Feature\Pages;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Role;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Jobs\ReconcileInventoryTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\BuildsTransfers;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Without a transfers screen, a transfer that needs a person is handled
 * from the pallet detail: retry after a rejection, reconcile, or close as
 * failed with proof. Only with transfers.reconcile.
 */
class PalletTransferActionsTest extends TestCase
{
    use BuildsTransfers, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    public function test_the_transfers_screen_no_longer_exists(): void
    {
        $this->actingAs($this->admin)->get('/transfers')->assertNotFound();
        $this->actingAs($this->admin)->get(route('pallets.index'))->assertOk()->assertDontSee('Traslados 04 → 05');
    }

    public function test_a_rejected_transfer_is_retried_from_the_pallet(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::Reject);
        [$pallet] = $this->auditedPallets(['L-1'])->all();

        Livewire::actingAs($this->admin)->test('pages::pallets.show', ['pallet' => $pallet])
            ->assertSee('La transferencia 04 → 05 falló en SAP.')
            ->call('retryTransfer')
            ->assertSee('Trasladado a 05');

        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);
    }

    public function test_a_pending_transfer_is_reconciled_or_closed_from_the_pallet(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout);
        [$pallet] = $this->auditedPallets(['L-1'])->all();
        $transfer = $this->transferOf($pallet);
        $this->travel(30)->minutes();
        Queue::fake();

        Livewire::actingAs($this->admin)->test('pages::pallets.show', ['pallet' => $pallet])
            ->assertSee('pendiente de conciliación')
            ->call('reconcileTransfer')
            ->call('failTransfer')->assertHasErrors(['failReason' => 'required'])
            ->set('failReason', 'No aparece')->call('failTransfer');

        Queue::assertPushed(ReconcileInventoryTransfer::class, fn ($job) => $job->transferId === $transfer->id);
        // The document does exist in SAP: closing it as failed is refused.
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->refresh()->status);
    }

    public function test_the_actions_need_transfers_reconcile(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::Reject);
        [$pallet] = $this->auditedPallets(['L-1'])->all();
        $viewer = $this->userWithRole(Role::Viewer);

        Livewire::actingAs($viewer)->test('pages::pallets.show', ['pallet' => $pallet])
            ->assertDontSee('Solicitar transferencia')
            ->call('retryTransfer')->assertForbidden();

        $this->assertSame(TransferStatus::Failed, $pallet->refresh()->transfer_status);
    }
}
