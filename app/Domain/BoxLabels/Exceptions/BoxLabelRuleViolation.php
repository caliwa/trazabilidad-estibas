<?php

namespace App\Domain\BoxLabels\Exceptions;

use Carbon\CarbonInterface;
use DomainException;

/**
 * A box label request that breaks a rule. The message is meant for the
 * person at the screen.
 */
class BoxLabelRuleViolation extends DomainException
{
    public static function invalidNumber(): self
    {
        return new self('Escribe el número de la orden de fabricación (solo números).');
    }

    public static function sapUnavailable(): self
    {
        return new self('No se pudo consultar SAP en este momento. Intenta de nuevo.');
    }

    public static function notFound(string $docNum): self
    {
        return new self("La orden de fabricación {$docNum} no existe en SAP.");
    }

    public static function notReleased(string $docNum, string $status): self
    {
        return new self("La orden de fabricación {$docNum} está «{$status}» en SAP. Solo se generan etiquetas de órdenes liberadas.");
    }

    public static function itemChanged(string $docNum): self
    {
        return new self("En SAP la orden {$docNum} tiene otro producto que el que ya tiene estibas aquí. Hay que revisarlo antes de seguir.");
    }

    public static function notConsulted(): self
    {
        return new self('Consulta la orden en SAP antes de generar etiquetas.');
    }

    public static function noBarcode(string $itemCode): self
    {
        return new self("El producto {$itemCode} no tiene código EAN-14 en SAP. Sin él no se genera la etiqueta.");
    }

    public static function invalidBarcode(string $itemCode, string $barcode): self
    {
        return new self("El código {$barcode} del producto {$itemCode} no es un EAN-14 (GTIN-14) válido: 14 dígitos con dígito de control. Hay que corregirlo en SAP.");
    }

    public static function noFactor(string $itemCode): self
    {
        return new self("El producto {$itemCode} no tiene unidades por caja vigentes (DP-02). Sin eso no se genera la etiqueta.");
    }

    public static function invalidQuantity(int $remaining): self
    {
        return $remaining > 0
            ? new self("La cantidad de etiquetas debe estar entre 1 y {$remaining}, lo que falta para completar la orden.")
            : new self('Ya se generaron todas las etiquetas que alcanza la cantidad planificada de la orden.');
    }

    public static function futureProductionDate(): self
    {
        return new self('La fecha de producción no puede ser futura.');
    }

    public static function lotExpirationMismatch(string $lotCode, CarbonInterface $expiration): self
    {
        return new self("El lote {$lotCode} ya tiene etiquetas con vencimiento {$expiration->format('d/m/Y')}. Un lote tiene una sola fecha de vencimiento.");
    }

    public static function invalidExpiration(): self
    {
        return new self('Indica una fecha de vencimiento posterior a la fecha de producción.');
    }
}
