<?php

namespace App\Models;

use App\Domain\Quality\Enums\QualityHoldStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pallet held by quality: moved to the non-conforming warehouse
 * (resolved_* is when SAP confirmed it), and released by quality when it
 * is fine (released_*). Cancelled if SAP refused the transfer. Once closed
 * it never changes.
 *
 * @property int $id
 * @property int $pallet_id
 * @property int $held_by
 * @property CarbonImmutable $held_at
 * @property string $reason
 * @property QualityHoldStatus $status
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution_notes
 * @property int|null $inventory_transfer_id
 * @property int|null $released_by
 * @property CarbonImmutable|null $released_at
 * @property string|null $release_notes
 */
class QualityHold extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QualityHoldStatus::class,
            'held_at' => 'datetime',
            'resolved_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Pallet, $this>
     */
    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * @return BelongsTo<InventoryTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }
}
