<?php

namespace App\Domain\Quality\Enums;

use App\Domain\Shared\HasTransitions;

/**
 * Quality dimension of a pallet, before the blind count. A held pallet is
 * neither counted nor transferred to 05; quality releases it or sends it to
 * the non-conforming warehouse (04 → 11C). NO_CONFORME is reached only
 * after the SAP transfer was verified.
 */
enum QualityStatus: string
{
    use HasTransitions;

    case None = 'SIN_NOVEDAD';
    case Held = 'RETENIDA';
    case Released = 'LIBERADA';
    case NonConformingRequested = 'NC_SOLICITADO';
    case NonConformingPending = 'NC_PENDIENTE';
    case NonConforming = 'NO_CONFORME';

    public function next(): array
    {
        return match ($this) {
            self::None => [self::Held],
            self::Held => [self::Released, self::NonConformingRequested],
            self::Released => [self::Held],
            // SAP refused the transfer: the pallet stays held, to retry or release.
            self::NonConformingRequested => [self::NonConforming, self::Held, self::NonConformingPending],
            self::NonConformingPending => [self::NonConforming, self::Held, self::NonConformingRequested],
            self::NonConforming => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sin novedad',
            self::Held => 'Retenida por calidad',
            self::Released => 'Liberada por calidad',
            self::NonConformingRequested => 'Traslado a no conforme solicitado',
            self::NonConformingPending => 'Traslado a no conforme por conciliar',
            self::NonConforming => 'En no conforme',
        };
    }

    /** Kept out of the blind count and of the transfer to 05. */
    public function blocksFlow(): bool
    {
        return in_array($this, [self::Held, self::NonConformingRequested, self::NonConformingPending, self::NonConforming], true);
    }

    /** Flux badge color. */
    public function color(): string
    {
        return match ($this) {
            self::None => 'zinc',
            self::Released => 'green',
            self::Held => 'amber',
            self::NonConformingRequested, self::NonConformingPending => 'orange',
            self::NonConforming => 'red',
        };
    }
}
