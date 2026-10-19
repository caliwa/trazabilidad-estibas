<?php

namespace Tests\Feature\Devices;

use App\Models\Device;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProductionMasters;
use Tests\TestCase;

class DeviceCommandsTest extends TestCase
{
    use BuildsProductionMasters, RefreshDatabase;

    private function tokenFrom(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return (string) end($lines);
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        $order = ProductionOrder::query()->first() ?? $this->orderWithStandard();

        return ['event_id' => (string) Str::uuid(), 'production_order' => $order->ref, 'item_code' => $order->item->code, 'lot' => 'L-1', 'occurred_at' => now()->toIso8601String()];
    }

    public function test_a_registered_device_gets_a_token_once_and_only_its_hash_is_stored(): void
    {
        $this->enableScanIngestion();

        Artisan::call('devices:register', ['code' => 'sen-l1', 'name' => 'Sensor línea 1']);
        $token = $this->tokenFrom(Artisan::output());

        $device = Device::sole();
        $this->assertSame('SEN-L1', $device->code);
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertStringNotContainsString($token, (string) json_encode(Device::query()->get()->toArray()));
        $this->assertSame('device.registered', TraceEvent::sole()->event);

        $this->withToken($token)->postJson(route('api.scan-events.store'), $this->payload())->assertCreated();
    }

    public function test_rotating_the_token_invalidates_the_old_one(): void
    {
        $this->enableScanIngestion();
        Artisan::call('devices:register', ['code' => 'SEN-L1', 'name' => 'Sensor']);
        $old = $this->tokenFrom(Artisan::output());

        Artisan::call('devices:rotate-token', ['code' => 'SEN-L1']);
        $new = $this->tokenFrom(Artisan::output());

        $this->assertNotSame($old, $new);
        $this->withToken($old)->postJson(route('api.scan-events.store'), $this->payload())->assertUnauthorized();
        $this->withToken($new)->postJson(route('api.scan-events.store'), $this->payload())->assertCreated();
    }

    public function test_a_deactivated_device_is_refused_and_duplicates_codes_are_reported(): void
    {
        $this->enableScanIngestion();
        Artisan::call('devices:register', ['code' => 'SEN-L1', 'name' => 'Sensor']);
        $token = $this->tokenFrom(Artisan::output());

        $this->artisan('devices:register', ['code' => 'SEN-L1', 'name' => 'Otro'])->assertFailed();
        $this->artisan('devices:deactivate', ['code' => 'SEN-L1', '--reason' => 'Retirado'])->assertSuccessful();

        $this->withToken($token)->postJson(route('api.scan-events.store'), $this->payload())->assertUnauthorized();
        $this->assertSame('Retirado', TraceEvent::where('event', 'device.deactivated')->sole()->reason);
    }
}
