<?php

namespace App\Models;

use App\Domain\Audits\Enums\DiscrepancyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Case opened when a blind count differs from the system quantity.
 */
class DiscrepancyCase extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DiscrepancyStatus::class,
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'evidence' => 'array',
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
     * @return BelongsTo<PalletAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(PalletAudit::class, 'pallet_audit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<PalletAudit, $this>
     */
    public function reaudit(): BelongsTo
    {
        return $this->belongsTo(PalletAudit::class, 'reaudit_id');
    }
}
