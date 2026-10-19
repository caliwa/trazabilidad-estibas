<?php

namespace App\Models;

use App\Domain\Integration\Enums\PalletHold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pallet held by a SAP document request, with the quantities sent for it.
 */
class ProductionReceiptPallet extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hold_status' => PalletHold::class,
            'units_per_box' => 'decimal:6',
            'sap_quantity' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<ProductionReceipt, $this>
     */
    public function productionReceipt(): BelongsTo
    {
        return $this->belongsTo(ProductionReceipt::class);
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
}
