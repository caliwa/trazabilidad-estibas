<?php

namespace App\Domain\Pallets\Enums;

enum ScanEventStatus: string
{
    case Accepted = 'ACEPTADO';
    case Rejected = 'RECHAZADO';
}
