<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsProductionMasters;
use Tests\TestCase;

class ScanEventApiTest extends TestCase
{
    use BuildsProductionMasters, RefreshDatabase;

    private const TOKEN = 'token-de-prueba-0123456789abcdef0123456789abcdef';

    private Device $device;

    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableScanIngestion();
        $this->device = Device::factory()->withToken(self::TOKEN)->create();
        $this->order = $this->orderWithStandard(boxes: 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'production_order' => $this->order->ref,
            'item_code' => $this->order->item->code,
            'lot' => 'L-1',
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
        $this->send($this->payload())
            ->assertCreated()
            ->assertExactJson(['status' => 'accepted', 'pallet_code' => 'EST-00000001', 'boxes_count' => 1, 'standard' => 2, 'pallet_closed' => false]);

        $this->send($this->payload())->assertCreated()->assertJson(['boxes_count' => 2, 'pallet_closed' => true]);
    }

    public function test_a_resend_answers_200_and_changes_nothing(): void
    {
        $payload = $this->payload(['event_id' => 'E-1']);

        $this->send($payload)->assertCreated();
        $this->send($payload)->assertOk()->assertJson(['status' => 'duplicate', 'boxes_count' => 1]);

        $this->assertSame(1, Pallet::sole()->boxes_count);
    }

    public function test_a_rejected_box_answers_422_with_the_reason_and_is_kept(): void
    {
        $this->send($this->payload(['item_code' => 'OTRO']))
            ->assertUnprocessable()
            ->assertJson(['status' => 'rejected', 'rejection' => 'PRODUCTO_NO_COINCIDE']);

        $this->assertSame(1, PalletScanEvent::count());
    }

    public function test_malformed_requests_are_refused_without_storing_anything(): void
    {
        $this->send($this->payload(['event_id' => '']))->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->send($this->payload(['event_id' => 'con espacio']))->assertJsonValidationErrors('event_id');
        $this->send($this->payload(['occurred_at' => 'ayer']))->assertJsonValidationErrors('occurred_at');
        $this->send($this->payload(['occurred_at' => now()->addHour()->toIso8601String()]))->assertJsonValidationErrors('occurred_at');
        $this->send($this->payload(['lot' => str_repeat('x', 51)]))->assertJsonValidationErrors('lot');

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

        $this->send($this->payload())->assertCreated();
        $this->send($this->payload())->assertCreated();
        $this->send($this->payload())->assertTooManyRequests();
    }

    public function test_the_trace_carries_the_device_and_the_callers_correlation_id(): void
    {
        $correlation = (string) Str::uuid();

        $this->send($this->payload(), headers: ['X-Correlation-Id' => $correlation])->assertHeader('X-Correlation-Id', $correlation);

        $trace = TraceEvent::sole();
        $this->assertSame($correlation, $trace->correlation_id);
        $this->assertSame($this->device->id, $trace->device_id);
        $this->assertNotNull($this->device->refresh()->last_seen_at);
    }

    public function test_times_with_offset_are_stored_as_the_same_instant_with_microseconds(): void
    {
        $this->send($this->payload(['event_id' => 'E-UTC', 'occurred_at' => '2026-10-19T15:00:00.250000Z']))->assertCreated();

        $stored = PalletScanEvent::query()->selectRaw("to_char(event_occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS utc")->value('utc');
        $this->assertSame('2026-10-19 15:00:00.250000', $stored);
        $this->assertSame('2026-10-19 10:00:00.250000', PalletScanEvent::sole()->event_occurred_at->setTimezone('America/Bogota')->format('Y-m-d H:i:s.u'));
    }
}
