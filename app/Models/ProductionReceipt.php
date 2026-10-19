<?php

namespace App\Models;

use App\Domain\Integration\Enums\SapDocumentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProductionReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Local request for a SAP document. Confirmed only after verifying it in SAP.
 *
 * @property int $id
 * @property string $request_uuid
 * @property SapDocumentStatus $status
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property int|null $sap_doc_entry
 * @property int|null $sap_doc_num
 * @property int $requested_by
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property array<string, mixed>|null $reconciliation
 * @property int $attempts
 * @property int $production_order_id
 */
class ProductionReceipt extends Model
{
    /** @use HasFactory<ProductionReceiptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SapDocumentStatus::class,
            'payload' => 'array',
            'reconciliation' => 'array',
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<ProductionReceiptPallet, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductionReceiptPallet::class);
    }
}
