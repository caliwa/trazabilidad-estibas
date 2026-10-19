<?php

namespace App\Domain\Shared;

enum ActorType: string
{
    case User = 'USUARIO';
    case Device = 'DISPOSITIVO';
    case System = 'SISTEMA';
}
