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

    public static function notABoxLabel(): self
    {
        return new self('Lo leído no es una etiqueta de caja de esta aplicación. Debe traer la OF, el PT, la descripción, el lote y el vencimiento.');
    }

    public static function orderNotInSap(string $ref): self
    {
        return new self("La OF {$ref} no existe en SAP o no es de un producto terminado (PT).");
    }

    public static function sapUnavailable(string $ref): self
    {
        return new self("No se pudo consultar la OF {$ref} en SAP. Intenta de nuevo en un momento.");
    }

    public static function orderNotReleased(string $ref, string $status): self
    {
        return new self("La OF {$ref} está «{$status}» en SAP. Solo se llenan estibas de órdenes liberadas.");
    }

    public static function boxItemMismatch(string $ref, string $expected, string $read): self
    {
        return new self("La caja dice {$read}, pero la OF {$ref} es del producto {$expected}.");
    }

    public static function lotWithoutLabels(string $lot, string $ref): self
    {
        return new self("El lote {$lot} no tiene etiquetas de caja generadas para la OF {$ref}. Solo se cuentan cajas con etiquetas de esta aplicación.");
    }

    public static function boxLabelWithoutCode(): self
    {
        return new self('La etiqueta no tiene código de caja: es de antes de que cada caja tuviera su propio código. Hay que reimprimir las etiquetas de ese lote.');
    }

    public static function unknownBoxCode(string $code, string $ref): self
    {
        return new self("El código de caja {$code} no corresponde a ninguna etiqueta generada para la OF {$ref}.");
    }

    public static function boxAlreadyRead(string $code, string $palletCode): self
    {
        return new self("La caja {$code} ya fue leída y está en la estiba {$palletCode}. No se cuenta otra vez.");
    }

    public static function boxHasRejectedRead(string $code): self
    {
        return new self("La caja {$code} tiene una lectura rechazada. Revísala en Lecturas rechazadas y reprocésala desde ahí.");
    }

    public static function boxRejected(string $reason): self
    {
        return new self("La caja no se contó: {$reason}.");
    }

    public static function unknownPalletQr(): self
    {
        return new self('Lo leído no es el QR de una estiba de esta aplicación.');
    }

    public static function eventNotRejected(): self
    {
        return new self('La lectura ya fue aceptada; no hay nada que reprocesar.');
    }
}
