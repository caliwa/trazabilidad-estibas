<?php

namespace Tests\Feature\Pages;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Role;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Jobs\ReconcileInventoryTransfer;
use App\Models\InventoryTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\BuildsTransfers;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class TransfersPageTest extends TestCase
{
    use BuildsTransfers, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    public function test_only_people_who_request_transfers_open_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('transfers.index'))->assertOk()->assertSee('Traslados 04 → 05');

        foreach ([Role::ProductionOperator, Role::LogisticsOperator, Role::Viewer] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('transfers.index'))->assertForbidden();
        }
    }

    public function test_it_lists_only_audited_pallets_and_requests_without_waiting_for_sap(): void
    {
        $audited = $this->auditedPallets(['L-1', 'L-2']);
        [$notAudited] = $this->labeledPallets(['L-9'])->all();
        Queue::fake();

        Livewire::actingAs($this->admin)->test('pages::transfers.index')
            ->assertSee($audited[0]->pallet_code)
            ->assertDontSee($notAudited->pallet_code)
            ->assertDontSee('Agrupar en un solo documento')
            ->set('selected', $audited->pluck('id')->all())
            ->call('request')
            ->assertSee('Solicitado');

        $this->assertSame(2, InventoryTransfer::where('status', SapDocumentStatus::Requested)->count());
        $this->assertSame(0, $this->sap()->transferWriteCalls());
        $this->assertTrue($audited->every(fn ($p) => $p->refresh()->transfer_status === TransferStatus::Requested));
    }

    public function test_grouping_shows_only_with_dp05_and_still_needs_the_confirmation(): void
    {
        config(['traceability.decisions.grouped_transfers_enabled' => true]);
        $pallets = $this->auditedPallets(['L-1', 'L-1']);

        $page = Livewire::actingAs($this->admin)->test('pages::transfers.index')
            ->assertSee('Agrupar en un solo documento')
            ->set('selected', $pallets->pluck('id')->all())
            ->set('grouped', true)
            ->call('request');
        $this->assertSame(0, InventoryTransfer::count());

        $page->set('groupingConfirmed', true)->call('request');
        $this->assertSame(1, InventoryTransfer::count());
    }

    public function test_pending_transfers_offer_reconciliation_only_with_its_permission(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::TimeoutWithoutCreating);
        [$transfer] = $this->requestTransfers($this->admin, $this->auditedPallets(['L-1']));
        Queue::fake();

        Livewire::actingAs($this->admin)->test('pages::transfers.index')
            ->assertSee('Pendiente de conciliación')
            ->call('reconcile', $transfer->id);
        Queue::assertPushed(ReconcileInventoryTransfer::class);

        $requester = $this->userWithRole(Role::Viewer);
        $requester->givePermissionTo('transfers.request');
        Livewire::actingAs($requester)->test('pages::transfers.index')->call('reconcile', $transfer->id)->assertForbidden();
    }

    public function test_marking_failed_from_the_page_needs_a_reason_and_proof(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout);
        [$transfer] = $this->requestTransfers($this->admin, $this->auditedPallets(['L-1']));
        $this->travel(30)->minutes();

        Livewire::actingAs($this->admin)->test('pages::transfers.index')
            ->call('askFail', $transfer->id)
            ->call('fail')->assertHasErrors(['failReason' => 'required'])
            ->set('failReason', 'No aparece')->call('fail');

        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->refresh()->status);
    }
}
