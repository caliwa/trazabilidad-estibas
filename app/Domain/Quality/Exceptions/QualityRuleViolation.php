<?php

namespace App\Domain\Quality\Exceptions;

use DomainException;

/**
 * A quality action that breaks a rule. The message is meant for the person
 * at the screen.
 */
class QualityRuleViolation extends DomainException
{
    public static function unreadableQr(): self
    {
        return new self('Lo leído no es el QR de una estiba de esta aplicación.');
    }

    public static function unknownPallet(): self
    {
        return new self('Ese QR no corresponde a ninguna estiba.');
    }

    public static function notLabeled(string $code): self
    {
        return new self("La estiba {$code} todavía no tiene la etiqueta confirmada. Calidad interviene después de ese paso.");
    }

    public static function beingCounted(string $code): self
    {
        return new self("La estiba {$code} la están contando en este momento. Espera a que termine el conteo.");
    }

    public static function alreadyCounted(string $code): self
    {
        return new self("La estiba {$code} ya pasó el conteo ciego y va hacia la bodega 05. Calidad interviene antes del conteo.");
    }

    public static function notHoldable(string $code, string $status): self
    {
        return new self("La estiba {$code} está «{$status}»: no se puede retener.");
    }

    public static function notHeld(string $code, string $status): self
    {
        return new self("La estiba {$code} está «{$status}»: solo se libera o se envía a no conforme una estiba retenida.");
    }

    public static function notReceived(string $code): self
    {
        return new self("La estiba {$code} no tiene recibo de producción confirmado en SAP: todavía no está en la bodega 04 y no se puede trasladar. Pide el recibo primero.");
    }
}
