<?php

namespace App\Domain\Pallets\Enums;

enum CloseType: string
{
    /** The pallet reached its frozen standard. */
    case Automatic = 'AUTOMATICO';
    /** Closed by a person with fewer boxes than the standard. */
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automático',
            self::Manual => 'Manual',
        };
    }
}
