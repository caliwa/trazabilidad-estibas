<?php

namespace Tests\Feature\Integration;

use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\DocumentLine;
use App\Integrations\Sap\Dto\InventoryTransferRequest;
use App\Integrations\Sap\Dto\ProductionReceiptRequest;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\WriteOutcome;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Integrations\Sap\ServiceLayer\NetworkProbe;
use App\Integrations\Sap\ServiceLayer\ServiceLayerGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Service Layer connector against recorded-style answers (Http::fake):
 * login and session, the production receipt JSON, how each answer is
 * classified, reading a receipt back and the production orders.
 */
class ServiceLayerGatewayTest extends TestCase
{
    private const URL = 'https://sap.test:50000/b1s/v1';

    private const UUID = '7d9f5c1e-2b3a-4c5d-8e9f-0a1b2c3d4e5f';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sap.driver' => 'service_layer',
            'sap.service_layer.base_url' => self::URL,
            'sap.service_layer.company_db' => 'SBO_DEV',
            'sap.service_layer.username' => 'trazabilidad',
            'sap.service_layer.password' => 's3cr3t-no-log',
        ]);
        $this->app->forgetInstance(SapGateway::class);
    }

    private function gateway(): ServiceLayerGateway
    {
        $gateway = app(SapGateway::class);
        $this->assertInstanceOf(ServiceLayerGateway::class, $gateway);

        return $gateway;
    }

    /**
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function fakes(array $more): array
    {
        return [
            self::URL.'/Login' => Http::response(['SessionId' => 'abc-123', 'SessionTimeout' => 30], 200, ['Set-Cookie' => 'B1SESSION=abc-123; path=/b1s']),
            ...$more,
        ];
    }

    private function request(?int $docEntry = 41659): ProductionReceiptRequest
    {
        return new ProductionReceiptRequest(
            self::UUID,
            '33128',
            [
                new DocumentLine('PT1350023', '33128294', new Quantity('5555'), '04', CarbonImmutable::parse('2027-04-21'), CarbonImmutable::parse('2026-10-21')),
                new DocumentLine('PT1350023', '33128295', new Quantity('12.5'), '04', CarbonImmutable::parse('2027-04-22'), CarbonImmutable::parse('2026-10-21')),
            ],
            CarbonImmutable::parse('2026-10-21'),
            $docEntry,
        );
    }

    public function test_the_receipt_goes_as_inventory_gen_entries_with_one_line_per_lot(): void
    {
        Http::fake($this->fakes([self::URL.'/InventoryGenEntries' => Http::response(['DocEntry' => 9001, 'DocNum' => 5001], 201)]));

        $result = $this->gateway()->createProductionReceipt($this->request());

        $this->assertSame(WriteOutcome::Created, $result->outcome);
        $this->assertSame([9001, 5001, 201], [$result->docEntry, $result->docNum, $result->httpStatus]);

        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/Login' && $r['CompanyDB'] === 'SBO_DEV' && $r['UserName'] === 'trazabilidad');
        Http::assertSent(function (Request $r): bool {
            if ($r->url() !== self::URL.'/InventoryGenEntries') {
                return false;
            }

            $this->assertSame('POST', $r->method());
            $this->assertStringContainsString('B1SESSION=abc-123', $r->header('Cookie')[0]);
            $this->assertSame([
                'DocDate' => '2026-10-21',
                'U_TRZ_UUID' => self::UUID,
                'DocumentLines' => [
                    [
                        'BaseType' => 202, 'BaseEntry' => 41659, 'Quantity' => 5555, 'WarehouseCode' => '04',
                        'CostingCode' => 'PCC', 'CostingCode2' => 'PRODUCCI', 'CostingCode3' => 'ANTIOQUI',
                        'BatchNumbers' => [['BaseLineNumber' => 0, 'BatchNumber' => '33128294', 'Quantity' => 5555, 'ManufacturingDate' => '2026-10-21', 'ExpiryDate' => '2027-04-21', 'AddmisionDate' => '2026-10-21']],
                    ],
                    [
                        'BaseType' => 202, 'BaseEntry' => 41659, 'Quantity' => 12.5, 'WarehouseCode' => '04',
                        'CostingCode' => 'PCC', 'CostingCode2' => 'PRODUCCI', 'CostingCode3' => 'ANTIOQUI',
                        'BatchNumbers' => [['BaseLineNumber' => 1, 'BatchNumber' => '33128295', 'Quantity' => 12.5, 'ManufacturingDate' => '2026-10-21', 'ExpiryDate' => '2027-04-22', 'AddmisionDate' => '2026-10-21']],
                    ],
                ],
            ], $r->data());

            return true;
        });
    }

    public function test_the_costing_codes_and_the_correlation_field_come_from_the_configuration(): void
    {
        config(['sap.receipt.correlation_field' => 'Comments', 'sap.receipt.costing_code' => 'X1', 'sap.receipt.costing_code2' => null, 'sap.receipt.costing_code3' => '']);
        $this->app->forgetInstance(SapGateway::class);
        Http::fake($this->fakes([self::URL.'/InventoryGenEntries' => Http::response(['DocEntry' => 1, 'DocNum' => 1], 201)]));

        $this->gateway()->createProductionReceipt($this->request());

        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/InventoryGenEntries'
            && $r['Comments'] === self::UUID
            && $r['DocumentLines'][0]['CostingCode'] === 'X1'
            && ! array_key_exists('CostingCode2', $r['DocumentLines'][0])
            && ! array_key_exists('CostingCode3', $r['DocumentLines'][0]));

        config(['sap.receipt.correlation_field' => "U_X eq 'a' or 1"]);
        $this->app->forgetInstance(SapGateway::class);
        $this->expectException(InvalidArgumentException::class);
        app(SapGateway::class);
    }

    public function test_a_clear_sap_error_is_rejected_and_anything_that_may_have_landed_is_uncertain(): void
    {
        Http::fake($this->fakes([self::URL.'/InventoryGenEntries' => Http::sequence()
            ->push(['error' => ['code' => -5002, 'message' => ['lang' => 'en-us', 'value' => 'Batch number already exists']]], 400)
            ->push(['error' => ['code' => '-1', 'message' => 'Internal error']], 500)
            ->push([], 504)
            ->push(['error' => ['code' => 429, 'message' => 'Too many requests']], 429)
            ->push(['DocEntry' => null], 201),
        ]));

        $rejected = $this->gateway()->createProductionReceipt($this->request());
        $this->assertSame([WriteOutcome::Rejected, 'SAP_-5002', 'Batch number already exists', 400], [$rejected->outcome, $rejected->errorCode, $rejected->errorMessage, $rejected->httpStatus]);

        foreach ([500, 504, 429, 201] as $status) {
            $result = $this->gateway()->createProductionReceipt($this->request());
            $this->assertSame(WriteOutcome::Uncertain, $result->outcome, "HTTP {$status}");
        }
    }

    public function test_a_timeout_is_uncertain_never_rejected(): void
    {
        Http::fake($this->fakes([self::URL.'/InventoryGenEntries' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]));

        $result = $this->gateway()->createProductionReceipt($this->request());

        $this->assertSame([WriteOutcome::Uncertain, 'TIMEOUT'], [$result->outcome, $result->errorCode]);
    }

    public function test_a_failed_login_sends_nothing_and_is_uncertain(): void
    {
        Http::fake([self::URL.'/Login' => Http::response(['error' => ['code' => 100000027, 'message' => ['value' => 'Fail to get DB Credentials']]], 401)]);
        $result = $this->gateway()->createProductionReceipt($this->request());
        $this->assertSame([WriteOutcome::Uncertain, 'SAP_LOGIN'], [$result->outcome, $result->errorCode]);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/InventoryGenEntries'));
    }

    public function test_an_expired_session_logs_in_again_once(): void
    {
        Http::fake($this->fakes([self::URL.'/InventoryGenEntries' => Http::sequence()
            ->push(['error' => ['code' => 301, 'message' => ['value' => 'Invalid session']]], 401)
            ->push(['DocEntry' => 7, 'DocNum' => 8], 201),
        ]));

        $this->assertSame(WriteOutcome::Created, $this->gateway()->createProductionReceipt($this->request())->outcome);
        Http::assertSentCount(4);
    }

    public function test_an_order_without_its_sap_doc_entry_is_refused_before_calling_sap(): void
    {
        Http::fake();

        $result = $this->gateway()->createProductionReceipt($this->request(docEntry: null));

        $this->assertSame([WriteOutcome::Rejected, 'NO_DOC_ENTRY'], [$result->outcome, $result->errorCode]);
        Http::assertNothingSent();
    }

    public function test_a_receipt_is_found_by_its_correlation_and_read_lot_by_lot(): void
    {
        Http::fake($this->fakes([
            self::URL.'/InventoryGenEntries?*' => Http::response(['value' => [['DocEntry' => 9001]]]),
            self::URL.'/InventoryGenEntries(9001)' => Http::response([
                'DocEntry' => 9001, 'DocNum' => 5001, 'DocDate' => '2026-10-21T00:00:00Z', 'U_TRZ_UUID' => self::UUID,
                'DocumentLines' => [
                    ['LineNum' => 0, 'ItemCode' => 'PT1350023', 'WarehouseCode' => '04', 'BaseEntry' => 41659, 'Quantity' => 5555.0,
                        'BatchNumbers' => [['BatchNumber' => '33128294', 'Quantity' => 5555.0, 'ExpiryDate' => '2027-04-21T00:00:00Z', 'ManufacturingDate' => '2026-10-21T00:00:00Z']]],
                    ['LineNum' => 1, 'ItemCode' => 'PT1350023', 'WarehouseCode' => '04', 'BaseEntry' => 41659, 'Quantity' => 12.5,
                        'BatchNumbers' => [['BatchNumber' => '33128295', 'Quantity' => 12.5, 'ExpiryDate' => '2027-04-22', 'ManufacturingDate' => '2026-10-21']]],
                ],
            ]),
        ]));

        $document = $this->gateway()->findProductionReceiptByCorrelation(self::UUID);

        $this->assertNotNull($document);
        $this->assertSame([9001, 5001, 41659, '2026-10-21'], [$document->docEntry, $document->docNum, $document->productionOrderDocEntry, $document->postingDate?->toDateString()]);
        $this->assertSame(
            [[0, '33128294', '5555', '2027-04-21', '2026-10-21'], [1, '33128295', '12.5', '2027-04-22', '2026-10-21']],
            array_map(fn ($l) => [$l->lineNum, $l->lotCode, $l->quantity->value, $l->expirationDate?->toDateString(), $l->manufacturingDate?->toDateString()], $document->lines),
        );
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), "\$filter=U_TRZ_UUID eq '".self::UUID."'"));
    }

    public function test_not_found_is_null_and_not_knowing_is_never_null(): void
    {
        Http::fake($this->fakes([self::URL.'/InventoryGenEntries?*' => Http::sequence()
            ->push(['value' => []])
            ->push(['value' => [['DocEntry' => 1], ['DocEntry' => 2]]])
            ->push(['error' => ['code' => -1, 'message' => ['value' => 'Invalid property U_TRZ_UUID']]], 400)
            ->push([], 503),
        ]));

        $this->assertNull($this->gateway()->findProductionReceiptByCorrelation(self::UUID));

        foreach (['más de un recibo', 'Invalid property U_TRZ_UUID', '503'] as $expected) {
            try {
                $this->gateway()->findProductionReceiptByCorrelation(self::UUID);
                $this->fail('Debía lanzar SapLookupFailed');
            } catch (SapLookupFailed $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_a_production_order_is_read_from_production_orders_with_its_product(): void
    {
        Http::fake($this->fakes([
            self::URL.'/ProductionOrders?*' => Http::response(['value' => [[
                'AbsoluteEntry' => 41659, 'DocumentNumber' => 33128, 'ProductionOrderStatus' => 'boposReleased', 'ProductionOrderType' => 'bopotStandard',
                'ItemNo' => 'PT1350023', 'PlannedQuantity' => 800.0, 'Warehouse' => '04', 'Priority' => 100,
                'PostingDate' => '2026-02-17T00:00:00Z', 'StartDate' => '2026-02-17T00:00:00Z', 'DueDate' => '2026-02-24T00:00:00Z', 'ProductionOrderOrigin' => 'bopooManual',
            ]]]),
            self::URL."/Items('PT1350023')*" => Http::response(['ItemCode' => 'PT1350023', 'ItemName' => 'GELATINA OLIMPICA SIN SABOR 30 GR', 'BarCode' => '12000013500233', 'InventoryUOM' => 'UNIDAD']),
        ]));

        $order = $this->gateway()->findProductionOrder('33128');

        $this->assertNotNull($order);
        $this->assertSame(
            [41659, '33128', ProductionOrderStatus::Released, 'Estándar', 'PT1350023', 'GELATINA OLIMPICA SIN SABOR 30 GR', '800', 'UNIDAD', '04', 100, '2026-02-24', '12000013500233'],
            [$order->docEntry, $order->docNum, $order->status, $order->type, $order->itemCode, $order->itemDescription, $order->plannedQuantity->value, $order->uomName, $order->warehouse, $order->priority, $order->dueDate?->toDateString(), $order->itemBarcode],
        );
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), '$filter=DocumentNumber eq 33128'));
    }

    public function test_the_released_orders_follow_every_page(): void
    {
        $row = fn (int $n) => ['AbsoluteEntry' => $n + 1000, 'DocumentNumber' => $n, 'ProductionOrderStatus' => 'boposReleased', 'ProductionOrderType' => 'bopotStandard', 'ItemNo' => 'PT1350023', 'PlannedQuantity' => 100, 'Warehouse' => '04'];
        Http::fake($this->fakes([
            self::URL.'/ProductionOrders?*' => Http::sequence()
                ->push(['value' => [$row(1), $row(2)], 'odata.nextLink' => "ProductionOrders?\$skip=2&\$filter=ProductionOrderStatus eq 'boposReleased'"])
                ->push(['value' => [$row(3)]]),
            self::URL."/Items('PT1350023')*" => Http::response(['ItemCode' => 'PT1350023', 'ItemName' => 'GELATINA', 'InventoryUOM' => 'UNIDAD']),
        ]));

        $orders = $this->gateway()->releasedProductionOrders();

        $this->assertSame(['1', '2', '3'], array_map(fn ($o) => $o->docNum, $orders));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), "\$filter=ProductionOrderStatus eq 'boposReleased'"));
        // The product is read once for the three orders.
        $this->assertCount(1, array_filter(Http::recorded()->all(), fn (array $pair) => str_contains($pair[0]->url(), 'Items(')));
    }

    public function test_an_unknown_order_status_or_an_unreachable_sap_is_never_taken_as_an_answer(): void
    {
        Http::fake($this->fakes([
            self::URL.'/ProductionOrders?*' => Http::sequence()
                ->push(['value' => [['AbsoluteEntry' => 1, 'DocumentNumber' => 1, 'ProductionOrderStatus' => 'boposSomethingNew', 'ItemNo' => 'PT1', 'PlannedQuantity' => 1]]])
                ->pushFailedConnection(),
            self::URL."/Items('PT1')*" => Http::response(['ItemCode' => 'PT1', 'ItemName' => 'X']),
        ]));

        foreach (['Estado de orden de fabricación desconocido', 'No se pudo consultar SAP'] as $expected) {
            try {
                $this->gateway()->findProductionOrder('1');
                $this->fail('Debía lanzar SapLookupFailed');
            } catch (SapLookupFailed $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_transfers_are_not_connected_and_never_send_anything(): void
    {
        Http::fake();

        $result = $this->gateway()->createInventoryTransfer(new InventoryTransferRequest(self::UUID, '04', '05', [new DocumentLine('PT1', 'L1', new Quantity('1'), '05')]));

        $this->assertSame([WriteOutcome::Rejected, 'NOT_CONNECTED'], [$result->outcome, $result->errorCode]);
        Http::assertNothingSent();
    }

    public function test_credentials_are_required_and_never_logged(): void
    {
        Log::spy();
        Http::fake($this->fakes([self::URL.'/InventoryGenEntries' => Http::response(['DocEntry' => 1, 'DocNum' => 1], 201)]));
        $this->gateway()->createProductionReceipt($this->request());

        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => str_contains((string) $m, 's3cr3t'))]);
        $this->assertStringNotContainsString('s3cr3t-no-log', (string) json_encode($this->gateway()));

        config(['sap.service_layer.password' => null]);
        $this->app->forgetInstance(SapGateway::class);
        $this->expectExceptionMessage('Faltan datos de conexión a SAP Service Layer');
        app(SapGateway::class);
    }

    public function test_the_network_probe_says_where_the_connection_breaks(): void
    {
        $closed = NetworkProbe::run('https://127.0.0.1:1/b1s/v1', 2);
        $this->assertFalse(end($closed)['ok']);
        $this->assertStringContainsString('No se pudo abrir conexión TCP a 127.0.0.1:1', end($closed)['text']);
        $this->assertStringContainsString('"localhost" no es el servidor de SAP', $closed[1]['text']);

        $this->assertFalse(NetworkProbe::run('servidor:50000')[0]['ok']);
        $this->assertNull(NetworkProbe::run('https://sap.test/api', 1, [])[1]['ok']);

        // The proxy is reported without its credentials, and NO_PROXY is honoured.
        $env = ['HTTPS_PROXY' => 'http://user:pass@proxy.corp:3128', 'NO_PROXY' => 'localhost,.interno.local'];
        $this->assertSame('http://proxy.corp:3128', NetworkProbe::proxyFor('https', 'sap.empresa.com', $env));
        $this->assertNull(NetworkProbe::proxyFor('https', 'sap.interno.local', $env));
        $this->assertNull(NetworkProbe::proxyFor('https', 'sap.empresa.com', []));
    }

    public function test_the_check_command_stops_when_the_network_does_not_reach_sap(): void
    {
        config(['sap.service_layer.base_url' => 'https://127.0.0.1:1/b1s/v1']);
        $this->app->forgetInstance(SapGateway::class);
        Http::fake();

        $this->assertSame(1, Artisan::call('sap:check'));
        $this->assertStringContainsString('No hay conexión de red con Service Layer', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_the_check_command_reads_the_released_orders(): void
    {
        Http::fake($this->fakes([
            self::URL.'/ProductionOrders?*' => Http::response(['value' => [['AbsoluteEntry' => 41659, 'DocumentNumber' => 33128, 'ProductionOrderStatus' => 'boposReleased', 'ItemNo' => 'PT1350023', 'PlannedQuantity' => 800, 'Warehouse' => '04']]]),
            self::URL."/Items('PT1350023')*" => Http::response(['ItemCode' => 'PT1350023', 'ItemName' => 'GELATINA OLIMPICA SIN SABOR 30 GR', 'InventoryUOM' => 'UNIDAD']),
        ]));

        $this->assertSame(0, Artisan::call('sap:check', ['--skip-network' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('OF liberadas en SAP: 1', $output);
        $this->assertStringContainsString('33128', $output);
        $this->assertStringNotContainsString('s3cr3t-no-log', $output);
    }
}
