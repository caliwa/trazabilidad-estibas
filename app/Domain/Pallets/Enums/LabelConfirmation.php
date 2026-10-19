<?php

namespace App\Domain\Pallets\Enums;

/**
 * How a label counts as applied (DP-03). Read from configuration; null
 * means the business has not decided and the pallet cannot reach ETIQUETADO.
 */
enum LabelConfirmation: string
{
    /** Printing the label is enough. */
    case Print = 'print';
    /** The operator presses a button after sticking it. */
    case Button = 'button';
    /** The operator scans the QR of the label already stuck on the pallet. */
    case Scan = 'scan';

    public static function configured(): ?self
    {
        $value = config('traceability.decisions.label_confirmation');

        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }
}
