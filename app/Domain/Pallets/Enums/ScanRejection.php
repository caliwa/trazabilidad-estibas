<?php

namespace App\Domain\Pallets\Enums;

/**
 * Why a scan event was not counted. Stored in pallet_scan_events.rejection_reason.
 */
enum ScanRejection: string
{
    case UnknownOrder = 'ORDEN_DESCONOCIDA';
    case ClosedOrder = 'ORDEN_CERRADA';
    case ItemMismatch = 'PRODUCTO_NO_COINCIDE';
    case InactiveItem = 'PRODUCTO_INACTIVO';
    case InvalidLot = 'LOTE_INVALIDO';
    case MissingStandard = 'ESTANDAR_AUSENTE';
    case InvalidStandard = 'ESTANDAR_INVALIDO';
    case ExpiredStandard = 'ESTANDAR_VENCIDO';

    public function label(): string
    {
        return match ($this) {
            self::UnknownOrder => 'La orden de producción no existe',
            self::ClosedOrder => 'La orden de producción está cerrada',
            self::ItemMismatch => 'El producto no corresponde a la orden',
            self::InactiveItem => 'El producto está inactivo',
            self::InvalidLot => 'El lote no tiene un formato válido',
            self::MissingStandard => 'El producto no tiene estándar de cajas por estiba',
            self::InvalidStandard => 'El estándar de cajas recibido de SAP no es válido',
            self::ExpiredStandard => 'El estándar de cajas no está vigente',
        };
    }
}
