<?php

namespace App\Domain\Transfers\Enums;

enum TransferMode: string
{
    case Individual = 'INDIVIDUAL';
    /** Several pallets in one document. Disabled until DP-05 is approved. */
    case Grouped = 'AGRUPADO';
}
