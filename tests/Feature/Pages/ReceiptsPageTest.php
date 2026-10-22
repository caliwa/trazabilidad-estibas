<?php

namespace Tests\Feature\Pages;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Enums\Role;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Jobs\ReconcileProductionReceipt;
use App\Models\ProductionReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\BuildsReceipts;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ReceiptsPageTest extends TestCase
{
    use BuildsReceipts, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    public function test_only_people_who_request_receipts_open_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('receipts.index'))->assertOk();

        foreach ([Role::ProductionOperator, Role::LogisticsOperator, Role::Viewer] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('receipts.index'))->assertForbidden();
        }
    }

    public function test_choosing_an_order_lists_its_labeled_pallets_and_previews_the_sap_lines(): void
    {
        $pallets = $this->labeledPallets(['L-1', 'L-1'], unitsPerBox: '2.5');

        Livewire::actingAs($this->admin)->test('pages::receipts.index')
            ->assertSee($pallets[0]->production_order_ref)
            ->set('orderId', $pallets[0]->production_order_id)
            ->assertSee($pallets[0]->pallet_code)
            ->call('selectAll')
            ->assertSee('2 estiba(s), 80 cajas')
            ->assertSee('200');
    }

    public function test_the_order_list_starts_on_a_real_empty_option(): void
    {
        $pallets = $this->labeledPallets(['L-1']);

        // A disabled placeholder made the browser show the first order as chosen while none was:
        // picking it fired nothing and the button stayed off. The empty option must be selectable.
        $page = Livewire::actingAs($this->admin)->test('pages::receipts.index');
        $this->assertMatchesRegularExpression('/<option\s+value=""[^>]*>Elige una orden<\/option>/', $page->html());
        $this->assertStringNotContainsString('class="placeholder"', $page->html());

        $page->set('orderId', $pallets[0]->production_order_id)
            ->set('orderId', '')
            ->assertSet('orderId', null)
            ->assertSet('selected', []);
    }

    public function test_requesting_from_the_page_never_waits_for_sap_and_lists_the_result(): void
    {
        Queue::fake();
        $pallets = $this->labeledPallets(['L-1', 'L-2']);

        Livewire::actingAs($this->admin)->test('pages::receipts.index')
            ->set('orderId', $pallets[0]->production_order_id)
            ->call('selectAll')
            ->set('documents', 'per_pallet')
            ->call('request')
            ->assertSee('Solicitado')
            ->assertSet('orderId', null)
            ->assertDontSee('Esta orden no tiene estibas etiquetadas por recibir');

        $this->assertSame(2, ProductionReceipt::where('status', SapDocumentStatus::Requested)->count());
        $this->assertSame(0, $this->sap()->receiptWriteCalls());
    }

    public function test_by_default_the_page_requests_one_receipt_with_every_pallet(): void
    {
        Queue::fake();
        $pallets = $this->labeledPallets(['L-1', 'L-2']);

        Livewire::actingAs($this->admin)->test('pages::receipts.index')
            ->assertSet('documents', 'grouped')
            ->set('orderId', $pallets[0]->production_order_id)
            ->call('selectAll')
            ->call('request');

        $this->assertSame(1, ProductionReceipt::count());
    }

    public function test_pending_receipts_offer_reconciliation_only_with_its_permission(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating);
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));
        Queue::fake();

        Livewire::actingAs($this->admin)->test('pages::receipts.index')
            ->assertSee('Pendiente de conciliación')
            ->call('reconcile', $receipt->id);
        Queue::assertPushed(ReconcileProductionReceipt::class);

        $requester = $this->userWithRole(Role::Viewer);
        $requester->givePermissionTo('receipts.request');
        Livewire::actingAs($requester)->test('pages::receipts.index')->call('reconcile', $receipt->id)->assertForbidden();
    }

    public function test_marking_failed_from_the_page_needs_a_reason_and_proof(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::CreateThenTimeout);
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));
        $this->travel(30)->minutes();

        Livewire::actingAs($this->admin)->test('pages::receipts.index')
            ->call('askFail', $receipt->id)
            ->call('fail')->assertHasErrors(['failReason' => 'required'])
            ->set('failReason', 'No aparece')->call('fail');

        // The document does exist in SAP: refused.
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->refresh()->status);
    }
}
