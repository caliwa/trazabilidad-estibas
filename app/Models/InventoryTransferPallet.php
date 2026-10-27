<?php

namespace App\Models;

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Transfers\Enums\TransferPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pallet held by a SAP document request, with the quantities sent for it.
 *
 * @property int $id
 * @property int $pallet_id
 * @property PalletHold $hold_status
 * @property TransferPurpose $purpose
 * @property int $boxes
 * @property int $box_conversion_factor_id
 * @property string $units_per_box
 * @property string $sap_quantity
 * @property string $item_code
 * @property string $lot_code
 * @property int|null $sap_line_num
 * @property int $inventory_transfer_id
 * @property int $pallet_audit_id
 */
class InventoryTransferPallet extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hold_status' => PalletHold::class,
            'purpose' => TransferPurpose::class,
            'units_per_box' => 'decimal:6',
            'sap_quantity' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<InventoryTransfer, $this>
     */
    public function inventoryTransfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class);
    }

    /**
     * @return BelongsTo<Pallet, $this>
     */
    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    /**
     * @return BelongsTo<BoxConversionFactor, $this>
     */
    public function conversionFactor(): BelongsTo
    {
        return $this->belongsTo(BoxConversionFactor::class, 'box_conversion_factor_id');
    }

    /**
     * @return BelongsTo<PalletAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(PalletAudit::class, 'pallet_audit_id');
    }
}
