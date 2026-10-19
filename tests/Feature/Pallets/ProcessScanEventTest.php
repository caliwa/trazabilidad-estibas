<?php

namespace Tests\Feature\Pallets;

use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Domain\Pallets\Exceptions\ScanIngestionDisabled;
use App\Domain\Pallets\PalletCodeGenerator;
use App\Models\Device;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsProductionMasters;
use Tests\TestCase;

/**
 * Stacking engine: CA-01 to CA-06, RN-02 to RN-06.
 */
class ProcessScanEventTest extends TestCase
{
    use BuildsProductionMasters, RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableScanIngestion();
        $this->device = Device::factory()->create();
    }

    public function test_the_first_scan_creates_a_pallet_with_the_standard_frozen(): void
    {
        $order = $this->orderWithStandard(boxes: 4);

        $result = $this->process($this->device, $this->scan($order));

        $this->assertSame(ScanOutcome::Accepted, $result->outcome);
        $pallet = Pallet::sole();
        $this->assertSame('EST-00000001', $pallet->pallet_code);
        $this->assertSame(1, $pallet->boxes_count);
        $this->assertSame(4, $pallet->standard_boxes_snapshot);
        $this->assertSame($order->ref, $pallet->production_order_ref);
        $this->assertSame('L-1', $pallet->lot_code);
        $this->assertSame(32, strlen($pallet->qr_token));
        $this->assertSame(PhysicalStatus::Stacking, $pallet->physical_status);
        $this->assertSame(['pallet.created'], TraceEvent::pluck('event')->all());
        $this->assertSame($this->device->id, TraceEvent::sole()->device_id);
    }

    public function test_each_valid_scan_adds_exactly_one_box(): void
    {
        $order = $this->orderWithStandard(boxes: 10);

        foreach (range(1, 3) as $expected) {
            $this->assertSame($expected, $this->process($this->device, $this->scan($order))->boxesCount);
        }

        $this->assertSame(3, Pallet::sole()->boxes_count);
        $this->assertSame(3, PalletScanEvent::where('processing_status', ScanEventStatus::Accepted)->count());
    }

    public function test_a_repeated_event_is_not_counted_again(): void
    {
        $order = $this->orderWithStandard(boxes: 10);
        $event = $this->scan($order, eventId: 'E-1');

        $this->process($this->device, $event);
        $again = $this->process($this->device, $event);

        $this->assertSame(ScanOutcome::Duplicate, $again->outcome);
        $this->assertFalse($again->payloadMismatch);
        $this->assertSame(1, $again->boxesCount);
        $this->assertSame(1, Pallet::sole()->boxes_count);
        $this->assertSame(1, PalletScanEvent::count());
    }

    public function test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another(): void
    {
        $order = $this->orderWithStandard(boxes: 3);

        $results = array_map(fn () => $this->process($this->device, $this->scan($order)), range(1, 4));

        $this->assertSame([false, false, true, false], array_map(fn ($r) => $r->palletClosed, $results));
        [$first, $second] = Pallet::orderBy('id')->get()->all();
        $this->assertSame(PhysicalStatus::Finished, $first->physical_status);
        $this->assertSame(CloseType::Automatic, $first->close_type);
        $this->assertSame(3, $first->boxes_count);
        $this->assertSame(100.0, $first->completionPercent());
        $this->assertSame('EST-00000002', $second->pallet_code);
        $this->assertSame(1, $second->boxes_count);
        $this->assertSame(1, TraceEvent::where('event', 'pallet.closed_automatic')->count());
    }

    public function test_orders_items_and_lots_never_mix(): void
    {
        $orderA = $this->orderWithStandard(boxes: 10, ref: 'OP-A', itemCode: 'PT-A');
        $orderB = $this->orderWithStandard(boxes: 10, ref: 'OP-B', itemCode: 'PT-B');

        $this->process($this->device, $this->scan($orderA, 'L-1'));
        $this->process($this->device, $this->scan($orderA, 'L-2'));
        $this->process($this->device, $this->scan($orderB, 'L-1'));
        $this->process($this->device, $this->scan($orderA, 'L-1'));

        $pallets = Pallet::orderBy('id')->get(['production_order_ref', 'item_code', 'lot_code', 'boxes_count'])->map->only(['production_order_ref', 'item_code', 'lot_code', 'boxes_count'])->all();
        $this->assertSame([
            ['production_order_ref' => 'OP-A', 'item_code' => 'PT-A', 'lot_code' => 'L-1', 'boxes_count' => 2],
            ['production_order_ref' => 'OP-A', 'item_code' => 'PT-A', 'lot_code' => 'L-2', 'boxes_count' => 1],
            ['production_order_ref' => 'OP-B', 'item_code' => 'PT-B', 'lot_code' => 'L-1', 'boxes_count' => 1],
        ], $pallets);
    }

    public function test_a_missing_standard_rejects_the_event_and_keeps_it_with_the_reason(): void
    {
        $order = ProductionOrder::factory()->create(['ref' => 'OP-SIN']);

        $result = $this->process($this->device, $this->scan($order));

        $this->assertSame(ScanOutcome::Rejected, $result->outcome);
        $this->assertSame(ScanRejection::MissingStandard, $result->rejection);
        $this->assertSame(0, Pallet::count());
        $event = PalletScanEvent::sole();
        $this->assertSame(ScanEventStatus::Rejected, $event->processing_status);
        $this->assertSame('ESTANDAR_AUSENTE', $event->rejection_reason);
        $this->assertSame('scan.rejected', TraceEvent::sole()->event);
    }

    public function test_every_master_problem_has_its_own_reason(): void
    {
        $order = $this->orderWithStandard(boxes: 10);
        $closed = ProductionOrder::factory()->create(['is_open' => false]);
        $inactive = ProductionOrder::factory()->for(Item::factory()->state(['active' => false]))->create();
        $invalid = ProductionOrder::factory()->create();
        PalletStandard::factory()->for($invalid->item)->create(['status' => MasterStatus::Invalid, 'boxes_per_pallet' => null, 'invalid_reason' => 'vacío']);
        $expired = ProductionOrder::factory()->create();
        PalletStandard::factory()->for($expired->item)->create(['valid_to' => now()->subDay()]);

        $cases = [
            [new ScanEventData('E-X', 'OP-NO-EXISTE', $order->item->code, 'L-1', CarbonImmutable::now()), ScanRejection::UnknownOrder],
            [$this->scan($closed), ScanRejection::ClosedOrder],
            [$this->scan($order, itemCode: 'OTRO'), ScanRejection::ItemMismatch],
            [$this->scan($inactive), ScanRejection::InactiveItem],
            [$this->scan($order, lot: '../etc'), ScanRejection::InvalidLot],
            [$this->scan($invalid), ScanRejection::InvalidStandard],
            [$this->scan($expired), ScanRejection::ExpiredStandard],
        ];

        foreach ($cases as [$event, $reason]) {
            $this->assertSame($reason, $this->process($this->device, $event)->rejection, $reason->value);
        }

        $this->assertSame(0, Pallet::count());
        $this->assertSame(count($cases), PalletScanEvent::where('processing_status', ScanEventStatus::Rejected)->count());
    }

    public function test_a_rejected_event_sent_again_after_fixing_the_master_counts_once(): void
    {
        $order = ProductionOrder::factory()->create();
        $event = $this->scan($order, eventId: 'E-1');

        $this->assertSame(ScanOutcome::Rejected, $this->process($this->device, $event)->outcome);
        $this->assertSame(ScanOutcome::Rejected, $this->process($this->device, $event)->outcome);
        $this->assertSame(1, TraceEvent::where('event', 'scan.rejected')->count());

        PalletStandard::factory()->for($order->item)->boxes(5)->create();

        $this->assertSame(ScanOutcome::Accepted, $this->process($this->device, $event)->outcome);
        $this->assertSame(ScanOutcome::Duplicate, $this->process($this->device, $event)->outcome);

        $this->assertSame(1, Pallet::sole()->boxes_count);
        $stored = PalletScanEvent::sole();
        $this->assertSame(ScanEventStatus::Accepted, $stored->processing_status);
        $this->assertNull($stored->rejection_reason);
    }

    public function test_a_reused_event_id_with_different_content_is_flagged_and_not_counted(): void
    {
        $order = $this->orderWithStandard(boxes: 10);
        $this->process($this->device, $this->scan($order, 'L-1', 'E-1'));

        $result = $this->process($this->device, $this->scan($order, 'L-2', 'E-1'));

        $this->assertSame(ScanOutcome::Duplicate, $result->outcome);
        $this->assertTrue($result->payloadMismatch);
        $this->assertSame(1, Pallet::count());
        $this->assertSame(1, TraceEvent::where('event', 'scan.duplicate_mismatch')->count());
    }

    public function test_the_same_event_id_from_another_device_is_another_box(): void
    {
        $order = $this->orderWithStandard(boxes: 10);

        $this->process($this->device, $this->scan($order, eventId: 'E-1'));
        $this->process(Device::factory()->create(), $this->scan($order, eventId: 'E-1'));

        $this->assertSame(2, Pallet::sole()->boxes_count);
    }

    public function test_an_event_older_than_the_open_pallet_is_counted_there_and_flagged_late(): void
    {
        $order = $this->orderWithStandard(boxes: 10);
        $this->process($this->device, $this->scan($order, at: CarbonImmutable::now()->subMinutes(5)));

        $this->process($this->device, $this->scan($order, eventId: 'TARDE', at: CarbonImmutable::now()->subMinutes(10)));

        $this->assertSame(2, Pallet::sole()->boxes_count);
        $this->assertTrue(PalletScanEvent::where('external_event_id', 'TARDE')->sole()->is_late);
    }

    public function test_a_new_standard_applies_to_new_pallets_only(): void
    {
        $order = $this->orderWithStandard(boxes: 10);
        $this->process($this->device, $this->scan($order));

        PalletStandard::query()->update(['status' => MasterStatus::Superseded]);
        PalletStandard::factory()->for($order->item)->boxes(2)->create(['version' => 2]);

        $this->process($this->device, $this->scan($order));
        $this->assertSame([10, 2], [Pallet::sole()->standard_boxes_snapshot, Pallet::sole()->boxes_count]);

        $this->process($this->device, $this->scan($order, 'L-2'));
        $this->assertSame(2, Pallet::where('lot_code', 'L-2')->sole()->standard_boxes_snapshot);
    }

    public function test_nothing_is_processed_while_dp01_is_open(): void
    {
        config(['traceability.decisions.scan_ingestion_enabled' => false]);
        $order = $this->orderWithStandard();

        $this->expectException(ScanIngestionDisabled::class);

        try {
            $this->process($this->device, $this->scan($order));
        } finally {
            $this->assertSame(0, PalletScanEvent::count());
        }
    }

    public function test_a_rolled_back_pallet_gives_its_number_back(): void
    {
        $codes = app(PalletCodeGenerator::class);

        try {
            DB::transaction(function () use ($codes): void {
                $this->assertSame('EST-00000001', $codes->next());

                throw new RuntimeException('falla después de numerar');
            });
        } catch (RuntimeException) {
        }

        DB::transaction(fn () => $this->assertSame('EST-00000001', $codes->next()));
        DB::transaction(fn () => $this->assertSame('EST-00000002', $codes->next()));
    }
}
