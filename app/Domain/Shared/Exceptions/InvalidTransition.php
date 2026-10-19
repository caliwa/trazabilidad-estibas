<?php

namespace App\Domain\Shared\Exceptions;

use BackedEnum;
use DomainException;

class InvalidTransition extends DomainException
{
    public static function between(BackedEnum $from, BackedEnum $to): self
    {
        $dimension = class_basename($from);

        return new self("Transición no permitida en {$dimension}: {$from->value} → {$to->value}.");
    }
}
