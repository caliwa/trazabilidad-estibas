<?php

namespace App\Domain\Orders\Enums;

/**
 * Where a production order stands, from SAP's status and what this
 * application received against it.
 */
enum OrderStage: string
{
    /** Never looked up in SAP: no planned quantity to compare with. */
    case NotConsulted = 'SIN_CONSULTAR';
    case InProgress = 'EN_PROCESO';
    /** Confirmed receipts reach the planned quantity (up to its last box): it can be closed in SAP. */
    case ReadyToClose = 'LISTA_PARA_CERRAR';
    /** Confirmed receipts go past the boxes the planned quantity fills. */
    case OverProduced = 'SOBREPRODUCCION';
    /** Closed or cancelled in SAP. */
    case Closed = 'CERRADA';

    public function label(): string
    {
        return match ($this) {
            self::NotConsulted => 'Sin consultar en SAP',
            self::InProgress => 'En proceso',
            self::ReadyToClose => 'Lista para cerrar en SAP',
            self::OverProduced => 'Sobreproducción',
            self::Closed => 'Cerrada en SAP',
        };
    }

    /** Flux badge color. */
    public function color(): string
    {
        return match ($this) {
            self::NotConsulted => 'zinc',
            self::InProgress => 'blue',
            self::ReadyToClose => 'green',
            self::OverProduced => 'red',
            self::Closed => 'zinc',
        };
    }

    public function closable(): bool
    {
        return $this === self::ReadyToClose || $this === self::OverProduced;
    }
}
