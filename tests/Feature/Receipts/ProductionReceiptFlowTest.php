<?php

namespace Tests\Feature\Receipts;

use App\Domain\Integration\Enums\AttemptOutcome;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Receipts\Exceptions\ReceiptRuleViolation;
use App\Domain\Receipts\ProductionReceiptLifecycle;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Dto\SapDocumentLine;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabelBatch;
use App\Models\IntegrationAttempt;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsReceipts;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * RF-11, RF-12, RN-07 to RN-09 and the "Recibos SAP" scenarios: grouped and
 * separate receipts, conversion, reuse, verification before confirming, no
 * blind second create after a timeout, reconciliation and partial receipts.
 */
class ProductionReceiptFlowTest extends TestCase
{
    use BuildsReceipts, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    private function lifecycle(): ProductionReceiptLifecycle
    {
        return app(ProductionReceiptLifecycle::class);
    }

    public function test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references(): void
    {
        $pallets = $this->labeledPallets(['L-1', 'L-1', 'L-2'], unitsPerBox: '12');

        [$receipt] = $this->requestReceipts($this->admin, $pallets);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->status);
        $this->assertNotNull($receipt->sap_doc_entry);
        $this->assertNotNull($receipt->sap_doc_num);
        $this->assertSame(1, $this->sap()->receiptWriteCalls());
        $document = $this->sap()->receipts()[0];
        $this->assertSame($receipt->sap_doc_entry, $document->docEntry);
        $this->assertEqualsCanonicalizing(['PT|L-1|960|04', 'PT|L-2|480|04'], array_map(fn (SapDocumentLine $l) => 'PT|'.$l->lotCode.'|'.$l->quantity->value.'|'.$l->warehouse, $document->lines));

