<?php

namespace App\Integrations\Sap\Enums;

/**
 * Production order status as SAP Business One reports it. Only RELEASED
 * orders can be produced against.
 */
enum ProductionOrderStatus: string
{
    case Planned = 'PLANIFICADO';
    case Released = 'LIBERADO';
    case Closed = 'CERRADO';
    case Cancelled = 'CANCELADO';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planificado',
            self::Released => 'Liberado',
            self::Closed => 'Cerrado',
            self::Cancelled => 'Cancelado',
        };
    }
}
