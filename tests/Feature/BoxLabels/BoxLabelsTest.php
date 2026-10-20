<?php

namespace Tests\Feature\BoxLabels;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\BoxLabelZpl;
use App\Domain\BoxLabels\BoxLot;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Fake\FakeSapCatalog;
use App\Integrations\Sap\Fake\FakeSapGateway;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabelBatch;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Box labels: a production order is looked up in SAP by number; only a
 * released one is stored and can get labels. Labels carry OF, product,
 * units per box, lot (OF + Julian day), EAN and expiration, and are
 * printed as ZPL on 10 × 8 cm.
 */
class BoxLabelsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
    }

    private function sap(): FakeSapGateway
    {
        $sap = app(SapGateway::class);
        $this->assertInstanceOf(FakeSapGateway::class, $sap);

        return $sap;
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->operator, Origin::Web, (string) Str::uuid());
    }

    private function consult(string $docNum, ?User $user = null): ProductionOrder
    {
        return app(ConsultProductionOrder::class)->handle($user ?? $this->operator, $docNum, $this->ctx($user));
    }

    /**
     * Order 33128 consulted, its product with 24 units per box.
     */
    private function ready(string $unitsPerBox = '24', ?int $shelfLifeDays = 365): ProductionOrder
    {
        $order = $this->consult('33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => $unitsPerBox]);
        Item::query()->whereKey($order->item_id)->update(['shelf_life_days' => $shelfLifeDays]);

        return $order->refresh();
    }

    private function generate(ProductionOrder $order, int $labels, ?string $production = '2026-10-20', ?string $expiration = null): BoxLabelBatch
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

        return app(GenerateBoxLabels::class)->handle($this->operator, $order->id, $labels, CarbonImmutable::parse($production), $expiration === null ? null : CarbonImmutable::parse($expiration), $this->ctx());
    }

    private function expectViolation(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('Debía rechazarse: '.$message);
        } catch (BoxLabelRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_released_order_is_consulted_in_sap_and_its_header_is_stored(): void
    {
        $order = $this->consult(' 33128 ');

        $this->assertSame('33128', $order->ref);
        $this->assertSame(34128, $order->sap_doc_entry);
        $this->assertSame('LIBERADO', $order->sap_status);
        $this->assertSame('Estándar', $order->sap_order_type);
        $this->assertSame('800.000000', $order->refresh()->planned_quantity);
        $this->assertSame(['UNIDAD', '04', 100, 'Aux Producción 1', 'Manual', 'Pedido de cliente'], [$order->uom_name, $order->warehouse_code, $order->priority, $order->sap_user, $order->sap_origin, $order->sap_linked_to]);
        $this->assertSame(['2026-02-17', '2026-02-17', '2026-02-24'], [$order->order_date?->toDateString(), $order->start_date?->toDateString(), $order->due_date?->toDateString()]);
        $this->assertSame('33128', $order->sap_header['doc_num']);
        $this->assertSame($this->operator->id, $order->consulted_by);

        $item = Item::query()->findOrFail($order->item_id);
        $this->assertSame(['PT1350023', 'GELATINA OLIMPICA SIN SABOR 30 GR', FakeSapCatalog::barcode('PT1350023')], [$item->code, $item->description, $item->barcode]);
        $this->assertSame(TraceEventType::ProductionOrderConsulted->value, TraceEvent::query()->latest('id')->value('event'));
    }

    public function test_orders_that_do_not_exist_or_are_not_released_are_refused_and_nothing_is_stored(): void
    {
        $this->expectViolation(fn () => $this->consult('99999'), 'no existe en SAP');
        $this->expectViolation(fn () => $this->consult('33129'), 'está «Planificado»');
        $this->expectViolation(fn () => $this->consult('33130'), 'está «Cerrado»');
        $this->expectViolation(fn () => $this->consult('33133'), 'está «Cancelado»');
        $this->expectViolation(fn () => $this->consult('OF-33128'), 'solo números');

        $this->sap()->failNextLookups();
        $this->expectViolation(fn () => $this->consult('33128'), 'No se pudo consultar SAP');

        $this->assertSame(0, ProductionOrder::count());
        $this->assertSame(0, Item::count());
    }

    public function test_consulting_again_refreshes_the_header_but_keeps_the_local_shelf_life(): void
    {
        $order = $this->ready(shelfLifeDays: 540);
        $sapOrder = FakeSapCatalog::productionOrders()['33128'];
        $this->sap()->putProductionOrder(new SapProductionOrder(
            $sapOrder->docEntry, $sapOrder->docNum, $sapOrder->type, $sapOrder->status, $sapOrder->itemCode, $sapOrder->itemDescription,
            new Quantity('1000'), $sapOrder->uomName, $sapOrder->warehouse, 50, $sapOrder->orderDate, $sapOrder->startDate, $sapOrder->dueDate,
            $sapOrder->userName, $sapOrder->origin, $sapOrder->linkedTo, $sapOrder->itemBarcode, $sapOrder->itemInventoryUom,
        ));

        $again = $this->consult('33128');

        $this->assertSame($order->id, $again->id);
        $this->assertSame(['1000.000000', 50], [$again->refresh()->planned_quantity, $again->priority]);
        $this->assertSame(540, Item::query()->findOrFail($order->item_id)->shelf_life_days);
        $this->assertSame(1, ProductionOrder::count());
    }

    public function test_an_order_whose_product_changed_in_sap_is_refused_once_it_has_pallets(): void
    {
        $other = Item::factory()->create(['code' => 'PT-OTRO']);
        $local = ProductionOrder::factory()->for($other)->create(['ref' => '33128']);
        Pallet::factory()->create(['production_order_id' => $local->id]);

        $this->expectViolation(fn () => $this->consult('33128'), 'otro producto');
        $this->assertSame($other->id, $local->refresh()->item_id);
    }

    public function test_the_lot_is_the_order_number_and_the_julian_day(): void
    {
        $this->assertSame('33128293', BoxLot::code('33128', CarbonImmutable::parse('2026-10-20')));
        $this->assertSame('33128005', BoxLot::code('33128', CarbonImmutable::parse('2026-01-05')));
        $this->assertSame('33128366', BoxLot::code('33128', CarbonImmutable::parse('2028-12-31')));
    }

    public function test_a_batch_carries_order_product_units_lot_ean_and_expiration_in_the_qr(): void
    {
        $order = $this->ready();

        $batch = $this->generate($order, 30);

        $ean = FakeSapCatalog::barcode('PT1350023');
        $this->assertSame("CJ1|33128|PT1350023|24 UNIDAD|33128293|{$ean}|2027-10-20", $batch->qr_payload);
        $this->assertSame(['33128293', '2026-10-20', '2027-10-20', 30], [$batch->lot_code, $batch->production_date->toDateString(), $batch->expiration_date->toDateString(), $batch->labels]);
        $this->assertSame(['order' => '33128', 'item' => 'PT1350023', 'units' => '24 UNIDAD', 'lot' => '33128293', 'barcode' => $ean, 'expiration' => '2027-10-20'], BoxLabelPayload::decode($batch->qr_payload));
        $this->assertSame(TraceEventType::BoxLabelsGenerated->value, TraceEvent::query()->latest('id')->value('event'));
    }

    public function test_the_zpl_is_a_10_by_8_cm_label_printed_as_many_times_as_asked(): void
    {
        $batch = $this->generate($this->ready(), 25);

        $this->assertStringStartsWith("^XA\n", $batch->zpl);
        $this->assertStringEndsWith("^XZ\n", $batch->zpl);
        $this->assertStringContainsString('^PW800', $batch->zpl);
        $this->assertStringContainsString('^LL640', $batch->zpl);
        $this->assertStringContainsString('^CI28', $batch->zpl);
        $this->assertStringContainsString('^BQN,2,6^FDMA,'.$batch->qr_payload.'^FS', $batch->zpl);
        $this->assertStringContainsString('^BEN,110,Y,N^FD'.substr($batch->barcode, 0, 12).'^FS', $batch->zpl);
        $this->assertStringContainsString('^FDGELATINA OLIMPICA SIN SABOR 30 GR^FS', $batch->zpl);
        $this->assertStringContainsString('^PQ25,0,1,Y', $batch->zpl);
        $this->assertSame(1, substr_count($batch->zpl, '^XA'));
    }

    public function test_text_from_sap_can_never_inject_zpl_commands(): void
    {
        $payload = new BoxLabelPayload('1', 'PT^X', '1', null, '1001', '123', CarbonImmutable::parse('2027-01-01'));

        $zpl = BoxLabelZpl::render($payload, 'DESCRIPCION ^XZ^XA~JA', CarbonImmutable::parse('2026-01-01'), 1);

        $this->assertSame(1, substr_count($zpl, '^XZ'));
        $this->assertStringNotContainsString('~JA', $zpl);
        $this->assertStringContainsString('^FDEAN: 123^FS', $zpl);
    }

    public function test_the_expiration_comes_from_the_shelf_life_or_is_typed_and_must_be_after_production(): void
    {
        $order = $this->ready(shelfLifeDays: null);

        $this->expectViolation(fn () => $this->generate($order, 1), 'fecha de vencimiento');
        $this->expectViolation(fn () => $this->generate($order, 1, expiration: '2026-10-20'), 'fecha de vencimiento');
        $this->assertSame('2027-04-30', $this->generate($order, 1, expiration: '2027-04-30')->expiration_date->toDateString());
    }

    public function test_an_order_never_gets_more_labels_than_its_planned_quantity_fills(): void
    {
        // 800 units at 24 per box: 33 full boxes and one of 8, so 34 labels.
        $order = $this->ready();
        $this->assertSame(34, GenerateBoxLabels::maxLabels('800.000000', '24.000000'));

        $this->generate($order, 30);
        $this->expectViolation(fn () => $this->generate($order, 5), 'entre 1 y 4');
        $this->generate($order, 4);
        $this->expectViolation(fn () => $this->generate($order, 1), 'Ya se generaron todas');
        $this->expectViolation(fn () => $this->generate($order, 0), 'Ya se generaron todas');

        $this->assertSame(34, (int) BoxLabelBatch::query()->sum('labels'));
    }

    public function test_labels_need_a_consulted_order_a_barcode_units_per_box_and_a_past_production_date(): void
    {
        $notConsulted = ProductionOrder::factory()->create();
        $this->expectViolation(fn () => $this->generate($notConsulted, 1, expiration: '2027-01-01'), 'Consulta la orden en SAP');

        $order = $this->consult('33128');
        $this->expectViolation(fn () => $this->generate($order, 1, expiration: '2027-01-01'), 'unidades por caja');

        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        Item::query()->whereKey($order->item_id)->update(['barcode' => null]);
        $this->expectViolation(fn () => $this->generate($order, 1, expiration: '2027-01-01'), 'no tiene código EAN');

        Item::query()->whereKey($order->item_id)->update(['barcode' => FakeSapCatalog::barcode('PT1350023')]);
        $this->expectViolation(fn () => $this->generate($order, 1, production: '2026-10-21', expiration: '2027-01-01'), 'no puede ser futura');

        $this->assertSame(0, BoxLabelBatch::count());
    }

    public function test_a_generated_batch_is_evidence_and_never_changes(): void
    {
        $batch = $this->generate($this->ready(), 2);

        $this->expectException(QueryException::class);
        BoxLabelBatch::query()->whereKey($batch->id)->update(['labels' => 200]);
    }

    public function test_only_people_with_box_labels_print_consult_and_generate(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->consult('33128', $this->userWithRole(Role::LogisticsOperator));
    }

    public function test_the_page_consults_shows_the_header_generates_and_offers_the_zpl(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $item = Item::factory()->create(['code' => 'PT1350023', 'shelf_life_days' => 365]);
        BoxConversionFactor::factory()->create(['item_id' => $item->id, 'units_per_box' => '24']);

        $page = Livewire::actingAs($this->operator)->test('pages::box-labels.index')
            ->set('docNum', '33129')->call('consult')
            ->assertHasErrors('docNum')
            ->assertSee('está «Planificado» en SAP')
            ->set('docNum', '33128')->call('consult')
            ->assertHasNoErrors()
            ->assertSee('GELATINA OLIMPICA SIN SABOR 30 GR')
            ->assertSee('Liberado')
            ->assertSee('Aux Producción 1')
            ->assertSee('Pedido de cliente')
            ->assertSee('33128293')
            ->assertSet('expirationDate', '2027-10-20')
            ->set('labels', 12)
            ->call('generate');

        $batch = BoxLabelBatch::sole();
        $page->assertSee('Descargar ZPL (12 etiquetas)')->assertSee($batch->qr_payload);

        $response = $this->actingAs($this->operator)->get(route('box-labels.zpl', $batch));
        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="OF33128-lote33128293-12cajas.zpl"');
        $this->assertSame($batch->zpl, $response->getContent());
        $this->assertSame(TraceEventType::BoxLabelsDownloaded->value, TraceEvent::query()->latest('id')->value('event'));
    }

    public function test_the_page_and_the_download_need_the_permission(): void
    {
        $batch = $this->generate($this->ready(), 1);

        $this->actingAs($this->userWithRole(Role::TraceabilityAdmin))->get(route('box-labels.index'))->assertOk();
        foreach ([Role::LogisticsOperator, Role::Viewer] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get(route('box-labels.index'))->assertForbidden();
            $this->actingAs($user)->get(route('box-labels.zpl', $batch))->assertForbidden();
        }
    }

    public function test_the_simulator_knows_the_real_finished_products(): void
    {
        $this->assertCount(40, FakeSapCatalog::ITEMS);
        $this->assertSame('GELATINA AZUCARADA SAN VALENTIN MORA 1000 GR', FakeSapCatalog::ITEMS['PT1350041']);
        $this->assertSame(ProductionOrderStatus::Released, FakeSapCatalog::productionOrders()['33128']->status);
        $this->assertMatchesRegularExpression('/^200\d{10}$/', FakeSapCatalog::barcode('PT1350002'));
    }
}
