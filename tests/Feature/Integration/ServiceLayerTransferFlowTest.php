<?php

namespace Tests\Feature\Integration;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\InventoryTransferLifecycle;
use App\Enums\Role;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTransfers;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The stock transfer 04 → 05 with the Service Layer connector: a transfer
 * that failed while it was not connected is retried from the pallet
 * detail, goes to StockTransfers and is confirmed with the document SAP
 * answers.
 */
class ServiceLayerTransferFlowTest extends TestCase
{
    use BuildsTransfers, CreatesUsers, RefreshDatabase;

    private const URL = 'https://sap.test:50000/b1s/v1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
        $this->travelTo('2026-10-23 09:30');
    }

    /** Received in 04 and counted, but SAP refused the transfer: it waits for a retry. */
    private function palletWithFailedTransfer(): Pallet
    {
        $this->sap()->queueTransferScenarios(FakeScenario::Reject);
        $pallet = $this->auditedPallets(['L-1'], unitsPerBox: '24')->sole();
        $this->assertSame(TransferStatus::Failed, $pallet->transfer_status);

        config([
            'sap.driver' => 'service_layer',
            'sap.service_layer.base_url' => self::URL,
            'sap.service_layer.company_db' => 'SBO_DEV',
            'sap.service_layer.username' => 'trazabilidad',
            'sap.service_layer.password' => 'secret',
        ]);
        $this->app->forgetInstance(SapGateway::class);

        return $pallet;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Pallet $pallet, float $quantity): array
    {
        return [
            'DocEntry' => 7001, 'DocNum' => 3001, 'DocDate' => '2026-10-23T00:00:00Z', 'FromWarehouse' => '04', 'ToWarehouse' => '05',
            'StockTransferLines' => [[
                'LineNum' => 0, 'ItemCode' => $pallet->item_code, 'Quantity' => $quantity, 'FromWarehouseCode' => '04', 'WarehouseCode' => '05',
                'BatchNumbers' => [['BatchNumber' => 'L-1', 'Quantity' => $quantity, 'BaseLineNumber' => 0]],
            ]],
        ];
    }

    public function test_a_failed_transfer_retried_goes_to_stock_transfers_and_is_confirmed(): void
    {
        $pallet = $this->palletWithFailedTransfer();
        $units = $pallet->boxes_count * 24;
        Http::fake(fn (Request $r) => match (true) {
            str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200),
            $r->method() === 'POST' && str_ends_with($r->url(), '/StockTransfers') => Http::response($this->document($pallet, $units), 201),
            default => Http::response([], 404),
        });

        [$transfer] = $this->retryTransfers($this->admin, [$pallet]);

        $transfer->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $transfer->status, json_encode($transfer->reconciliation) ?: '');
        $this->assertSame([7001, 3001], [$transfer->sap_doc_entry, $transfer->sap_doc_num]);
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/StockTransfers')
            && $r['FromWarehouse'] === '04' && $r['ToWarehouse'] === '05' && $r['CardCode'] === 'P811022882'
            && $r['StockTransferLines'][0]['ItemCode'] === $pallet->item_code
            && $r['StockTransferLines'][0]['Quantity'] === $units
            && $r['StockTransferLines'][0]['BatchNumbers'][0] === ['BatchNumber' => 'L-1', 'Quantity' => $units, 'BaseLineNumber' => 0]);
    }

    public function test_a_transfer_that_sap_moved_with_another_quantity_is_never_confirmed(): void
    {
        $pallet = $this->palletWithFailedTransfer();
        Http::fake(fn (Request $r) => match (true) {
            str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200),
            $r->method() === 'POST' => Http::response($this->document($pallet, 1.0), 201),
            default => Http::response([], 404),
        });

        [$transfer] = $this->retryTransfers($this->admin, [$pallet]);

        $transfer->refresh();
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->status);
        $this->assertTrue($transfer->reconciliation['mismatch']);
        $this->assertNull($transfer->sap_doc_num);
    }

    public function test_a_transfer_without_an_answer_stays_pending_and_is_never_resent(): void
    {
        $pallet = $this->palletWithFailedTransfer();
        Http::fake(fn (Request $r) => match (true) {
            str_ends_with($r->url(), '/Login') => Http::response(['SessionId' => 's1'], 200),
            default => Http::response(['error' => ['code' => -1, 'message' => ['value' => 'Internal error']]], 500),
        });

        [$transfer] = $this->retryTransfers($this->admin, [$pallet]);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->refresh()->status);

        // No correlation field on the transfer: reconciling cannot prove anything, so nothing is resent.
        $this->travel(2)->hours();
        app(InventoryTransferLifecycle::class)->reconcile($transfer->id);

        $transfer->refresh();
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->status);
        $this->assertStringContainsString('no lleva campo de correlación', (string) ($transfer->reconciliation['last_error'] ?? ''));
        Http::assertSentCount(2);
    }
}
