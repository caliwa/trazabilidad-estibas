<?php

namespace App\Models;

use App\Domain\Integration\Enums\MasterStatus;
use Database\Factories\BoxConversionFactorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inventory units per box for an item, versioned (DP-02). Kept as a decimal
 * string; never a float.
 */
class BoxConversionFactor extends Model
{
    /** @use HasFactory<BoxConversionFactorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MasterStatus::class,
            'units_per_box' => 'decimal:6',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
