<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A counted box that no longer counts (VoidBox). Append-only.
 *
 * @property int $id
 * @property int $pallet_scan_event_id
 * @property int $pallet_id
 * @property string $box_code
 * @property int $box_void_reason_id
 * @property string|null $note
 * @property int $voided_by
 * @property CarbonImmutable $voided_at
 * @property array{boxes: int, physical_status: string, close_type: string|null} $pallet_before
 * @property array{boxes: int, physical_status: string, close_type: string|null} $pallet_after
 */
class BoxVoid extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voided_at' => 'datetime',
            'pallet_before' => 'array',
            'pallet_after' => 'array',
        ];
    }

    /**
     * @return BelongsTo<BoxVoidReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(BoxVoidReason::class, 'box_void_reason_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return BelongsTo<Pallet, $this>
     */
    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    /**
     * @return BelongsTo<PalletScanEvent, $this>
     */
    public function scanEvent(): BelongsTo
    {
        return $this->belongsTo(PalletScanEvent::class, 'pallet_scan_event_id');
    }
}
