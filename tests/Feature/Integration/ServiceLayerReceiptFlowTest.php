<?php

namespace Tests\Feature\Integration;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Enums\Role;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsReceipts;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The whole production receipt with the Service Layer connector: request,
 * POST InventoryGenEntries, reading it back by its correlation and the
 * comparison before confirming.
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

    public function test_a_receipt_is_created_in_sap_read_back_and_confirmed(): void
    {
        $pallet = $this->pallet();
        $uuid = null;
        Http::fake(function (Request $r) use ($pallet, &$uuid) {
            return match (true) {
                str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200, ['Set-Cookie' => 'B1SESSION=s1']),
                $r->method() === 'POST' && str_ends_with($r->url(), '/InventoryGenEntries') => (function () use ($r, &$uuid) {
                    $uuid = $r['U_TRZ_UUID'];

                    return Http::response(['DocEntry' => 9001, 'DocNum' => 5001], 201);
                })(),
                str_contains($r->url(), '/InventoryGenEntries?') => Http::response(['value' => [['DocEntry' => 9001]]]),
                str_ends_with($r->url(), '/InventoryGenEntries(9001)') => Http::response($this->document($pallet, (string) $uuid, '2028-10-20T00:00:00Z')),
                default => Http::response([], 404),
            };
        });

        [$receipt] = $this->requestReceipts($this->admin, [$pallet]);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $receipt->status, json_encode($receipt->reconciliation) ?: '');
        $this->assertSame([9001, 5001], [$receipt->sap_doc_entry, $receipt->sap_doc_num]);
        $this->assertSame($receipt->request_uuid, $uuid);
        $this->assertSame(ReceiptStatus::Confirmed, $pallet->refresh()->receipt_status);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/InventoryGenEntries')
            && $r['DocDate'] === '2026-10-21'
            && $r['DocumentLines'][0]['BaseEntry'] === 41659
            && $r['DocumentLines'][0]['Quantity'] === 480
            && $r['DocumentLines'][0]['BatchNumbers'][0] === ['BaseLineNumber' => 0, 'BatchNumber' => 'L-1', 'Quantity' => 480, 'ManufacturingDate' => '2026-10-21', 'ExpiryDate' => '2028-10-20', 'AddmisionDate' => '2026-10-21']);
    }

    public function test_a_receipt_with_other_batch_dates_in_sap_is_never_confirmed(): void
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
                str_contains($r->url(), '/InventoryGenEntries?') => Http::response(['value' => [['DocEntry' => 9001]]]),
                default => Http::response($this->document($pallet, (string) $uuid, '2027-01-01')),
            };
        });

        [$receipt] = $this->requestReceipts($this->admin, [$pallet]);

        $receipt->refresh();
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $receipt->status);
        $this->assertTrue($receipt->reconciliation['mismatch']);
        $this->assertStringContainsString('Fecha de vencimiento distinta', $receipt->reconciliation['differences'][0]);
        $this->assertNull($receipt->sap_doc_entry);
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
