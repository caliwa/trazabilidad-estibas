<?php

namespace Tests\Feature\Pages;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Integration\SapWait;
use App\Domain\Pallets\PalletQr;
use App\Enums\Role;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\ProductionReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\BuildsReceipts;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * After sending documents to SAP the page shows "Esperando respuesta de
 * SAP" over everything and cannot be used until SAP answered each one, or
 * SapWait::TIMEOUT_SECONDS passed.
 */
class SapWaitTest extends TestCase
{
    use BuildsReceipts, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    /** Moves a document to a state as the lifecycle would. */
    private function set(ProductionReceipt|InventoryTransfer $document, array $values): void
    {
        DB::table($document->getTable())->where('id', $document->id)->update($values);
    }

    public function test_the_receipt_waits_on_screen_until_sap_answers(): void
    {
        Queue::fake();
        $pallets = $this->labeledPallets(['L-1']);

        $page = Livewire::actingAs($this->admin)->test('pages::receipts.index')
            ->set('orderId', $pallets[0]->production_order_id)->call('selectAll')->call('request')
            ->assertSeeHtml('data-sap-wait="waiting"')
            ->assertSee('Esperando respuesta de SAP')
            ->assertSee('En cola para SAP');
        $receipt = ProductionReceipt::query()->sole();

        // Nothing goes on while SAP has not answered.
        $page->call('closeSapWait')->assertSeeHtml('data-sap-wait="waiting"');

        $this->set($receipt, ['status' => SapDocumentStatus::Sending->value, 'sent_at' => now()]);
        $page->call('checkSapWait')->assertSee('Enviando a SAP');

        $this->set($receipt, ['status' => SapDocumentStatus::Confirmed->value, 'sap_doc_entry' => 9001, 'sap_doc_num' => 5001, 'confirmed_at' => now()]);
        $page->call('checkSapWait')
            ->assertSeeHtml('data-sap-wait="ok"')
            ->assertSee('SAP confirmó')
            ->assertSee('Confirmado en SAP · Doc. 5001')
            ->call('closeSapWait')
            ->assertDontSeeHtml('data-sap-wait=')
            ->assertSet('sapWait', null);
    }

    public function test_a_refusal_or_no_answer_is_said_and_then_the_page_goes_on(): void
    {
        Queue::fake();
        $pallets = $this->labeledPallets(['L-1', 'L-2']);

        $page = Livewire::actingAs($this->admin)->test('pages::receipts.index')
            ->set('orderId', $pallets[0]->production_order_id)->call('selectAll')->set('documents', 'per_pallet')->call('request');
        [$first, $second] = ProductionReceipt::query()->orderBy('id')->get()->all();

        $this->set($first, ['status' => SapDocumentStatus::Sending->value, 'sent_at' => now()]);
        $this->set($first, ['status' => SapDocumentStatus::Failed->value, 'failed_at' => now(), 'last_error_code' => '-10', 'last_error_message' => 'Quantity exceeds planned quantity']);
        $page->call('checkSapWait')->assertSeeHtml('data-sap-wait="waiting"')->assertSee('SAP lo rechazó: Quantity exceeds planned quantity');

        // The worker or SAP down: after the limit the wait gives up and says so.
        $this->travel(SapWait::TIMEOUT_SECONDS + 1)->seconds();
        $page->call('checkSapWait')->assertSeeHtml('data-sap-wait="timeout"')->assertSee('Sin respuesta de SAP')
            ->call('closeSapWait')->assertSet('sapWait', null);

        $this->set($second, ['status' => SapDocumentStatus::Sending->value, 'sent_at' => now()]);
        $this->set($second, ['status' => SapDocumentStatus::PendingReconciliation->value]);
        $this->assertSame('pending', SapWait::status(SapWait::start('x', [['type' => 'receipt', 'id' => $second->id]]))['rows'][0]['state']);
    }

    public function test_reconciling_waits_for_the_check_made_after_pressing_the_button(): void
    {
        Queue::fake();
        $receipt = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']))[0];
        $this->set($receipt, ['status' => SapDocumentStatus::Sending->value, 'sent_at' => now()]);
        $this->set($receipt, ['status' => SapDocumentStatus::PendingReconciliation->value, 'reconciliation' => json_encode(['last_checked_at' => now()->subMinutes(5)->toIso8601String(), 'last_result' => 'consulta_fallida'])]);

        $page = Livewire::actingAs($this->admin)->test('pages::receipts.index')
            ->call('reconcile', $receipt->id)
            ->assertSeeHtml('data-sap-wait="waiting"')
            ->assertSee('Consultando SAP');

        $this->set($receipt, ['reconciliation' => json_encode(['last_checked_at' => now()->addSecond()->toIso8601String(), 'last_result' => 'no_existe_en_espera'])]);
        $page->call('checkSapWait')->assertSeeHtml('data-sap-wait="answered"')->assertSee('Todavía no aparece en SAP');
    }

    public function test_an_approved_blind_count_waits_for_its_transfer(): void
    {
        Queue::fake();
        $operator = $this->userWithRole(Role::LogisticsOperator);
        $pallet = Pallet::factory()->receivedInSap()->create(['standard_boxes_snapshot' => 12]);

        Livewire::actingAs($operator)->test('pages::cedi.count')
            ->set('scanned', PalletQr::payload($pallet))->call('scan')
            ->set('counted', 12)->call('submit')
            ->assertSeeHtml('data-sap-wait="waiting"')
            ->assertSee('Traslado de la estiba contada')
            ->assertSee($pallet->pallet_code);

        $this->assertSame(1, InventoryTransfer::query()->count());
    }

    public function test_a_wrong_count_sends_nothing_and_does_not_wait(): void
    {
        Queue::fake();
        $operator = $this->userWithRole(Role::LogisticsOperator);
        $pallet = Pallet::factory()->receivedInSap()->create(['standard_boxes_snapshot' => 12]);

        Livewire::actingAs($operator)->test('pages::cedi.count')
            ->set('scanned', PalletQr::payload($pallet))->call('scan')
            ->set('counted', 3)->call('submit')
            ->assertDontSeeHtml('data-sap-wait=');
    }

    public function test_holding_in_quality_waits_for_the_move_to_non_conforming(): void
    {
        Queue::fake();
        $quality = $this->userWithRole(Role::Quality);
        $pallet = Pallet::factory()->receivedInSap()->create();

        Livewire::actingAs($quality)->test('pages::quality.index')
            ->call('scanFrom', PalletQr::payload($pallet), 'scan-1')
            ->set('reason', 'Sello defectuoso')
            ->call('hold')
            ->assertSeeHtml('data-sap-wait="waiting"')
            ->assertSee('Traslado a no conforme')
            ->assertSee($pallet->pallet_code);
    }

    public function test_the_page_without_documents_sent_never_shows_the_wait(): void
    {
        $this->actingAs($this->admin)->get(route('receipts.index'))->assertOk()->assertDontSee('data-sap-wait', false);
    }
}
