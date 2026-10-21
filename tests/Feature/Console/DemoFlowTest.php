<?php

namespace Tests\Feature\Console;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Fake\FakeSapGateway;
use App\Models\BoxLabelBatch;
use App\Models\DiscrepancyCase;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\User;
use App\Notifications\OrderReadyToClose;
use App\Queries\OrderProgressQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DemoFlowTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_it_walks_the_whole_flow_with_the_real_use_cases(): void
    {
        $code = Artisan::call('demo:flow');
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);

        $this->assertSame(84, PalletScanEvent::query()->where('processing_status', 'ACEPTADO')->count());
        $this->assertSame(3, PalletScanEvent::query()->where('processing_status', 'RECHAZADO')->count());
        $this->assertSame(84, (int) Pallet::query()->sum('boxes_count'));

        $states = Pallet::query()->orderBy('id')->get()->map(fn (Pallet $p) => [$p->physical_status, $p->receipt_status, $p->audit_status, $p->transfer_status])->all();
        $this->assertEquals([
            [PhysicalStatus::Labeled, ReceiptStatus::Confirmed, AuditStatus::Approved, TransferStatus::Confirmed],
            [PhysicalStatus::Labeled, ReceiptStatus::Confirmed, AuditStatus::Approved, TransferStatus::Confirmed],
            [PhysicalStatus::Labeled, ReceiptStatus::Confirmed, AuditStatus::Discrepancy, TransferStatus::NotEligible],
            [PhysicalStatus::Labeled, ReceiptStatus::Confirmed, AuditStatus::Approved, TransferStatus::Confirmed],
            [PhysicalStatus::Labeled, ReceiptStatus::Confirmed, AuditStatus::Pending, TransferStatus::NotEligible],
            [PhysicalStatus::Labeled, ReceiptStatus::PendingReconciliation, AuditStatus::Pending, TransferStatus::NotEligible],
            [PhysicalStatus::Labeled, ReceiptStatus::Confirmed, AuditStatus::Approved, TransferStatus::PendingReconciliation],
            [PhysicalStatus::Palletized, ReceiptStatus::None, AuditStatus::Pending, TransferStatus::NotEligible],
            [PhysicalStatus::Stacking, ReceiptStatus::None, AuditStatus::Pending, TransferStatus::NotEligible],
            [PhysicalStatus::Labeled, ReceiptStatus::Confirmed, AuditStatus::Approved, TransferStatus::Confirmed],
            [PhysicalStatus::Labeled, ReceiptStatus::Confirmed, AuditStatus::Approved, TransferStatus::Confirmed],
        ], $states);

        $this->assertSame(4, ProductionReceipt::query()->where('status', SapDocumentStatus::Confirmed)->count());
        $this->assertSame(5, InventoryTransfer::query()->where('status', SapDocumentStatus::Confirmed)->count());
        $this->assertSame(1, InventoryTransfer::query()->where('status', SapDocumentStatus::PendingReconciliation)->count());
        $this->assertSame(1, DiscrepancyCase::query()->count());

        // OF 33134 was received and transferred in full: ready to close in SAP, alert sent.
        $complete = ProductionOrder::query()->where('ref', '33134')->sole();
        $progress = app(OrderProgressQuery::class)->forOrder($complete->id);
        $this->assertSame(OrderStage::ReadyToClose, $progress?->stage());
        $this->assertTrue($progress->allInCedi());
        // 33129 (planned), 33130 (closed) and 33133 (cancelled) are not brought in.
        $this->assertEqualsCanonicalizing(['33128', '33131', '33132', '33134'], ProductionOrder::query()->where('sap_status', 'LIBERADO')->pluck('ref')->all());
        $this->assertSame(0, ProductionOrder::query()->whereIn('ref', ['33129', '33130', '33133'])->count());
        $this->assertNotNull($complete->ready_to_close_notified_at);
        $this->assertSame(1, User::query()->where('email', 'supervisor@example.com')->sole()->notifications()->where('type', OrderReadyToClose::class)->count());

        // Box labels first, one batch per lot; every receipt line took its lot's dates from them.
        $this->assertSame(6, BoxLabelBatch::query()->count());
        $sap = app(SapGateway::class);
        $this->assertInstanceOf(FakeSapGateway::class, $sap);
        foreach ($sap->receipts() as $document) {
            foreach ($document->lines as $line) {
                $batch = BoxLabelBatch::query()->where('lot_code', $line->lotCode)->sole();
                $this->assertSame($batch->expiration_date->toDateString(), $line->expirationDate?->toDateString());
                $this->assertSame($document->postingDate?->toDateString(), $line->manufacturingDate?->toDateString());
            }
        }
        $this->assertStringContainsString('TRZ1:', $output);
    }

    public function test_the_pending_decisions_stay_off_after_it_runs(): void
    {
        config(['queue.default' => 'database']);
        Artisan::call('demo:flow');

        $this->assertFalse(config('traceability.decisions.scan_ingestion_enabled'));
        $this->assertEmpty(config('traceability.decisions.label_confirmation'));
        $this->assertSame('database', config('queue.default'));
    }

    public function test_it_runs_once_and_never_in_production(): void
    {
        Artisan::call('demo:flow');
        $this->assertSame(1, Artisan::call('demo:flow'));
        $this->assertStringContainsString('ya existen', Artisan::output());

        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('demo:flow'));
    }
}
