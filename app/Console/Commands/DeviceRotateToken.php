<?php

namespace App\Console\Commands;

use App\Domain\Devices\ManageDevices;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Models\Device;
use App\Support\Correlation\CorrelationId;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('devices:rotate-token {code}')]
#[Description('Invalida el token de un dispositivo y muestra uno nuevo una sola vez.')]
class DeviceRotateToken extends Command
{
    public function handle(ManageDevices $devices, CorrelationId $correlation): int
    {
        $device = Device::query()->where('code', Str::upper((string) $this->argument('code')))->first();

        if ($device === null) {
            $this->error('No existe un dispositivo con ese código.');

            return self::FAILURE;
        }

        $token = $devices->rotateToken($device, TraceContext::system(Origin::Console, $correlation->get()));

        $this->info("Token de {$device->code} renovado. El anterior ya no funciona.");
        $this->line($token);

        return self::SUCCESS;
    }
}
