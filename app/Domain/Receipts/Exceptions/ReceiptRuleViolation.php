<?php

namespace App\Domain\Receipts\Exceptions;

use DomainException;

/**
 * A receipt operation the rules do not allow. Safe to show to the user.
 */
class ReceiptRuleViolation extends DomainException
{
    public static function noPallets(): self
    {
        return new self('Selecciona al menos una estiba.');
    }

    public static function missing(): self
    {
        return new self('Alguna de las estibas seleccionadas no existe.');
    }

    public static function mixedOrders(): self
    {
        return new self('Todas las estibas de un recibo deben ser de la misma orden de producción.');
    }

    public static function notLabeled(string $code): self
    {
        return new self("La estiba {$code} no está etiquetada.");
    }

    public static function notAvailable(string $code, string $status): self
    {
        return new self("La estiba {$code} no se puede recibir: su recepción está en «{$status}».");
    }

    public static function orderClosed(string $ref): self
    {
        return new self("La orden {$ref} está cerrada.");
    }

    public static function missingFactor(string $itemCode): self
    {
        return new self("El producto {$itemCode} no tiene un factor de conversión cajas → unidades vigente (DP-02).");
    }

    public static function lotWithoutLabels(string $lotCode, string $orderRef): self
    {
        return new self("El lote {$lotCode} no tiene etiquetas de caja generadas para la orden {$orderRef}. SAP pide el vencimiento del lote y sale de esas etiquetas.");
    }

    public static function notPending(): self
    {
        return new self('El recibo no está pendiente de conciliación.');
    }

    public static function documentMayExist(): self
    {
        return new self('No se puede marcar como fallido: SAP no ha demostrado que el documento no exista.');
    }
}
