<?php

namespace Tests\Feature\Integration;

use App\Domain\Integration\ConversionFactors;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Receipts\ProductionReceiptLifecycle;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Models\BoxConversionFactor;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsReceipts;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The whole production receipt with the Service Layer connector: request,
 * POST InventoryGenEntries, the comparison of what SAP answered before
 * confirming, and reconciliation by the DocEntry SAP gave.
 */
class ServiceLayerReceiptFlowTest extends TestCase
{
    use BuildsReceipts, CreatesUsers, RefreshDatabase;

    private const URL = 'https://sap.test:50000/b1s/v1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
        $this->travelTo('2026-10-21 09:30');
        config([
            'sap.driver' => 'service_layer',
            'sap.service_layer.base_url' => self::URL,
            'sap.service_layer.company_db' => 'SBO_DEV',
            'sap.service_layer.username' => 'trazabilidad',
            'sap.service_layer.password' => 'secret',
        ]);
        $this->app->forgetInstance(SapGateway::class);
    }

    private function pallet(): Pallet
    {
        $order = ProductionOrder::factory()->create(['sap_doc_entry' => 41659, 'sap_status' => 'LIBERADO']);

        return $this->labeledPallets(['L-1'], unitsPerBox: '12', order: $order)->sole();
    }

    /**
     * What SAP returns when reading the receipt, with the batch expiry given.
     *
     * @return array<string, mixed>
     */
    private function document(Pallet $pallet, string $uuid, string $expiry): array
    {
        return [
            'DocEntry' => 9001, 'DocNum' => 5001, 'DocDate' => '2026-10-21T00:00:00Z', 'U_TRZ_UUID' => $uuid,
            'DocumentLines' => [[
                'LineNum' => 0, 'ItemCode' => $pallet->item_code, 'WarehouseCode' => '04', 'BaseEntry' => 41659, 'Quantity' => 480.0,
                'BatchNumbers' => [['BatchNumber' => 'L-1', 'Quantity' => 480.0, 'ExpiryDate' => $expiry, 'ManufacturingDate' => '2026-10-21T00:00:00Z']],
            ]],
        ];
    }

    /** SAP without the correlation user field: any query by it is refused. */
    private const NO_UDF = ['error' => ['code' => -1000, 'message' => ['value' => "Property 'U_TRZ_UUID' of 'Document'is invalid"]]];

    public function test_a_receipt_is_confirmed_with_the_document_sap_answers(): void
    {
        $pallet = $this->pallet();
        $uuid = null;
        Http::fake(function (Request $r) use ($pallet, &$uuid) {
            return match (true) {
                str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200, ['Set-Cookie' => 'B1SESSION=s1']),
                $r->method() === 'POST' && str_ends_with($r->url(), '/InventoryGenEntries') => (function () use ($r, $pallet, &$uuid) {
                    $uuid = $r['U_TRZ_UUID'];

                    // Service Layer answers 201 with the created document.
                    return Http::response($this->document($pallet, (string) $uuid, '2028-10-20T00:00:00Z'), 201);
                })(),
                str_contains($r->url(), '/InventoryGenEntries?') => Http::response(self::NO_UDF, 400),
                default => Http::response([], 404),
            };
        });

        [$receipt] = $this->requestReceipts($this->admin, [$pallet]);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->status, json_encode($receipt->reconciliation) ?: '');
        $this->assertSame([9001, 5001], [$receipt->sap_doc_entry, $receipt->sap_doc_num]);
        $this->assertSame($receipt->request_uuid, $uuid);
        $this->assertSame(ReceiptStatus::Confirmed, $pallet->refresh()->receipt_status);

        // Nothing is asked by the correlation field: SAP may not have it.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/InventoryGenEntries'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/InventoryGenEntries')
            && $r['DocDate'] === '2026-10-21'
            && $r['DocumentLines'][0]['BaseEntry'] === 41659
            && $r['DocumentLines'][0]['Quantity'] === 480
            && $r['DocumentLines'][0]['BatchNumbers'][0] === ['BaseLineNumber' => 0, 'BatchNumber' => 'L-1', 'Quantity' => 480, 'ManufacturingDate' => '2026-10-21', 'ExpiryDate' => '2028-10-20', 'AddmisionDate' => '2026-10-21']);
    }

    public function test_an_answer_without_lines_is_read_back_by_its_doc_entry(): void
    {
        $pallet = $this->pallet();
        $uuid = null;
        Http::fake(function (Request $r) use ($pallet, &$uuid) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/InventoryGenEntries')) {
                $uuid = $r['U_TRZ_UUID'];
            }

            return match (true) {
                str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200),
                $r->method() === 'POST' => Http::response(['DocEntry' => 9001, 'DocNum' => 5001], 201),
                str_contains($r->url(), '/InventoryGenEntries?') => Http::response(self::NO_UDF, 400),
                str_ends_with($r->url(), '/InventoryGenEntries(9001)') => Http::response($this->document($pallet, (string) $uuid, '2028-10-20T00:00:00Z')),
                default => Http::response([], 404),
            };
        });

        [$receipt] = $this->requestReceipts($this->admin, [$pallet]);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->status, json_encode($receipt->reconciliation) ?: '');
        $this->assertSame([9001, 5001], [$receipt->sap_doc_entry, $receipt->sap_doc_num]);
    }

    public function test_reconciling_a_created_receipt_reads_it_by_the_doc_entry_sap_gave(): void
    {
        $pallet = $this->pallet();
        $uuid = null;
        $readable = false;
        Http::fake(function (Request $r) use ($pallet, &$uuid, &$readable) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/InventoryGenEntries')) {
                $uuid = $r['U_TRZ_UUID'];
            }

            return match (true) {
                str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200),
                $r->method() === 'POST' => Http::response(['DocEntry' => 9001, 'DocNum' => 5001], 201),
                str_contains($r->url(), '/InventoryGenEntries?') => Http::response(self::NO_UDF, 400),
                str_ends_with($r->url(), '/InventoryGenEntries(9001)') => $readable
                    ? Http::response($this->document($pallet, (string) $uuid, '2028-10-20T00:00:00Z'))
                    : Http::response(['error' => ['code' => -1, 'message' => ['value' => 'Internal error']]], 500),
                default => Http::response([], 404),
            };
        });

        // Created in SAP, but it could not be read right after: pending, with the reason.
        [$receipt] = $this->requestReceipts($this->admin, [$pallet]);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->refresh()->status);
        $this->assertStringContainsString('no se pudo verificar el documento: SAP respondió 500', (string) $receipt->last_error_message);

        // Reconciling reads DocEntry 9001, never the correlation field, and confirms.
        $readable = true;
        app(ProductionReceiptLifecycle::class)->reconcile($receipt->id);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->status, json_encode($receipt->reconciliation) ?: '');
        $this->assertSame([9001, 5001], [$receipt->sap_doc_entry, $receipt->sap_doc_num]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/InventoryGenEntries?'));
    }

    public function test_a_receipt_with_other_batch_dates_in_sap_is_never_confirmed(): void
    {
        $pallet = $this->pallet();
        Http::fake(function (Request $r) use ($pallet) {
            return match (true) {
                str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200),
                $r->method() === 'POST' => Http::response($this->document($pallet, (string) $r['U_TRZ_UUID'], '2027-01-01'), 201),
                default => Http::response([], 404),
            };
        });

        [$receipt] = $this->requestReceipts($this->admin, [$pallet]);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->status);
        $this->assertTrue($receipt->reconciliation['mismatch']);
        $this->assertStringContainsString('Fecha de vencimiento distinta', $receipt->reconciliation['differences'][0]);
        $this->assertNull($receipt->sap_doc_entry);
    }

    public function test_syncing_orders_stores_the_product_sales_packaging(): void
    {
        Http::fake([
            self::URL.'/Login' => Http::response(['SessionId' => 's1'], 200),
            self::URL.'/ProductionOrders?*' => Http::response(['value' => [[
                'AbsoluteEntry' => 41659, 'DocumentNumber' => 33128, 'ProductionOrderStatus' => 'boposReleased', 'ItemNo' => 'PT1350023', 'PlannedQuantity' => 800, 'Warehouse' => '04',
            ]]]),
            self::URL."/Items('PT1350023')*" => Http::response(['ItemCode' => 'PT1350023', 'ItemName' => 'GELATINA OLIMPICA SIN SABOR 30 GR', 'InventoryUOM' => 'UNIDAD', 'SalesQtyPerPackUnit' => 24.0, 'SalesPackagingUnit' => 'CAJA']),
        ]);

        $this->assertSame(0, Artisan::call('sap:sync-orders'), Artisan::output());

        $item = Item::query()->where('code', 'PT1350023')->sole();
        $this->assertSame(['24.000000', 'CAJA'], [$item->sales_qty_per_pack_unit, $item->sales_packaging_unit]);
        $this->assertSame(41659, ProductionOrder::query()->where('ref', '33128')->value('sap_doc_entry'));
    }

    public function test_sales_qty_per_pack_unit_becomes_the_versioned_units_per_box(): void
    {
        $units = null;
        Http::fake(function (Request $r) use (&$units) {
            return match (true) {
                str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200),
                str_contains($r->url(), '/ProductionOrders?') => Http::response(['value' => [[
                    'AbsoluteEntry' => 41659, 'DocumentNumber' => 33128, 'ProductionOrderStatus' => 'boposReleased', 'ItemNo' => 'PT1350023', 'PlannedQuantity' => 800, 'Warehouse' => '04',
                ]]]),
                default => Http::response(['ItemCode' => 'PT1350023', 'ItemName' => 'GELATINA', 'InventoryUOM' => 'UNIDAD', 'SalesQtyPerPackUnit' => $units, 'SalesPackagingUnit' => 'CAJA']),
            };
        });
        $answers = [24.0, 24.0, 12.0, 0.0, null];
        $sync = function () use (&$answers, &$units): void {
            $units = array_shift($answers);
            $this->assertSame(0, Artisan::call('sap:sync-orders'));
        };
        $item = fn () => Item::query()->where('code', 'PT1350023')->sole();
        $factors = fn () => BoxConversionFactor::query()->where('item_id', $item()->id)->orderBy('version')->get()
            ->map(fn (BoxConversionFactor $f) => [$f->version, $f->units_per_box, $f->status])->all();

        $sync();
        $this->assertEquals([[1, '24.000000', MasterStatus::Current]], $factors());
        $this->assertSame('24.000000', app(ConversionFactors::class)->current($item()->id, now())?->units_per_box);

        // Same value: no new version.
        $sync();
        $this->assertCount(1, $factors());

        // SAP changed it: a new current version, the old one kept as superseded.
        $sync();
        $this->assertEquals([[1, '24.000000', MasterStatus::Superseded], [2, '12.000000', MasterStatus::Current]], $factors());
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::MastersSynced->value)->where('subject_id', $item()->id)->whereNotNull('from_state')->count());

        // Zero or empty in SAP: the current factor stays.
        $sync();
        $sync();
        $this->assertSame('12.000000', app(ConversionFactors::class)->current($item()->id, now())?->units_per_box);
        $this->assertCount(2, $factors());
    }

    public function test_sap_rejecting_the_receipt_fails_it_and_frees_the_pallet(): void
    {
        $pallet = $this->pallet();
        Http::fake([
            self::URL.'/Login' => Http::response(['SessionId' => 's1'], 200),
            self::URL.'/InventoryGenEntries' => Http::response(['error' => ['code' => -10, 'message' => ['value' => 'Quantity exceeds planned quantity']]], 400),
        ]);

        [$receipt] = $this->requestReceipts($this->admin, [$pallet]);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::Failed, $receipt->status);
        $this->assertSame('Quantity exceeds planned quantity', $receipt->last_error_message);
        $this->assertSame(ReceiptStatus::Failed, $pallet->refresh()->receipt_status);
    }
}
