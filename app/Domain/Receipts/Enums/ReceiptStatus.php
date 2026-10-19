<?php

namespace App\Domain\Receipts\Enums;

use App\Domain\Shared\HasTransitions;

/**
 * Receipt dimension of a pallet (production receipt into warehouse 04).
 * CONFIRMADO_04 is reached only after the SAP document was verified.
 */
enum ReceiptStatus: string
{
    use HasTransitions;

    case None = 'SIN_RECIBO';
    case Requested = 'SOLICITADO';
    case PendingReconciliation = 'PENDIENTE_CONCILIACION';
    case Confirmed = 'CONFIRMADO_04';
    case Failed = 'FALLIDO';

    public function next(): array
    {
        return match ($this) {
            self::None => [self::Requested],
            self::Requested => [self::Confirmed, self::Failed, self::PendingReconciliation],
            // Back to requested only when SAP proved no document exists and it is resent.
            self::PendingReconciliation => [self::Confirmed, self::Failed, self::Requested],
            // A failure proved in SAP frees the pallet for a new request.
            self::Failed => [self::Requested],
            self::Confirmed => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sin recibo',
            self::Requested => 'Solicitado',
            self::PendingReconciliation => 'Pendiente de conciliación',
            self::Confirmed => 'Recibido en 04',
            self::Failed => 'Fallido',
        };
    }
}
