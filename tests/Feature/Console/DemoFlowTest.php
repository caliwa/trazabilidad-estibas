<?php

namespace Tests\Feature\Console;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Integration\Enums\SapDocumentStatus;
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
use App\Models\ProductionReceipt;
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

        $this->assertSame(74, PalletScanEvent::query()->where('processing_status', 'ACEPTADO')->count());
        $this->assertSame(3, PalletScanEvent::query()->where('processing_status', 'RECHAZADO')->count());
        $this->assertSame(74, (int) Pallet::query()->sum('boxes_count'));

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
        ], $states);

        $this->assertSame(3, ProductionReceipt::query()->where('status', SapDocumentStatus::Confirmed)->count());
        $this->assertSame(3, InventoryTransfer::query()->where('status', SapDocumentStatus::Confirmed)->count());
        $this->assertSame(1, InventoryTransfer::query()->where('status', SapDocumentStatus::PendingReconciliation)->count());
        $this->assertSame(1, DiscrepancyCase::query()->count());

        // Box labels first, one batch per lot; every receipt line took its lot's dates from them.
        $this->assertSame(5, BoxLabelBatch::query()->count());
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
