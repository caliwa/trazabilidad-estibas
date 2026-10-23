<?php

namespace App\Domain\Audits\Exceptions;

use DomainException;

/**
 * A count or discrepancy operation the rules do not allow. Messages are
 * shown to the logistics operator, so none of them may carry a quantity.
 */
class AuditRuleViolation extends DomainException
{
    public static function unreadableQr(): self
    {
        return new self('El código leído no es una etiqueta de estiba.');
    }

    public static function unknownPallet(): self
    {
        return new self('No existe una estiba con esa etiqueta.');
    }

    public static function notReceived(string $code): self
    {
        return new self("La estiba {$code} no tiene recibo confirmado en SAP: todavía no se puede auditar.");
    }

    public static function alreadyTransferred(string $code): self
    {
        return new self("La estiba {$code} ya fue trasladada.");
    }

    public static function alreadyApproved(string $code): self
    {
        return new self("La estiba {$code} ya tiene la auditoría aprobada.");
    }

    public static function countedByOther(string $code): self
    {
        return new self("La estiba {$code} la está contando otra persona.");
    }

    public static function discrepancyOpen(string $code): self
    {
        return new self("La estiba {$code} tiene una discrepancia abierta. Un administrador debe autorizar la reauditoría.");
    }

    public static function notYourCount(): self
    {
        return new self('Ese conteo no es tuyo o ya terminó.');
    }

    public static function resolutionUndecided(): self
    {
        return new self('La resolución de discrepancias no está habilitada todavía (DP-04).');
    }

    public static function actionNotAllowed(string $action): self
    {
        return new self("La acción «{$action}» no está entre las aprobadas para resolver discrepancias (DP-04).");
    }

    public static function resolverCounted(): self
    {
        return new self('Quien contó la estiba no puede resolver su propia discrepancia.');
    }

    public static function caseClosed(): self
    {
        return new self('La discrepancia ya está resuelta.');
    }

    public static function caseNotOpen(): self
    {
        return new self('La reauditoría solo se autoriza sobre una discrepancia abierta.');
    }
}
