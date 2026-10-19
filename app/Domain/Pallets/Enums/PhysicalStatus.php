<?php

namespace App\Domain\Pallets\Enums;

use App\Domain\Shared\HasTransitions;

/**
 * Physical life of a pallet on the plant floor. Only moves forward, one
 * step at a time.
 */
enum PhysicalStatus: string
{
    use HasTransitions;

    case Stacking = 'EN_ESTIBADO';
    case Finished = 'ESTIBADO_FINALIZADO';
    case Palletized = 'PALETIZADO';
    case Labeled = 'ETIQUETADO';

    public function next(): array
    {
        return match ($this) {
            self::Stacking => [self::Finished],
            self::Finished => [self::Palletized],
            self::Palletized => [self::Labeled],
            self::Labeled => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Stacking => 'En estibado',
            self::Finished => 'Estibado finalizado',
            self::Palletized => 'Paletizado',
            self::Labeled => 'Etiquetado',
        };
    }

    public function acceptsBoxes(): bool
    {
        return $this === self::Stacking;
    }
}
