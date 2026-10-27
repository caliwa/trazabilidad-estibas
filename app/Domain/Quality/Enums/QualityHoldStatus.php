<?php

namespace App\Domain\Quality\Enums;

enum QualityHoldStatus: string
{
    /** The transfer to the non-conforming warehouse is on its way. */
    case Requested = 'RETENIDA';
    /** In the non-conforming warehouse, waiting for quality's decision. */
    case NonConforming = 'NO_CONFORME';
    case Released = 'LIBERADA';
    /** SAP refused the transfer: the pallet never left. */
    case Cancelled = 'ANULADA';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Trasladando a no conforme',
            self::NonConforming => 'Retenida en no conforme',
            self::Released => 'Liberada',
            self::Cancelled => 'Anulada',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::NonConforming;
    }
}
