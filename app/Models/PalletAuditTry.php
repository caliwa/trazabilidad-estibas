<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One number typed in a blind count. Server-side evidence: the operator
 * only ever learns whether it matched.
 *
 * @property int $id
 * @property int $pallet_audit_id
 * @property int $try_number
 * @property int $counted_boxes
 * @property bool $matches
 * @property CarbonImmutable $created_at
 */
class PalletAuditTry extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'matches' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PalletAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(PalletAudit::class, 'pallet_audit_id');
    }
}
