<?php

namespace Tests\Feature\Api;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\TraceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * POST /api/v1/scan-events: a device sends the box it read (QR text, token
 * or code). Same rules as every other read; each box counts once.
 */
class ScanEventApiTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const TOKEN = 'token-de-prueba-0123456789abcdef0123456789abcdef';

    private Device $device;

    private BoxLabelBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['traceability.test_pallet_boxes' => 2, 'traceability.decisions.scan_ingestion_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $this->device = Device::factory()->withToken(self::TOKEN)->create();

        $operator = $this->userWithRole(Role::ProductionOperator);
        $ctx = TraceContext::forUser($operator, Origin::Web, (string) Str::uuid());
        app(SyncReleasedOrders::class)->handle(null, $ctx);
        $order = app(ConsultProductionOrder::class)->handle($operator, '33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        $this->batch = app(GenerateBoxLabels::class)->handle($operator, $order->id, 5, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2028-10-20'), $ctx);
    }

    private function label(int $n): BoxLabel
    {
        return BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->where('sequence', $n)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $box = 1, array $overrides = []): array
    {
        return [
            'event_id' => BoxQr::payload((string) $this->label($box)->qr_token),
            'production_order' => '33128',
            'item_code' => 'PT1350023',
            'lot' => '33128293',
            'occurred_at' => now()->toIso8601String(),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function send(array $payload, string $token = self::TOKEN, array $headers = []): TestResponse
    {
        return $this->withToken($token)->postJson(route('api.scan-events.store'), $payload, $headers);
    }

    public function test_an_accepted_box_answers_201_with_the_pallet(): void
    {
        $this->send($this->payload(1))
            ->assertCreated()
            ->assertExactJson(['status' => 'accepted', 'box_code' => '33128293-0001', 'pallet_code' => 'EST-00000001', 'boxes_count' => 1, 'standard' => 2, 'pallet_closed' => false]);

        $this->send($this->payload(2))->assertCreated()->assertJson(['boxes_count' => 2, 'pallet_closed' => true]);
        $this->assertSame($this->device->id, PalletScanEvent::query()->orderBy('id')->first()?->read_by_device_id);
    }

    public function test_the_same_box_sent_again_answers_200_and_changes_nothing(): void
    {
        $this->send($this->payload(1))->assertCreated();
        $this->send($this->payload(1))->assertOk()->assertJson(['status' => 'duplicate', 'box_code' => '33128293-0001']);
        // Its token alone or its code: still the same box.
        $this->send($this->payload(1, ['event_id' => $this->label(1)->qr_token]))->assertOk()->assertJson(['status' => 'duplicate']);
        $this->send($this->payload(1, ['event_id' => '33128293-0001']))->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, Pallet::sole()->boxes_count);
        $this->assertSame(1, PalletScanEvent::count());
    }

    public function test_an_event_that_does_not_name_a_printed_box_is_refused_and_counts_nothing(): void
    {
        $this->send($this->payload(1, ['event_id' => (string) Str::uuid()]))
            ->assertUnprocessable()
            ->assertJson(['status' => 'rejected', 'rejection' => 'NO_ES_CAJA']);

        $this->send($this->payload(1, ['lot' => 'OTRO']))
            ->assertUnprocessable()
            ->assertJson(['status' => 'rejected', 'rejection' => 'DATOS_DISTINTOS_A_LA_CAJA']);

        $this->assertSame(0, PalletScanEvent::count());
        $this->assertSame(0, Pallet::count());
    }

    public function test_malformed_requests_are_refused_without_storing_anything(): void
    {
        $this->send($this->payload(1, ['event_id' => '']))->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->send($this->payload(1, ['event_id' => 'con espacio']))->assertJsonValidationErrors('event_id');
        $this->send($this->payload(1, ['occurred_at' => 'ayer']))->assertJsonValidationErrors('occurred_at');
        $this->send($this->payload(1, ['occurred_at' => now()->addHour()->toIso8601String()]))->assertJsonValidationErrors('occurred_at');
        $this->send($this->payload(1, ['lot' => str_repeat('x', 51)]))->assertJsonValidationErrors('lot');

        $this->assertSame(0, PalletScanEvent::count());
    }

    public function test_unknown_wrong_and_inactive_devices_get_401(): void
    {
        $this->postJson(route('api.scan-events.store'), $this->payload())->assertUnauthorized();
        $this->send($this->payload(), 'otro-token')->assertUnauthorized();

        $this->device->forceFill(['active' => false])->save();
        $this->send($this->payload())->assertUnauthorized();

        $this->assertSame(0, PalletScanEvent::count());
    }

    public function test_ingestion_answers_503_while_dp01_is_open(): void
    {
        config(['traceability.decisions.scan_ingestion_enabled' => false]);

        $this->send($this->payload())->assertServiceUnavailable()->assertJson(['status' => 'disabled']);
        $this->assertSame(0, PalletScanEvent::count());
    }

    public function test_each_device_is_rate_limited(): void
    {
        config(['traceability.scan_rate_per_minute' => 2]);

        $this->send($this->payload(1))->assertCreated();
        $this->send($this->payload(2))->assertCreated();
        $this->send($this->payload(3))->assertTooManyRequests();
    }

    public function test_the_trace_carries_the_device_and_the_callers_correlation_id(): void
    {
        $correlation = (string) Str::uuid();

        $this->send($this->payload(), headers: ['X-Correlation-Id' => $correlation])->assertHeader('X-Correlation-Id', $correlation);

        $trace = TraceEvent::query()->where('event', 'pallet.created')->sole();
        $this->assertSame($correlation, $trace->correlation_id);
        $this->assertSame($this->device->id, $trace->device_id);
        $this->assertNotNull($this->device->refresh()->last_seen_at);
    }
}
