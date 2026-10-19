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

#[Signature('devices:deactivate {code} {--reason=}')]
#[Description('Desactiva un dispositivo: sus lecturas se rechazan con 401.')]
class DeviceDeactivate extends Command
{
    public function handle(ManageDevices $devices, CorrelationId $correlation): int
    {
        $device = Device::query()->where('code', Str::upper((string) $this->argument('code')))->first();

        if ($device === null) {
            $this->error('No existe un dispositivo con ese código.');

            return self::FAILURE;
        }

        $reason = $this->option('reason');
        $devices->deactivate($device, is_string($reason) ? $reason : null, TraceContext::system(Origin::Console, $correlation->get()));
        $this->info("Dispositivo {$device->code} desactivado.");

        return self::SUCCESS;
    }
}
