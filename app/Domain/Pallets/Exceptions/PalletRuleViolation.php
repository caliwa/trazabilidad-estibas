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

    public static function voidReasonUnavailable(): self
    {
        return new self('Escoge un motivo de anulación de la lista. El que estaba seleccionado ya no está disponible.');
    }

    public static function boxNotCounted(string $boxCode): self
    {
        return new self("La caja {$boxCode} no tiene una lectura contada: no hay nada que anular.");
    }

    public static function boxAlreadyVoided(string $boxCode): self
    {
        return new self("La caja {$boxCode} ya fue anulada. Una caja anulada no se vuelve a contar.");
    }

    public static function boxVoidTooLate(string $boxCode, string $palletCode): self
    {
        return new self("La caja {$boxCode} está en {$palletCode}, que ya fue paletizada: solo se anulan cajas de estibas sin paletizar.");
    }

    public static function boxVoidWouldEmptyClosed(string $boxCode, string $palletCode, string $openCode): self
    {
        return new self("Anular {$boxCode} dejaría {$palletCode} cerrada y sin cajas, y no se puede reabrir porque {$openCode} está abierta para la misma orden y lote.");
    }

    public static function closeReasonUnavailable(): self
    {
        return new self('Escoge un motivo de cierre de la lista. El que estaba seleccionado ya no está disponible.');
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

    public static function labelBoxMissing(): self
    {
        return new self('Lee el QR de una caja de la estiba donde pegaste la etiqueta.');
    }

    public static function labelBoxNotABox(): self
    {
        return new self('Lo leído no es el QR de una caja. Lee el QR de una caja de la estiba (no el de la etiqueta).');
    }

    public static function labelBoxNotRead(string $boxCode): self
    {
        return new self("La caja {$boxCode} no se ha leído en ninguna estiba. Lee otra caja de la estiba, del centro.");
    }

    public static function labelOnWrongPallet(string $labelPallet, string $boxPallet): self
    {
        return new self("La caja leída es de {$boxPallet}, no de {$labelPallet}: la etiqueta de {$labelPallet} no está en la estiba correcta. Revisa la estiba.");
    }

    public static function qrMismatch(): self
    {
        return new self('El código leído no corresponde a esta estiba.');
    }

    public static function notABoxLabel(): self
    {
        return new self('Lo leído no es el QR de una caja de esta aplicación.');
    }

    public static function orderNotReleased(string $ref, string $status): self
    {
        return new self("La OF {$ref} está «{$status}» en SAP. Solo se llenan estibas de órdenes liberadas.");
    }

    public static function boxItemMismatch(string $ref, string $expected, string $read): self
    {
        return new self("La caja dice {$read}, pero la OF {$ref} es del producto {$expected}.");
    }

    public static function unknownBoxQr(): self
    {
        return new self('Ese QR no corresponde a ninguna caja generada en la aplicación. Si es una etiqueta impresa antes de este cambio, hay que reimprimir ese lote.');
    }

    public static function palletQrInBoxField(): self
    {
        return new self('Leíste el QR de una estiba. Aquí va el QR de una caja.');
    }

    public static function boxAlreadyRead(string $code, string $palletCode): self
    {
        return new BoxAlreadyRead("La caja {$code} ya fue leída y está en la estiba {$palletCode}. No se cuenta otra vez.");
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
