<?php

namespace App\Domain\Quality\Enums;

use App\Domain\Shared\HasTransitions;

/**
 * Quality dimension of a pallet, before the blind count. Holding a pallet
 * moves it to the non-conforming warehouse: "held" means "in 11C", reached
 * only after the SAP transfer was verified. Released, it stays in 11C and
 * goes on to the count, whose transfer then leaves from 11C to 05.
 */
enum QualityStatus: string
{
    use HasTransitions;

    case None = 'SIN_NOVEDAD';
    case NonConformingRequested = 'NC_SOLICITADO';
    case NonConformingPending = 'NC_PENDIENTE';
    case NonConforming = 'NO_CONFORME';
    case Released = 'LIBERADA';

    public function next(): array
    {
        return match ($this) {
            self::None => [self::NonConformingRequested],
            // SAP refused the transfer: nothing moved, the pallet is as it was.
            self::NonConformingRequested => [self::NonConforming, self::NonConformingPending, self::None],
            self::NonConformingPending => [self::NonConforming, self::NonConformingRequested, self::None],
            self::NonConforming => [self::Released],
            // Still in 11C until counted: holding it again moves nothing.
            self::Released => [self::NonConforming],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sin novedad',
            self::NonConformingRequested => 'Retención: traslado a no conforme solicitado',
            self::NonConformingPending => 'Retención: traslado a no conforme por conciliar',
            self::NonConforming => 'Retenida por calidad (en no conforme)',
            self::Released => 'Liberada por calidad',
        };
    }

    /** Kept out of the blind count and of the transfer to 05. */
    public function blocksFlow(): bool
    {
        return in_array($this, [self::NonConformingRequested, self::NonConformingPending, self::NonConforming], true);
    }

    /** Flux badge color. */
    public function color(): string
    {
        return match ($this) {
            self::None => 'zinc',
            self::Released => 'green',
            self::NonConformingRequested, self::NonConformingPending => 'amber',
            self::NonConforming => 'red',
        };
    }
}