        foreach ($pallets as $pallet) {
            $this->assertSame(ReceiptStatus::Confirmed, $pallet->refresh()->receipt_status);
        }
        $lines = ProductionReceiptPallet::query()->where('production_receipt_id', $receipt->id)->get();
        $this->assertTrue($lines->every(fn ($l) => $l->hold_status === PalletHold::Confirmed && $l->sap_line_num !== null && $l->sap_quantity === '480.000000'));
        $this->assertSame(2, $lines->pluck('sap_line_num')->unique()->count());
    }

    public function test_each_batch_goes_with_the_expiration_of_its_box_labels_and_the_admission_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:30'));
        $order = ProductionOrder::factory()->create();
        BoxLabelBatch::factory()->create(['production_order_id' => $order->id, 'lot_code' => 'L-1', 'expiration_date' => '2028-10-20']);
        BoxLabelBatch::factory()->create(['production_order_id' => $order->id, 'lot_code' => 'L-2', 'expiration_date' => '2028-10-21']);

        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1', 'L-1', 'L-2'], order: $order));

        $this->assertSame('2026-10-21', $receipt->payload['posting_date']);
        $byLot = collect($receipt->payload['lines'])->keyBy('lot_code');
        $this->assertSame(['2028-10-20', '2026-10-21'], [$byLot['L-1']['expiration_date'], $byLot['L-1']['manufacturing_date']]);
        $this->assertSame(['2028-10-21', '2026-10-21'], [$byLot['L-2']['expiration_date'], $byLot['L-2']['manufacturing_date']]);

        // What reached SAP: one batch per lot with its dates, posted on the admission day.
        $document = $this->sap()->receipts()[0];
        $this->assertSame('2026-10-21', $document->postingDate?->toDateString());
        $dates = collect($document->lines)->mapWithKeys(fn (SapDocumentLine $l) => [$l->lotCode => [$l->expirationDate?->toDateString(), $l->manufacturingDate?->toDateString()]])->all();
        $this->assertEquals(['L-1' => ['2028-10-20', '2026-10-21'], 'L-2' => ['2028-10-21', '2026-10-21']], $dates);
        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->refresh()->status);
    }

    public function test_a_lot_without_box_labels_is_refused_and_nothing_is_created(): void
    {
        $order = ProductionOrder::factory()->create();
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id]);
        $labeled = $this->labeledPallets(['L-1'], order: $order);
        $orphan = Pallet::factory()->labeled()->create(['production_order_id' => $order->id, 'lot_code' => 'L-SIN-ETIQUETA', 'pallet_standard_id' => $labeled[0]->pallet_standard_id]);

        try {
            $this->requestReceipts($this->admin, [$labeled[0], $orphan]);
            $this->fail('Un lote sin etiquetas de caja no puede ir a SAP.');
        } catch (ReceiptRuleViolation $e) {
            $this->assertStringContainsString('El lote L-SIN-ETIQUETA no tiene etiquetas de caja', $e->getMessage());
        }

        $this->assertSame(0, ProductionReceipt::count());
        $this->assertSame(ReceiptStatus::None, $labeled[0]->refresh()->receipt_status);
        $this->assertSame(0, $this->sap()->receiptWriteCalls());
    }

    public function test_a_resend_after_midnight_keeps_the_admission_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 23:55'));
        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating);
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));

        $this->travel(config()->integer('sap.reconciliation.resend_after_minutes') + 1)->minutes();
        $this->lifecycle()->reconcile($receipt->id);

        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->refresh()->status);
        $this->assertSame(2, $this->sap()->receiptWriteCalls());
        $this->assertSame('2026-10-21', $this->sap()->receipts()[0]->postingDate?->toDateString());
        $this->assertSame('2026-10-21', $this->sap()->receipts()[0]->lines[0]->manufacturingDate?->toDateString());
    }

    public function test_a_batch_with_other_dates_in_sap_is_never_confirmed(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::CreateThenTimeout);
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));
        $this->sap()->alterReceipt($receipt->request_uuid, fn (SapDocument $d) => new SapDocument($d->docEntry, $d->docNum, $d->correlationId, [
            new SapDocumentLine(0, $d->lines[0]->itemCode, $d->lines[0]->lotCode, $d->lines[0]->quantity, $d->lines[0]->warehouse, CarbonImmutable::parse('2027-01-01'), $d->lines[0]->manufacturingDate),
        ], $d->productionOrderRef, postingDate: $d->postingDate));

        $this->travel(60)->minutes();
        $this->lifecycle()->reconcile($receipt->id);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->status);
        $this->assertTrue($receipt->reconciliation['mismatch']);
        $this->assertCount(1, $receipt->reconciliation['differences']);
        $this->assertStringContainsString('Fecha de vencimiento distinta', $receipt->reconciliation['differences'][0]);
        $this->assertStringContainsString('pedida 2028-10-20, en SAP 2027-01-01', $receipt->reconciliation['differences'][0]);
        $this->assertSame(1, $this->sap()->receiptWriteCalls());
    }

    public function test_three_pallets_in_separate_receipts(): void
    {
        $pallets = $this->labeledPallets(['L-1', 'L-1', 'L-2']);

        $receipts = $this->requestReceipts($this->admin, $pallets, onePerPallet: true);

        $this->assertCount(3, $receipts);
        $this->assertSame(3, $this->sap()->receiptWriteCalls());
        $this->assertSame(3, ProductionReceipt::where('status', SapDocumentStatus::Confirmed)->distinct('sap_doc_entry')->count('sap_doc_entry'));
    }

    public function test_boxes_convert_with_the_current_factor_and_the_line_keeps_which_one(): void
    {
        $order = ProductionOrder::factory()->create();
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'version' => 1, 'units_per_box' => '6', 'status' => MasterStatus::Superseded]);
        $current = BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'version' => 2, 'units_per_box' => '2.5']);
        [$pallet] = $this->labeledPallets(['L-1'], order: $order)->all();

        $this->requestReceipts($this->admin, [$pallet]);

        $line = ProductionReceiptPallet::sole();
        $this->assertSame($current->id, $line->box_conversion_factor_id);
        $this->assertSame('2.500000', $line->units_per_box);
        $this->assertSame('100.000000', $line->sap_quantity);
        $this->assertSame('100', $this->sap()->receipts()[0]->lines[0]->quantity->value);
    }

    public function test_a_received_or_held_pallet_cannot_be_requested_again(): void
    {
        [$received, $held] = $this->labeledPallets(['L-1', 'L-2'])->all();
        $this->requestReceipts($this->admin, [$received]);

        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating);
        $this->requestReceipts($this->admin, [$held]);
        $this->assertSame(ReceiptStatus::PendingReconciliation, $held->refresh()->receipt_status);

        foreach ([$received, $held] as $pallet) {
            try {
                $this->requestReceipts($this->admin, [$pallet]);
                $this->fail('Debía rechazarse');
            } catch (ReceiptRuleViolation $e) {
                $this->assertStringContainsString('no se puede recibir', $e->getMessage());
            }
        }

        $this->assertSame(2, $this->sap()->receiptWriteCalls());
    }

    public function test_requests_that_break_a_rule_create_nothing(): void
    {
        $pallets = $this->labeledPallets(['L-1']);
        $other = $this->labeledPallets(['L-9']);
        $notLabeled = Pallet::factory()->palletized()->create(['production_order_id' => $pallets[0]->production_order_id, 'pallet_standard_id' => $pallets[0]->pallet_standard_id]);
        $noFactor = Pallet::factory()->labeled()->create();
        $closedOrder = $this->labeledPallets(['L-C']);
        ProductionOrder::query()->whereKey($closedOrder[0]->production_order_id)->update(['is_open' => false]);

        $cases = [
            [[], 'al menos una'],
            [[$pallets[0], $other[0]], 'misma orden'],
            [[$notLabeled], 'no está etiquetada'],
            [[$noFactor], 'factor de conversión'],
            [[$closedOrder[0]], 'está cerrada'],
        ];

        foreach ($cases as [$selection, $message]) {
            try {
                $this->requestReceipts($this->admin, $selection);
                $this->fail("Debía rechazar: {$message}");
            } catch (ReceiptRuleViolation $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(0, ProductionReceipt::count());
        $this->assertSame(0, $this->sap()->receiptWriteCalls());
    }

    public function test_a_rejected_receipt_fails_releases_the_pallets_and_they_can_be_requested_again(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::Reject);
        $pallets = $this->labeledPallets(['L-1', 'L-2']);

        [$first] = $this->requestReceipts($this->admin, $pallets);

        $this->assertSame(SapDocumentStatus::Failed, $first->refresh()->status);
        $this->assertSame('FAKE-REJECTED', $first->last_error_code);
        $this->assertTrue(ProductionReceiptPallet::where('production_receipt_id', $first->id)->get()->every(fn ($l) => $l->hold_status === PalletHold::Released));
        $this->assertSame(ReceiptStatus::Failed, $pallets[0]->refresh()->receipt_status);

        [$second] = $this->requestReceipts($this->admin, $pallets);

        $this->assertSame(SapDocumentStatus::Confirmed, $second->refresh()->status);
        $this->assertSame(ReceiptStatus::Confirmed, $pallets[1]->refresh()->receipt_status);
    }

    public function test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::CreateThenTimeout);
        $pallets = $this->labeledPallets(['L-1']);

        [$receipt] = $this->requestReceipts($this->admin, $pallets);

        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->refresh()->status);
        $this->assertSame(ReceiptStatus::PendingReconciliation, $pallets[0]->refresh()->receipt_status);

        $this->travel(30)->minutes();
        $this->lifecycle()->reconcile($receipt->id);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->status);
        $this->assertSame($this->sap()->receipts()[0]->docEntry, $receipt->sap_doc_entry);
        $this->assertSame(1, $this->sap()->receiptWriteCalls());
        $this->assertSame(ReceiptStatus::Confirmed, $pallets[0]->refresh()->receipt_status);
    }

    public function test_a_document_proved_absent_is_resent_only_after_the_grace_period(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating);
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));

        $this->lifecycle()->reconcile($receipt->id);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->refresh()->status);
        $this->assertSame('no_existe_en_espera', $receipt->reconciliation['last_result']);
        $this->assertSame(1, $this->sap()->receiptWriteCalls());

        $this->travel(config()->integer('sap.reconciliation.resend_after_minutes') + 1)->minutes();
        $this->lifecycle()->reconcile($receipt->id);

        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->refresh()->status);
        $this->assertSame(2, $receipt->attempts);
        $this->assertSame(2, $this->sap()->receiptWriteCalls());
        $this->assertCount(1, $this->sap()->receipts());
    }

    public function test_when_sap_cannot_be_asked_nothing_changes_and_nothing_is_resent(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating);
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));
        $this->travel(60)->minutes();

        $this->sap()->failNextLookups();
        $this->lifecycle()->reconcile($receipt->id);

        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->refresh()->status);
        $this->assertSame('consulta_fallida', $receipt->reconciliation['last_result']);
        $this->assertSame('Consulta a SAP sin respuesta (simulador).', $receipt->reconciliation['last_error']);
        $this->assertSame(1, $this->sap()->receiptWriteCalls());
        $this->assertSame(AttemptOutcome::QueryFailed, IntegrationAttempt::latest('id')->first()?->outcome);
    }

    public function test_a_created_receipt_that_cannot_be_read_back_says_why(): void
    {
        $this->sap()->failNextLookups();
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->status);
        $this->assertSame('SAP respondió creado pero no se pudo verificar el documento: Consulta a SAP sin respuesta (simulador).', $receipt->last_error_message);
    }

    public function test_resending_stops_at_the_attempt_limit(): void
    {
        config(['sap.reconciliation.max_send_attempts' => 2]);
        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating, FakeScenario::TimeoutWithoutCreating, FakeScenario::TimeoutWithoutCreating);
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));

        foreach (range(1, 3) as $i) {
            $this->travel(20)->minutes();
            $this->lifecycle()->reconcile($receipt->id);
        }

        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->refresh()->status);
        $this->assertSame(2, $this->sap()->receiptWriteCalls());
        $this->assertSame('no_existe_limite_de_envios', $receipt->reconciliation['last_result']);
    }

    public function test_a_document_that_does_not_match_the_request_is_never_confirmed_nor_resent(): void
    {
        $this->sap()->queueReceiptScenarios(FakeScenario::CreateThenTimeout);
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));
        $this->sap()->alterReceipt($receipt->request_uuid, fn (SapDocument $d) => new SapDocument($d->docEntry, $d->docNum, $d->correlationId, [
            new SapDocumentLine(0, $d->lines[0]->itemCode, $d->lines[0]->lotCode, new Quantity('1'), $d->lines[0]->warehouse),
        ], $d->productionOrderRef));

        $this->travel(60)->minutes();
        $this->lifecycle()->reconcile($receipt->id);
        $this->lifecycle()->reconcile($receipt->id);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->status);
        $this->assertTrue($receipt->reconciliation['mismatch']);
        $this->assertStringContainsString('Cantidad distinta', $receipt->reconciliation['differences'][0]);
        $this->assertNull($receipt->sap_doc_entry);
        $this->assertSame(1, $this->sap()->receiptWriteCalls());
    }

    public function test_a_send_left_hanging_by_a_dead_worker_goes_to_reconciliation_not_to_a_new_send(): void
    {
        Queue::fake();
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));
        $receipt->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now(), 'attempts' => 1])->save();

        $this->travel(config()->integer('sap.reconciliation.stale_sending_minutes') + 1)->minutes();
        Artisan::call('sap:sweep');

        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->refresh()->status);
        $this->assertSame(0, $this->sap()->receiptWriteCalls());
    }

    public function test_the_send_job_is_queued_only_after_commit_and_a_lost_one_is_queued_again_by_the_sweeper(): void
    {
        // The real database queue (the production driver), so after-commit is honored as in production.
        config(['queue.default' => 'database']);
        $pallets = $this->labeledPallets(['L-1', 'L-2']);

        try {
            DB::transaction(function () use ($pallets): void {
                $this->requestReceipts($this->admin, [$pallets[0]]);

                throw new RuntimeException('falla después de solicitar');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, ProductionReceipt::count());

        [$receipt] = $this->requestReceipts($this->admin, [$pallets[1]]);
        $this->assertSame(1, DB::table('jobs')->count());

        // The job is lost (a deploy wiped the queue, say): the sweeper queues it again.
        DB::table('jobs')->delete();
        $this->travel(config()->integer('sap.reconciliation.requeue_after_minutes') + 1)->minutes();
        Artisan::call('sap:sweep');

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringContainsString('SendProductionReceipt', $payload);
        $this->assertSame(SapDocumentStatus::Requested, $receipt->refresh()->status);
    }

    public function test_running_the_send_twice_sends_once(): void
    {
        Queue::fake();
        [$receipt] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));

        $this->lifecycle()->send($receipt->id);
        $this->lifecycle()->send($receipt->id);

        $this->assertSame(1, $this->sap()->receiptWriteCalls());
    }

    public function test_successive_partial_receipts_of_one_order(): void
    {
        $order = ProductionOrder::factory()->create();
        $first = $this->labeledPallets(['L-1'], order: $order);
        $this->requestReceipts($this->admin, $first);
        $second = $this->labeledPallets(['L-2'], order: $order);
        $this->requestReceipts($this->admin, $second);

        $this->assertSame(2, ProductionReceipt::where('production_order_id', $order->id)->where('status', SapDocumentStatus::Confirmed)->count());
    }

    public function test_a_person_can_close_a_pending_receipt_as_failed_only_with_proof_of_absence(): void
    {
        $ctx = TraceContext::forUser($this->admin, Origin::Web, (string) Str::uuid());
        $this->sap()->queueReceiptScenarios(FakeScenario::CreateThenTimeout, FakeScenario::TimeoutWithoutCreating);
        [$exists] = $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));
        [$absent] = $this->requestReceipts($this->admin, $pallets = $this->labeledPallets(['L-2']));
        $this->travel(30)->minutes();

        try {
            $this->lifecycle()->failAfterProof($exists->id, $ctx, 'Prueba');
            $this->fail('No debía permitirlo: el documento existe');
        } catch (ReceiptRuleViolation) {
        }

        $this->lifecycle()->failAfterProof($absent->id, $ctx, 'Nunca llegó a SAP');

        $this->assertSame(SapDocumentStatus::Failed, $absent->refresh()->status);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $exists->refresh()->status);
        $this->assertSame(ReceiptStatus::Failed, $pallets[0]->refresh()->receipt_status);
    }

    public function test_every_call_to_sap_is_recorded(): void
    {
        $this->requestReceipts($this->admin, $this->labeledPallets(['L-1']));

        $this->assertSame(['CREAR', 'CONSULTAR'], IntegrationAttempt::orderBy('attempt_number')->pluck('operation')->all());
        $this->assertSame([1, 2], IntegrationAttempt::orderBy('attempt_number')->pluck('attempt_number')->all());
    }

    public function test_only_people_with_the_permission_request_receipts(): void
    {
        $pallets = $this->labeledPallets(['L-1']);

        $this->expectException(AuthorizationException::class);
        $this->requestReceipts($this->userWithRole(Role::ProductionOperator), $pallets);
    }
}
