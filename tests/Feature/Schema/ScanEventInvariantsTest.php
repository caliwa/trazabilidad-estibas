<?php

namespace Tests\Feature\Schema;

use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsDatabaseRejects;
use Tests\TestCase;

/**
 * RN-02: an event is processed at most once.
 */
class ScanEventInvariantsTest extends TestCase
{
    use AssertsDatabaseRejects, RefreshDatabase;

    public function test_an_event_id_is_unique_per_device(): void
    {
        $device = Device::factory()->create();
        PalletScanEvent::factory()->for($device)->create(['external_event_id' => 'E-1']);

        $this->assertDatabaseRejects(fn () => PalletScanEvent::factory()->for($device)->create(['external_event_id' => 'E-1']), 'pallet_scan_events_device_id_external_event_id_unique');

        // The same id from another device is another event (scope pending in DP-01).
        PalletScanEvent::factory()->create(['external_event_id' => 'E-1']);
        $this->assertSame(2, PalletScanEvent::count());
    }

    public function test_accepted_events_point_to_a_pallet_and_rejected_ones_say_why(): void
    {
        $this->assertDatabaseRejects(
            fn () => PalletScanEvent::factory()->create(['processing_status' => ScanEventStatus::Accepted, 'rejection_reason' => null, 'processed_at' => now()]),
            'pallet_scan_events_outcome_check',
        );

        $this->assertDatabaseRejects(
            fn () => PalletScanEvent::factory()->create(['rejection_reason' => null]),
            'pallet_scan_events_outcome_check',
        );
    }

    public function test_a_rejected_event_can_be_accepted_once_and_then_never_changes(): void
    {
        $event = PalletScanEvent::factory()->create();
        $pallet = Pallet::factory()->create();

        $event->forceFill(['processing_status' => ScanEventStatus::Accepted, 'pallet_id' => $pallet->id, 'rejection_reason' => null, 'processed_at' => now()])->save();

        $this->assertDatabaseRejects(fn () => $event->forceFill(['pallet_id' => Pallet::factory()->create()->id])->save(), 'no se puede modificar');
        $this->assertDatabaseRejects(fn () => $event->delete(), 'no_delete');
    }

    public function test_reprocessing_cannot_rewrite_what_the_sensor_sent(): void
    {
        $event = PalletScanEvent::factory()->create();
        $pallet = Pallet::factory()->create();

        $this->assertDatabaseRejects(
            fn () => $event->forceFill(['processing_status' => ScanEventStatus::Accepted, 'pallet_id' => $pallet->id, 'rejection_reason' => null, 'processed_at' => now(), 'lot_code' => 'OTRO'])->save(),
            'no se puede modificar',
        );
    }
}
