<?php

namespace App\Models;

use App\Domain\Pallets\Enums\ScanEventStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PalletScanEventFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One box read by a sensor. Accepted events count exactly once; rejected ones
 * stay as evidence. Rows never change except a rejected event that is later
 * accepted.
 *
 * @property int $id
 * @property int $device_id
 * @property string $external_event_id
 * @property int|null $pallet_id
 * @property string $production_order_ref
 * @property string $item_code
 * @property string $lot_code
 * @property CarbonImmutable $event_occurred_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property ScanEventStatus $processing_status
 * @property string|null $rejection_reason
 * @property string $payload_hash
 * @property bool $is_late
 * @property int|null $box_label_id Box this read belongs to, set by the database from the QR token (one read per box)
 * @property int|null $read_by_device_id Fixed reader that read the box, when counted from boxes:reader
 */
class PalletScanEvent extends Model
{
    /** @use HasFactory<PalletScanEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processing_status' => ScanEventStatus::class,
            'event_occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<Pallet, $this>
     */
    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    /**
     * @return BelongsTo<BoxLabel, $this>
     */
    public function boxLabel(): BelongsTo
    {
        return $this->belongsTo(BoxLabel::class);
    }

    /** What people read on the label (Caja 33128293-0007); the read itself is identified by the QR token. */
    public function boxCodeForDisplay(): string
    {
        if ($this->boxLabel !== null) {
            return $this->boxLabel->code;
        }

        $raw = $this->external_event_id;
        if (strlen($raw) === 32 && ctype_xdigit($raw)) {
            return BoxLabel::query()->where('qr_token', $raw)->value('code') ?? $raw;
        }

        return $raw;
    }

    /**
     * Resuelve el código legible de la caja para accesors Blade heredados.
     */
    public function getBoxCodeDisplayAttribute(): string
    {
        return $this->boxCodeForDisplay();
    }

    /**
     * @return HasOne<BoxVoid, $this>
     */
    public function void(): HasOne
    {
        return $this->hasOne(BoxVoid::class);
    }

    /**
     * Reads that count as a box: accepted and not voided.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->where('processing_status', ScanEventStatus::Accepted)->whereDoesntHave('void');
    }
}
