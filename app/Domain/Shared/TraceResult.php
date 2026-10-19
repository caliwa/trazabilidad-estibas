<?php

namespace App\Domain\Shared;

enum TraceResult: string
{
    case Ok = 'OK';
    case Rejected = 'RECHAZADO';
    case Error = 'ERROR';
}
