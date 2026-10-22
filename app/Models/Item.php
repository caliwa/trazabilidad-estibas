<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Product master (local read cache of SAP).
 *
 * @property string|null $sales_qty_per_pack_unit Items.SalesQtyPerPackUnit
 * @property string|null $sales_packaging_unit Items.SalesPackagingUnit
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'synced_at' => 'datetime',
            'shelf_life_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<PalletStandard, $this>
     */
    public function standards(): HasMany
    {
        return $this->hasMany(PalletStandard::class);
    }

    /**
     * @return HasMany<BoxConversionFactor, $this>
     */
    public function conversionFactors(): HasMany
    {
        return $this->hasMany(BoxConversionFactor::class);
    }
}
