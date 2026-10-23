<?php

namespace App\Models;

use App\Domain\Audits\Enums\CountStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PalletAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One blind count attempt. expected_boxes is server-side evidence and must
 * never be serialized to the counting operator.
 *
 * @property int $id
 * @property int $pallet_id
 * @property int $attempt
 * @property int $operator_id
 * @property CountStatus $status
 * @property int|null $counted_boxes
 * @property int|null $expected_boxes
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $reaudit_of_id
 */
class PalletAudit extends Model
{
    /** @use HasFactory<PalletAuditFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = ['expected_boxes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CountStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * @return BelongsTo<PalletAudit, $this>
     */
    public function reauditOf(): BelongsTo
    {
        return $this->belongsTo(PalletAudit::class, 'reaudit_of_id');
    }

    /**
     * @return HasMany<PalletAuditTry, $this>
     */
    public function tries(): HasMany
    {
        return $this->hasMany(PalletAuditTry::class)->orderBy('try_number');
    }
}
