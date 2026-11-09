<?php

namespace App\Domain\Settings;

/**
 * Parameters the administrator changes from the application
 * (Administración → Parámetros). Each one has its default and its rules.
 */
enum Setting: string
{
    /** Percentage of the planned quantity at which an order stops taking production receipts. */
    case ReceiptLimitPercent = 'receipts.order_limit_percent';

    public function default(): int
    {
        return match ($this) {
            self::ReceiptLimitPercent => 90,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReceiptLimitPercent => 'Límite de recibos por OF (% de lo planificado)',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::ReceiptLimitPercent => 'Cuando lo recibido en 04 (confirmado o en trámite en SAP) llega a este porcentaje de la cantidad planificada, la OF no acepta más recibos y hay que cerrarla en SAP. El supervisor de producción puede habilitarla de nuevo desde el Tablero de OF.',
        };
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::ReceiptLimitPercent => ['required', 'integer', 'min:1', 'max:100'],
        };
    }
}
