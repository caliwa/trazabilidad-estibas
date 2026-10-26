<?php

namespace App\Domain\Quality\Enums;

enum QualityHoldStatus: string
{
    case Open = 'RETENIDA';
    case Released = 'LIBERADA';
    case NonConforming = 'NO_CONFORME';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Retenida',
            self::Released => 'Liberada',
            self::NonConforming => 'Enviada a no conforme',
        };
    }
}
