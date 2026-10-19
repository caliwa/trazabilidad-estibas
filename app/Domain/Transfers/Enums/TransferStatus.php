<?php

namespace App\Domain\Transfers\Enums;

use App\Domain\Shared\HasTransitions;

/**
 * Transfer dimension of a pallet (warehouse 04 → 05). CONFIRMADO_05 is
 * reached only after the SAP document was verified.
 */
enum TransferStatus: string
{
    use HasTransitions;

    case NotEligible = 'NO_ELEGIBLE';
    case Ready = 'LISTO';
    case Requested = 'SOLICITADO';
    case PendingReconciliation = 'PENDIENTE_CONCILIACION';
    case Confirmed = 'CONFIRMADO_05';
    case Failed = 'FALLIDO';

    public function next(): array
    {
        return match ($this) {
            self::NotEligible => [self::Ready],
            self::Ready => [self::Requested],
            self::Requested => [self::Confirmed, self::Failed, self::PendingReconciliation],
            // Back to requested only when SAP proved no document exists and it is resent.
            self::PendingReconciliation => [self::Confirmed, self::Failed, self::Requested],
            self::Failed => [self::Requested],
            self::Confirmed => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NotEligible => 'No elegible',
            self::Ready => 'Listo para trasladar',
            self::Requested => 'Solicitado',
            self::PendingReconciliation => 'Pendiente de conciliación',
            self::Confirmed => 'Trasladado a 05',
            self::Failed => 'Fallido',
        };
    }
}
