<?php

namespace App\Domain\Transfers\Exceptions;

use DomainException;

/**
 * A transfer operation the rules do not allow. Safe to show to the user.
 */
class TransferRuleViolation extends DomainException
{
    public static function noPallets(): self
    {
        return new self('Selecciona al menos una estiba.');
    }

    public static function missing(): self
    {
        return new self('Alguna de las estibas seleccionadas no existe.');
    }

    public static function notApproved(string $code): self
    {
        return new self("La estiba {$code} no tiene la auditoría aprobada: no se puede trasladar.");
    }

    public static function notAvailable(string $code, string $status): self
    {
        return new self("La estiba {$code} no se puede trasladar: su traslado está en «{$status}».");
    }

    public static function noConfirmedReceipt(string $code): self
    {
        return new self("La estiba {$code} no tiene un recibo confirmado en SAP del cual partir.");
    }

    public static function groupingUndecided(): self
    {
        return new self('El traslado agrupado no está habilitado (DP-05). Se trasladan de a una estiba.');
    }

    public static function groupingNotConfirmed(): self
    {
        return new self('Para agrupar estibas en un solo traslado hay que confirmarlo explícitamente.');
    }

    public static function incompatibleForGrouping(): self
    {
        return new self('Un traslado agrupado solo puede tener estibas del mismo producto y lote.');
    }

    public static function notPending(): self
    {
        return new self('El traslado no está pendiente de conciliación.');
    }

    public static function documentMayExist(): self
    {
        return new self('No se puede marcar como fallido: SAP no ha demostrado que el documento no exista.');
    }
}
