<?php

namespace App\Models;

use App\Domain\Integration\Enums\MasterStatus;
use Database\Factories\PalletStandardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Boxes per pallet for an item, versioned. Pallets freeze the version they
 * were created with.
 */
class PalletStandard extends Model
{
    /** @use HasFactory<PalletStandardFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MasterStatus::class,
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
