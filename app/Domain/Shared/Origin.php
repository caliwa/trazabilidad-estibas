<?php

namespace App\Domain\Shared;

enum Origin: string
{
    case Web = 'WEB';
    case Api = 'API';
    case Job = 'JOB';
    case Console = 'CONSOLA';
}
