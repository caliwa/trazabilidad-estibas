<?php

namespace App\Models;

use Database\Factories\ProductionOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Production order (local read cache of SAP).
 */
class ProductionOrder extends Model
{
    /** @use HasFactory<ProductionOrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'synced_at' => 'datetime',
            'order_date' => 'immutable_date',
            'start_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'sap_header' => 'array',
            'consulted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return HasMany<BoxLabelBatch, $this>
     */
    public function boxLabelBatches(): HasMany
    {
        return $this->hasMany(BoxLabelBatch::class);
    }

    /**
     * @return HasMany<Pallet, $this>
     */
    public function pallets(): HasMany
    {
        return $this->hasMany(Pallet::class);
    }
}
