<?php

namespace App\Models;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Transfers\Enums\TransferMode;
use Database\Factories\InventoryTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Local request for a SAP document. Confirmed only after verifying it in SAP.
 */
class InventoryTransfer extends Model
{
    /** @use HasFactory<InventoryTransferFactory> */
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
            'mode' => TransferMode::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<InventoryTransferPallet, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryTransferPallet::class);
    }
}
