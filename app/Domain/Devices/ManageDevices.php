<?php

namespace App\Domain\Devices;

use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Models\Device;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Registers sensors and readers. Tokens are returned once, to be loaded
 * into the device; only their hash is kept.
 */
class ManageDevices
{
    public const KINDS = ['SENSOR', 'LECTOR'];

    public function __construct(private readonly TraceLogger $trace) {}

    /**
     * @return array{Device, string} the device and its plain token
     */
    public function register(string $code, string $name, string $kind, TraceContext $ctx): array
    {
        $kind = Str::upper($kind);

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('El tipo de dispositivo debe ser SENSOR o LECTOR.');
        }

        $token = self::newToken();

        $device = DB::transaction(function () use ($code, $name, $kind, $token, $ctx): Device {
            $device = new Device;
            $device->forceFill(['code' => Str::upper(trim($code)), 'name' => trim($name), 'kind' => $kind, 'token_hash' => Device::hashToken($token), 'active' => true])->save();

            $this->trace->record($ctx, TraceEventType::DeviceRegistered, $device, to: ['code' => $device->code, 'name' => $device->name, 'kind' => $kind]);

            return $device;
        });

        return [$device, $token];
    }

    /**
     * Invalidates the current token at once and returns a new one.
     */
    public function rotateToken(Device $device, TraceContext $ctx): string
    {
        $token = self::newToken();

        DB::transaction(function () use ($device, $token, $ctx): void {
            $device->forceFill(['token_hash' => Device::hashToken($token)])->save();
            $this->trace->record($ctx, TraceEventType::DeviceTokenRotated, $device);
        });

        return $token;
    }

    public function deactivate(Device $device, ?string $reason, TraceContext $ctx): void
    {
        DB::transaction(function () use ($device, $reason, $ctx): void {
            $device->forceFill(['active' => false])->save();
            $this->trace->record($ctx, TraceEventType::DeviceDeactivated, $device, from: ['active' => true], to: ['active' => false], reason: $reason);
        });
    }

    private static function newToken(): string
    {
        return Str::random(48);
    }
}
