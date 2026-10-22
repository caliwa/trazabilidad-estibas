<?php

namespace App\Models;

use App\Domain\Integration\Enums\MasterStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PalletStandardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Boxes per pallet for an item, versioned. Pallets freeze the version they
 * were created with.
 *
 * @property int $id
 * @property int $item_id
 * @property int $version
 * @property int|null $boxes_per_pallet
 * @property string $sap_source_key
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property MasterStatus $status
 * @property string|null $invalid_reason
 * @property CarbonImmutable $synced_at
 */
class PalletStandard extends Model
{
    /** @use HasFactory<PalletStandardFactory> */
    use HasFactory;

    /** sap_source_key of the test standard used while the real one is loaded in SAP. */
    public const TEST_SOURCE = 'PRUEBA';

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
