<?php

namespace App\Models;

use App\Domain\Pallets\Enums\ScanEventStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PalletScanEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * Resuelve el código legible de la caja (ej. 35061309-0001) cuando external_event_id es el token de 32 hex.
     */
    public function getBoxCodeDisplayAttribute(): string
    {
        if (strlen($this->external_event_id) === 32 && ctype_xdigit($this->external_event_id)) {
            $code = BoxLabel::query()->where('qr_token', $this->external_event_id)->value('code');
            if ($code) {
                return $code;
            }
        }

        return $this->external_event_id;
    }
}
