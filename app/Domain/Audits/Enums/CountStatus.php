<?php

namespace App\Domain\Audits\Enums;

/**
 * Outcome of one blind count attempt (pallet_audits row).
 */
enum CountStatus: string
{
    case Counting = 'EN_CONTEO';
    case Approved = 'APROBADA';
    case Discrepancy = 'DISCREPANCIA';
    case Cancelled = 'CANCELADA';
}
