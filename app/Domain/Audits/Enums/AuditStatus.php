<?php

namespace App\Domain\Audits\Enums;

use App\Domain\Shared\HasTransitions;

/**
 * Logistics audit dimension of a pallet (blind count at the warehouse).
 */
enum AuditStatus: string
{
    use HasTransitions;

    case Pending = 'PENDIENTE';
    case Counting = 'EN_CONTEO';
    case Approved = 'APROBADA';
    case Discrepancy = 'DISCREPANCIA';

    public function next(): array
    {
        return match ($this) {
            // Back to pending when a count is abandoned before submitting.
            self::Pending => [self::Counting],
            self::Counting => [self::Approved, self::Discrepancy, self::Pending],
            // The only way out of a discrepancy defined so far is a new count (DP-04).
            self::Discrepancy => [self::Counting],
            self::Approved => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Counting => 'En conteo',
            self::Approved => 'Aprobada',
            self::Discrepancy => 'Discrepancia',
        };
    }
}
