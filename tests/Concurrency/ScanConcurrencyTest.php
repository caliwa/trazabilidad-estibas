<?php

namespace Tests\Concurrency;

use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Enums\Role;
use App\Models\Device;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * RN-02, RN-06, RN-12 and CA-04 with real parallel processes, each with its
 * own PostgreSQL connection. Nothing is mocked: the advisory lock, the
 * FOR UPDATE and the unique indexes are what keep the numbers right.
 */
class ScanConcurrencyTest extends ConcurrencyTestCase
{
    private function order(int $boxes): ProductionOrder
    {
        $item = Item::factory()->create();
        PalletStandard::factory()->for($item)->boxes($boxes)->create();

        return ProductionOrder::factory()->for($item)->create();
    }

    /**
     * Checks one order + lot combination (the item comes with the order).
     */
    private function assertPalletInvariants(ProductionOrder $order, string $lot): void
    {
        $pallets = Pallet::query()->where('production_order_id', $order->id)->where('lot_code', $lot)->get();

        $this->assertLessThanOrEqual(1, $pallets->where('physical_status', PhysicalStatus::Stacking)->count(), 'Más de una estiba abierta');

        foreach ($pallets as $pallet) {
            $counted = PalletScanEvent::query()->where('pallet_id', $pallet->id)->where('processing_status', ScanEventStatus::Accepted)->count();
            $this->assertSame($counted, $pallet->boxes_count, "La estiba {$pallet->pallet_code} no cuadra con sus eventos");

            match ($pallet->close_type) {
                CloseType::Automatic => $this->assertSame($pallet->standard_boxes_snapshot, $pallet->boxes_count),
                CloseType::Manual => $this->assertTrue($pallet->boxes_count >= 1 && $pallet->boxes_count < $pallet->standard_boxes_snapshot),
                null => $this->assertSame(PhysicalStatus::Stacking, $pallet->physical_status),
            };
        }
    }

    private function assertCodesHaveNoGapsOrRepeats(): void
    {
        $numbers = Pallet::query()->orderBy('id')->pluck('pallet_code')
            ->map(fn (string $code) => (int) substr($code, strrpos($code, '-') + 1))
            ->sort()->values()->all();

        $this->assertSame(range(1, count($numbers)), $numbers);
    }

    public function test_parallel_scans_of_one_combination_fill_pallets_exactly(): void
    {
        $order = $this->order(boxes: 40);
        $device = Device::factory()->create();
        $workers = 6;
        $perWorker = 30;

        $summaries = $this->runInParallel(array_map(fn (int $w) => [
            'mode' => 'scan', 'device_id' => $device->id, 'order' => $order->ref, 'item' => $order->item->code, 'lot' => 'L-1',
            'events' => array_map(fn (int $i) => "W{$w}-{$i}", range(1, $perWorker)),
        ], range(1, $workers)));

        $this->assertSame($workers * $perWorker, $this->total($summaries, 'accepted'));
        $this->assertSame(180, (int) Pallet::query()->sum('boxes_count'));
        $this->assertSame(4, Pallet::query()->where('physical_status', PhysicalStatus::Finished)->where('boxes_count', 40)->count());
        $this->assertSame(20, Pallet::query()->where('physical_status', PhysicalStatus::Stacking)->sole()->boxes_count);
        $this->assertPalletInvariants($order, 'L-1');
        $this->assertCodesHaveNoGapsOrRepeats();
    }

    public function test_the_same_events_sent_by_many_workers_count_once(): void
    {
        $order = $this->order(boxes: 50);
        $device = Device::factory()->create();
        $events = array_map(fn (int $i) => "E-{$i}", range(1, 40));

        $summaries = $this->runInParallel(array_fill(0, 6, [
            'mode' => 'scan', 'device_id' => $device->id, 'order' => $order->ref, 'item' => $order->item->code, 'lot' => 'L-1', 'events' => $events,
        ]));

        $this->assertSame(40, $this->total($summaries, 'accepted'));
        $this->assertSame(200, $this->total($summaries, 'duplicate'));
        $this->assertSame(40, Pallet::sole()->boxes_count);
        $this->assertSame(40, PalletScanEvent::query()->count());
    }

    public function test_parallel_combinations_never_mix_and_share_one_gapless_sequence(): void
    {
        $orders = [$this->order(boxes: 7), $this->order(boxes: 7)];
        $device = Device::factory()->create();

        $jobs = [];
        foreach ($orders as $o => $order) {
            foreach (['L-1', 'L-2'] as $lot) {
                $jobs[] = [
                    'mode' => 'scan', 'device_id' => $device->id, 'order' => $order->ref, 'item' => $order->item->code, 'lot' => $lot,
                    'events' => array_map(fn (int $i) => "O{$o}-{$lot}-{$i}", range(1, 25)),
                ];
            }
        }

        $this->runInParallel($jobs);

        foreach ($orders as $order) {
            foreach (['L-1', 'L-2'] as $lot) {
                $pallets = Pallet::query()->where('production_order_id', $order->id)->where('lot_code', $lot)->get();
                $this->assertSame(25, (int) $pallets->sum('boxes_count'));
                $this->assertSame(4, $pallets->count()); // 7 + 7 + 7 + 4
                $this->assertTrue($pallets->every(fn (Pallet $p) => $p->item_code === $order->item->code && $p->production_order_ref === $order->ref));
            }
            $this->assertPalletInvariants($order, 'L-1');
            $this->assertPalletInvariants($order, 'L-2');
        }

        $this->assertCodesHaveNoGapsOrRepeats();
    }

    public function test_manual_closes_racing_scans_never_lose_or_overcount_boxes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $order = $this->order(boxes: 10);
        $device = Device::factory()->create();
        $closer = User::factory()->create()->assignRole(Role::ProductionOperator->value);

        $jobs = array_map(fn (int $w) => [
            'mode' => 'scan', 'device_id' => $device->id, 'order' => $order->ref, 'item' => $order->item->code, 'lot' => 'L-1',
            'events' => array_map(fn (int $i) => "W{$w}-{$i}", range(1, 30)),
        ], range(1, 4));
        $jobs[] = ['mode' => 'close', 'user_id' => $closer->id, 'order' => $order->ref, 'item' => $order->item->code, 'lot' => 'L-1', 'rounds' => 60];

        $summaries = $this->runInParallel($jobs);

        $this->assertSame(120, $this->total($summaries, 'accepted'));
        $this->assertSame(120, (int) Pallet::query()->sum('boxes_count'));
        $this->assertSame($this->total($summaries, 'closed'), Pallet::query()->where('close_type', CloseType::Manual)->count());
        $this->assertPalletInvariants($order, 'L-1');
        $this->assertCodesHaveNoGapsOrRepeats();
    }
}
