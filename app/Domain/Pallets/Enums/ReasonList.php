<?php

namespace App\Domain\Pallets\Enums;

use App\Models\BoxVoidReason;
use App\Models\PalletCloseReason;

/**
 * The configurable lists of reasons the station asks for.
 */
enum ReasonList: string
{
    case PalletClose = 'cierre';
    case BoxVoid = 'anulacion';

    /**
     * @return class-string<PalletCloseReason>|class-string<BoxVoidReason>
     */
    public function model(): string
    {
        return match ($this) {
            self::PalletClose => PalletCloseReason::class,
            self::BoxVoid => BoxVoidReason::class,
        };
    }

    /** Prefix of its entries in the admin audit log. */
    public function auditPrefix(): string
    {
        return match ($this) {
            self::PalletClose => 'pallet_close_reason',
            self::BoxVoid => 'box_void_reason',
        };
    }

    /**
     * Table and column that point to a reason of this list.
     *
     * @return array{0: string, 1: string}
     */
    public function usedBy(): array
    {
        return match ($this) {
            self::PalletClose => ['pallets', 'close_reason_id'],
            self::BoxVoid => ['box_voids', 'box_void_reason_id'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PalletClose => 'Cierre de estiba incompleta',
            self::BoxVoid => 'Anulación de caja',
        };
    }

    public function usesLabel(): string
    {
        return match ($this) {
            self::PalletClose => 'Estibas cerradas',
            self::BoxVoid => 'Cajas anuladas',
        };
    }

    public function emptyWarning(): string
    {
        return match ($this) {
            self::PalletClose => 'No hay motivos activos. Mientras la lista esté vacía, en la estación no se pueden cerrar estibas incompletas.',
            self::BoxVoid => 'No hay motivos activos. Mientras la lista esté vacía, en la estación no se pueden anular cajas.',
        };
    }
}
