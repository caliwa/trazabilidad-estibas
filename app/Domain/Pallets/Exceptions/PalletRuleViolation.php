<?php

namespace App\Domain\Pallets\Exceptions;

use DomainException;

/**
 * A pallet operation that the business rules do not allow right now.
 * The message is safe to show to the person who tried it.
 */
class PalletRuleViolation extends DomainException
{
    public static function notOpen(string $palletCode): self
    {
        return new self("La estiba {$palletCode} ya está cerrada.");
    }

    public static function manualCloseRange(string $palletCode, int $standard): self
    {
        return new self("La estiba {$palletCode} solo se puede cerrar a mano con entre 1 y ".($standard - 1).' cajas.');
    }
}
