<?php

namespace App\Domain\Pallets\Exceptions;

/**
 * The reader fired but could not read the label: it sent its "no read"
 * text (NO_READ, NoRead, ERROR...) instead of a QR. No box is counted; the
 * person has to read that box again.
 */
class ReaderNoRead extends PalletRuleViolation
{
    /** Texts readers send when they trigger and read nothing. */
    private const PATTERN = '/^\s*(?:no[\s_-]?read|no[\s_-]?lectura|error|ng)\s*$/i';

    public static function matches(string $read): bool
    {
        return preg_match(self::PATTERN, $read) === 1;
    }

    public static function make(string $read): self
    {
        return new self('El lector se activó pero no logró leer la etiqueta ('.trim($read).'). Vuelve a leer esa caja.');
    }
}
