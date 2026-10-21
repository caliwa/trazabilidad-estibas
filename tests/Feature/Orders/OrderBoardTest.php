<?php

namespace Tests\Feature\Orders;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\Orders\Actions\RefreshProductionOrders;
use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Orders\OrderProgress;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Fake\FakeSapCatalog;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use App\Models\User;
use App\Notifications\OrderReadyToClose;
use App\Queries\OrderProgressQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsReceipts;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Production order board: progress against SAP, the "ready to close in
 * SAP" alert, the refresh from SAP and the screens.
 */
class OrderBoardTest extends TestCase
{
    use BuildsReceipts, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->admin, Origin::Web, (string) Str::uuid());
    }

    /** OF 33128 as SAP has it: released, 800 units. At 10 per box a labeled pallet (40 boxes) is 400 units. */
    private function released(): ProductionOrder
    {
        return app(ConsultProductionOrder::class)->handle($this->admin, '33128', $this->ctx());
    }

    private function stage(ProductionOrder $order): OrderStage
    {
        return app(OrderProgressQuery::class)->forOrder($order->id)?->stage() ?? $this->fail('Sin avance');
    }

    private function receive(ProductionOrder $order, string $lot): void
    {
        $this->requestReceipts($this->admin, $this->labeledPallets([$lot], unitsPerBox: '10', order: $order));
    }

    public function test_the_stage_follows_confirmed_receipts_against_the_planned_quantity(): void
    {
        $order = $this->released();
        $this->assertSame(OrderStage::InProgress, $this->stage($order));

        $this->receive($order, 'L-1');
        $progress = app(OrderProgressQuery::class)->forOrder($order->id);
        $this->assertSame(OrderStage::InProgress, $progress?->stage());
        $this->assertSame(50, $progress->percent());
        $this->assertSame(40, $progress->receivedBoxes);
        $this->assertSame(80, $progress->plannedBoxes());

        $this->receive($order, 'L-2');
        $this->assertSame(OrderStage::ReadyToClose, $this->stage($order));

        $this->receive($order, 'L-3');
        $progress = app(OrderProgressQuery::class)->forOrder($order->id);
        $this->assertSame(OrderStage::OverProduced, $progress?->stage());
        $this->assertSame(100, $progress->percent());
        $this->assertStringContainsString('400 UNIDAD por encima de lo planificado', $progress->alerts()[0]['text']);
    }

    public function test_the_last_partial_box_still_counts_as_the_planned_quantity(): void
    {
        // 800 units at 24 per box: 33 full boxes and one of 8. Receipts go in whole boxes.
        $progress = fn (int $received, ?int $perBox = 24) => new OrderProgress(1, 'LIBERADO', 800_000_000, 'UNIDAD', $perBox === null ? null : $perBox * 1_000_000, null,
            0, 0, 0, 0, 0, 0, $received * 1_000_000, 0, 0, 0, 0, 0, 0, 0, 0);

        $this->assertSame(OrderStage::InProgress, $progress(792)->stage());
        $this->assertSame(OrderStage::ReadyToClose, $progress(800)->stage());
        $this->assertSame(OrderStage::ReadyToClose, $progress(816)->stage());
        $this->assertStringContainsString('La última caja va completa en el recibo: 16 UNIDAD más', $progress(816)->alerts()[0]['text']);
        $this->assertSame(OrderStage::OverProduced, $progress(840)->stage());
        // Without a conversion factor there are no boxes to reason about: the plan is the limit.
        $this->assertSame(OrderStage::OverProduced, $progress(816, null)->stage());
    }

    public function test_a_receipt_not_confirmed_yet_does_not_count(): void
    {
        $order = $this->released();
        $this->receive($order, 'L-1');
        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating);
        $this->receive($order, 'L-2');

        $progress = app(OrderProgressQuery::class)->forOrder($order->id);
        $this->assertSame(OrderStage::InProgress, $progress?->stage());
        $this->assertSame(1, $progress->receiptsPending);
        $this->assertContains('1 recibo(s) pendiente(s) de conciliar con SAP.', array_column($progress->alerts(), 'text'));
    }

    public function test_the_ready_to_close_alert_goes_once_to_the_people_who_follow_the_board(): void
    {
        Notification::fake();
        $operator = $this->userWithRole(Role::ProductionOperator);
        $order = $this->released();

        $this->receive($order, 'L-1');
        Notification::assertNothingSent();

        $this->receive($order, 'L-2');
        Notification::assertSentTo($this->admin, OrderReadyToClose::class, fn (OrderReadyToClose $n) => $n->orderId === $order->id);
        Notification::assertNotSentTo($operator, OrderReadyToClose::class);
        $this->assertNotNull($order->refresh()->ready_to_close_notified_at);
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::ProductionOrderReadyToClose->value)->count());

        $this->receive($order, 'L-3');
        Notification::assertSentToTimes($this->admin, OrderReadyToClose::class, 1);

        $data = (new OrderReadyToClose($order->id))->toArray($this->admin);
        $this->assertSame('OF 33128 lista para cerrar en SAP', $data['title']);
        $this->assertStringContainsString('Recibido 1.200 de 800 UNIDAD. Hay sobreproducción', $data['body']);
    }

    public function test_an_order_not_released_in_sap_never_raises_the_alert(): void
    {
        Notification::fake();
        $order = ProductionOrder::factory()->create(['planned_quantity' => '400', 'sap_status' => null]);

        $this->receive($order, 'L-1');

        Notification::assertNothingSent();
        $this->assertNull($order->refresh()->ready_to_close_notified_at);
    }

    public function test_the_refresh_reads_status_and_completed_quantity_from_sap(): void
    {
        $order = $this->released();
        $this->receive($order, 'L-1');

        $problems = app(RefreshProductionOrders::class)->handle($this->admin, [$order->id], $this->ctx());

        $this->assertSame([], $problems);
        $this->assertSame('400.000000', $order->refresh()->sap_completed_quantity);
        // SAP and the app agree: no alert about it.
        $alerts = implode(' ', array_column(app(OrderProgressQuery::class)->forOrder($order->id)?->alerts() ?? [], 'text'));
        $this->assertStringNotContainsString('SAP reporta', $alerts);
        $this->assertSame(TraceEventType::ProductionOrderRefreshed->value, TraceEvent::query()->latest('id')->value('event'));

        // Someone closes it in SAP: after the refresh it is closed here too, and nothing more can be received.
        $sapOrder = FakeSapCatalog::productionOrders()['33128'];
        $this->sap()->putProductionOrder(new SapProductionOrder(...[...get_object_vars($sapOrder), 'status' => ProductionOrderStatus::Closed]));
        app(RefreshProductionOrders::class)->handle($this->admin, RefreshProductionOrders::openOrderIds(), $this->ctx());

        $order->refresh();
        $this->assertSame('CERRADO', $order->sap_status);
        $this->assertFalse($order->is_open);
        $this->assertSame(OrderStage::Closed, $this->stage($order));
        $this->assertSame([], RefreshProductionOrders::openOrderIds());
    }

    public function test_sap_and_the_app_disagreeing_is_an_alert(): void
    {
        $order = $this->released();
        $this->receive($order, 'L-1');
        $order->forceFill(['sap_completed_quantity' => '600'])->save();

        $alerts = array_column(app(OrderProgressQuery::class)->forOrder($order->id)?->alerts() ?? [], 'text');

        $this->assertContains('SAP reporta 600 UNIDAD completadas y la aplicación confirmó 400. Puede haber recibos hechos por fuera o pendientes.', $alerts);
    }

    public function test_more_boxes_read_than_labels_is_an_alert(): void
    {
        $order = $this->released();
        // The helper prints 10 labels per lot; a labeled pallet holds 40 boxes.
        $this->labeledPallets(['L-1'], unitsPerBox: '10', order: $order);

        $this->assertContains('Se leyeron 40 cajas y solo se generaron 10 etiquetas de caja.', array_column(app(OrderProgressQuery::class)->forOrder($order->id)?->alerts() ?? [], 'text'));
    }

    public function test_an_order_that_cannot_be_refreshed_keeps_its_data_and_says_why(): void
    {
        $order = $this->released();
        $ghost = ProductionOrder::factory()->create(['ref' => '99999']);

        $this->sap()->failNextLookups();
        $problems = app(RefreshProductionOrders::class)->handle($this->admin, [$order->id, $ghost->id], $this->ctx());

        $this->assertSame(['33128' => 'SAP no respondió. Quedan los datos de la última actualización.', '99999' => 'SAP no tiene una orden con ese número.'], $problems);
        $this->assertSame('LIBERADO', $order->refresh()->sap_status);
        $this->assertNull($ghost->refresh()->sap_status);
    }

    public function test_only_people_with_orders_view_refresh_or_open_the_board(): void
    {
        $operator = $this->userWithRole(Role::ProductionOperator);
        $order = $this->released();

        $this->expectExceptionObject(new AuthorizationException('This action is unauthorized.'));
        try {
            $this->actingAs($operator)->get(route('orders.index'))->assertForbidden();
            $this->actingAs($operator)->get(route('orders.show', $order))->assertForbidden();
            $this->actingAs($this->userWithRole(Role::Viewer))->get(route('orders.index'))->assertForbidden();
        } finally {
            app(RefreshProductionOrders::class)->handle($operator, [$order->id], $this->ctx($operator));
        }
    }

    public function test_the_board_lists_only_released_orders_most_urgent_first(): void
    {
        $inProgress = $this->released();
        $ready = app(ConsultProductionOrder::class)->handle($this->admin, '33131', $this->ctx());
        $this->requestReceipts($this->admin, $this->labeledPallets(['L-1'], unitsPerBox: '15', order: $ready));
        $notConsulted = ProductionOrder::factory()->create(['ref' => '55555']);
        $closed = ProductionOrder::factory()->create(['ref' => '66666', 'sap_status' => 'CERRADO', 'is_open' => false]);

        $this->actingAs($this->admin)->get(route('orders.index'))
            ->assertOk()
            ->assertSeeInOrder(['33131', 'Lista para cerrar en SAP', '33128', 'En proceso'])
            ->assertDontSee($notConsulted->ref)
            ->assertDontSee($closed->ref);

        Livewire::actingAs($this->admin)->test('pages::orders.index')
            ->set('stage', OrderStage::ReadyToClose->value)
            ->assertSee('33131')->assertDontSee('GELATINA OLIMPICA')
            ->set('stage', '')->set('search', 'olimpica')
            ->assertSee('33128')->assertDontSee('GELATINA ALKOSTO')
            ->call('refreshFromSap')
            ->assertHasNoErrors();

        // The refresh also asked SAP about the order nobody had looked up: SAP doesn't have it.
        Livewire::actingAs($this->admin)->test('pages::orders.index')->call('refreshFromSap')->assertSee('OF 55555')->assertSee('SAP no tiene una orden con ese número.');
    }

    public function test_the_detail_shows_the_way_lots_documents_and_pallets(): void
    {
        $order = $this->released();
        $this->receive($order, 'L-1');

        $this->actingAs($this->admin)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('OF 33128')
            ->assertSee('GELATINA OLIMPICA SIN SABOR 30 GR')
            ->assertSeeInOrder(['Planificado', '800 UNIDAD', 'Recorrido', 'Recibido en 04', '40 cajas', '400 UNIDAD'])
            ->assertSeeInOrder(['Lotes', 'L-1', '20/10/2028'])
            ->assertSeeInOrder(['Documentos en SAP', 'Recibo de producción', 'Confirmado'])
            ->assertSee('Estibas (1)');

        Livewire::actingAs($this->admin)->test('pages::orders.show', ['order' => $order])->call('refreshFromSap')->assertSee('400 UNIDAD');
    }
}
