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

    public static function notFinished(string $palletCode): self
    {
        return new self("La estiba {$palletCode} todavía está en estibado: hay que cerrarla antes de paletizar.");
    }

    public static function alreadyPalletized(string $palletCode): self
    {
        return new self("La estiba {$palletCode} ya fue paletizada.");
    }

    public static function notPalletized(string $palletCode): self
    {
        return new self("La estiba {$palletCode} no está paletizada: no se puede etiquetar.");
    }

    public static function reprintNeedsReason(string $palletCode): self
    {
        return new self("Para reimprimir la etiqueta de {$palletCode} hay que indicar el motivo.");
    }

    public static function labelConfirmationUndecided(): self
    {
        return new self('La confirmación de etiqueta aplicada no está habilitada todavía (DP-03).');
    }

    public static function labelConfirmationIsAutomatic(): self
    {
        return new self('La etiqueta se da por aplicada al imprimirla; no hay que confirmarla aparte.');
    }

    public static function labelNotPrinted(string $palletCode): self
    {
        return new self("La etiqueta de {$palletCode} no se ha impreso.");
    }

    public static function alreadyLabeled(string $palletCode): self
    {
        return new self("La estiba {$palletCode} ya tiene la etiqueta confirmada.");
    }

    public static function qrMismatch(): self
    {
        return new self('El código leído no corresponde a esta estiba.');
    }

    public static function eventNotRejected(): self
    {
        return new self('La lectura ya fue aceptada; no hay nada que reprocesar.');
    }
}
