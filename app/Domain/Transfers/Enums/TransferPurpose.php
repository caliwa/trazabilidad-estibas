<?php

namespace App\Domain\Transfers\Enums;

/**
 * Why a stock transfer exists: to the CEDI (04 → 05, from an approved
 * blind count) or to the non-conforming warehouse (04 → 11C, from quality).
 */
enum TransferPurpose: string
{
    case Cedi = 'CEDI';
    case NonConforming = 'NO_CONFORME';
}
