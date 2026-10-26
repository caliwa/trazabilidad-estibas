<?php

namespace App\Models;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Quality\Enums\QualityStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical pallet: exactly one production order, item and lot.
 *
 * The four status columns are independent dimensions (physical, receipt,
 * audit, transfer). They are changed only by the use cases, and the
 * database triggers reject any transition the enums do not allow.
 *
 * Box quantities are hidden from serialization: a pallet sent to a page
 * must go through an explicit DTO, so the blind count cannot leak them by
 * accident.
 *
 * @property int $id
 * @property string $pallet_code
 * @property string $qr_token
 * @property int $production_order_id
 * @property int $item_id
 * @property string $production_order_ref
 * @property string $item_code
 * @property string $lot_code
 * @property int $pallet_standard_id
 * @property int $standard_boxes_snapshot
 * @property int $boxes_count
 * @property PhysicalStatus $physical_status
 * @property CloseType|null $close_type
 * @property ReceiptStatus $receipt_status
 * @property AuditStatus $audit_status
 * @property TransferStatus $transfer_status
 * @property QualityStatus $quality_status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $closed_by
 * @property CarbonImmutable|null $palletized_at
 * @property int|null $palletized_by
 * @property CarbonImmutable|null $labeled_at
 * @property int|null $labeled_by
 * @property int $label_print_count
 */
class Pallet extends Model
{
    /** @use HasFactory<PalletFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = ['boxes_count', 'standard_boxes_snapshot', 'qr_token'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'boxes_count' => 0,
        'physical_status' => 'EN_ESTIBADO',
        'receipt_status' => 'SIN_RECIBO',
        'audit_status' => 'PENDIENTE',
        'transfer_status' => 'NO_ELEGIBLE',
        'quality_status' => 'SIN_NOVEDAD',
        'label_print_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'physical_status' => PhysicalStatus::class,
            'close_type' => CloseType::class,
            'receipt_status' => ReceiptStatus::class,
            'audit_status' => AuditStatus::class,
            'transfer_status' => TransferStatus::class,
            'quality_status' => QualityStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'palletized_at' => 'datetime',
            'labeled_at' => 'datetime',
        ];
    }

    /**
     * Registered boxes over the frozen standard. A manually closed pallet
     * keeps its real percentage below 100.
     */
    public function completionPercent(): float
    {
        return round($this->boxes_count / $this->standard_boxes_snapshot * 100, 1);
    }

    public function isComplete(): bool
    {
        return $this->boxes_count === $this->standard_boxes_snapshot;
    }

    /**
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<PalletStandard, $this>
     */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(PalletStandard::class, 'pallet_standard_id');
    }

    /**
     * @return HasMany<PalletScanEvent, $this>
     */
    public function scanEvents(): HasMany
    {
        return $this->hasMany(PalletScanEvent::class);
    }

    /**
     * @return HasMany<PalletLabelPrint, $this>
     */
    public function labelPrints(): HasMany
    {
        return $this->hasMany(PalletLabelPrint::class);
    }

    /**
     * @return HasMany<PalletAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(PalletAudit::class);
    }

    /**
     * @return HasMany<ProductionReceiptPallet, $this>
     */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(ProductionReceiptPallet::class);
    }

    /**
     * @return HasMany<InventoryTransferPallet, $this>
     */
    public function transferLines(): HasMany
    {
        return $this->hasMany(InventoryTransferPallet::class);
    }
}
