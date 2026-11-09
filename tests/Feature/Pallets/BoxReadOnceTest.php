<?php

namespace Tests\Feature\Pallets;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Pallets\Actions\ProcessScanEvent;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * A box token counts once, whatever reads it: by hand, PDA, camera, the
 * fixed line reader, a device through the API, or any device writing the
 * box into the events table. The database itself refuses a second read.
 */
class BoxReadOnceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    private BoxLabelBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
        config(['traceability.test_pallet_boxes' => 40, 'traceability.decisions.scan_ingestion_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

        app(SyncReleasedOrders::class)->handle(null, $this->ctx());
        $order = app(ConsultProductionOrder::class)->handle($this->operator, '33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        $this->batch = app(GenerateBoxLabels::class)->handle($this->operator, $order->id, 5, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2028-10-20'), $this->ctx());
    }

    private function ctx(): TraceContext
    {
        return TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid());
    }

    private function label(int $n): BoxLabel
    {
        return BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->where('sequence', $n)->sole();
    }

    private function manual(int $n): void
    {
        $this->travel(1)->seconds();
        app(RegisterManualBoxScan::class)->handle($this->operator, BoxQr::payload((string) $this->label($n)->qr_token), $this->ctx());
    }

    public function test_every_read_is_tied_to_its_box_and_the_database_refuses_a_second_one_from_any_device(): void
    {
        $this->manual(1);
        $read = PalletScanEvent::sole();
        $this->assertSame($this->label(1)->id, $read->box_label_id);
        // The read is identified by the QR token, not by the printed box code.
        $this->assertSame($this->label(1)->qr_token, $read->external_event_id);
        $this->assertSame('33128293-0001', $read->boxCodeForDisplay());

        // Another device writing the same box, by its QR text or its token: refused by the database.
        $other = Device::factory()->create();
        foreach (['TRZB:'.$this->label(1)->qr_token, (string) $this->label(1)->qr_token, '  TRZB;'.strtoupper((string) $this->label(1)->qr_token)."\r\n"] as $eventId) {
            try {
                DB::transaction(fn () => DB::table('pallet_scan_events')->insert([
                    'device_id' => $other->id, 'external_event_id' => $eventId, 'production_order_ref' => '33128', 'item_code' => 'PT1350023', 'lot_code' => '33128293',
                    'event_occurred_at' => now(), 'received_at' => now(), 'processed_at' => now(), 'processing_status' => 'ACEPTADO',
                    'pallet_id' => $read->pallet_id, 'payload_hash' => str_repeat('a', 64),
                ]));
                $this->fail("La base aceptó una segunda lectura de la caja con «{$eventId}».");
            } catch (QueryException $e) {
                $this->assertStringContainsString('pallet_scan_events_one_per_box', $e->getMessage());
            }
        }

        $this->assertSame(1, PalletScanEvent::count());
        $this->assertSame(1, Pallet::sole()->boxes_count);
    }

    public function test_a_box_counted_by_a_sensor_cannot_be_read_again_by_hand_and_the_other_way_round(): void
    {
        $sensor = Device::factory()->create();
        $sensorRead = fn (string $eventId) => app(ProcessScanEvent::class)->handle($sensor, new ScanEventData($eventId, '33128', 'PT1350023', '33128293', CarbonImmutable::now()), TraceContext::forDevice($sensor, (string) Str::uuid()));

        // A box read by hand first loads the test pallet standard the sensor read needs.
        $this->manual(3);

        // Sensor first, naming the box by its QR token.
        $this->assertSame(ScanOutcome::Accepted, $sensorRead((string) $this->label(1)->qr_token)->outcome);
        try {
            $this->manual(1);
            $this->fail('La caja ya la contó el sensor.');
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString('ya fue leída', $e->getMessage());
        }

        // By hand first: the sensor naming the same box by its QR is a duplicate.
        $this->manual(2);
        $again = $sensorRead('TRZB:'.$this->label(2)->qr_token);
        $this->assertSame(ScanOutcome::Duplicate, $again->outcome);

        $this->assertSame(3, Pallet::sole()->boxes_count);
        $this->assertSame(3, PalletScanEvent::count());
    }

    public function test_the_api_and_the_station_share_the_one_read_of_each_box(): void
    {
        $device = Device::factory()->withToken('token-api-0123456789abcdef0123456789abcdef')->create();
        $send = fn (int $n) => $this->withToken('token-api-0123456789abcdef0123456789abcdef')->postJson(route('api.scan-events.store'), [
            'event_id' => BoxQr::payload((string) $this->label($n)->qr_token), 'production_order' => '33128', 'item_code' => 'PT1350023', 'lot' => '33128293', 'occurred_at' => now()->toIso8601String(),
        ]);

        $this->manual(1);
        $send(1)->assertOk()->assertJson(['status' => 'duplicate']);

        $send(2)->assertCreated();
        try {
            $this->manual(2);
            $this->fail('La caja ya la contó el dispositivo.');
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString('ya fue leída', $e->getMessage());
        }

        $this->assertSame(2, Pallet::sole()->boxes_count);
    }

    public function test_the_duplicates_report_is_clean(): void
    {
        $this->manual(1);
        $this->manual(2);

        $this->artisan('boxes:duplicates')->expectsOutputToContain('Ninguna caja tiene más de una lectura contada')->assertSuccessful();
    }
}
