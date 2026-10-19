<?php

namespace App\Console\Commands;

use App\Domain\Devices\ManageDevices;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Support\Correlation\CorrelationId;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

#[Signature('devices:register {code : Código único, por ejemplo SEN-L1} {name : Nombre visible} {--kind=SENSOR : SENSOR o LECTOR}')]
#[Description('Registra un sensor o lector y muestra su token una sola vez.')]
class DeviceRegister extends Command
{
    public function handle(ManageDevices $devices, CorrelationId $correlation): int
    {
        try {
            [$device, $token] = $devices->register(
                (string) $this->argument('code'),
                (string) $this->argument('name'),
                (string) $this->option('kind'),
                TraceContext::system(Origin::Console, $correlation->get()),
            );
        } catch (UniqueConstraintViolationException) {
            $this->error('Ya existe un dispositivo con ese código.');

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Dispositivo {$device->code} registrado.");
        $this->line('Token (cópialo ahora, no se vuelve a mostrar):');
        $this->line($token);

        return self::SUCCESS;
    }
}
