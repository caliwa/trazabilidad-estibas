<?php

namespace App\Models;

use App\Domain\Shared\ActorType;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only traceability log of critical operations (see TraceLogger).
 */
class TraceEvent extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'actor_type' => ActorType::class,
            'origin' => Origin::class,
            'result' => TraceResult::class,
            'from_state' => 'array',
            'to_state' => 'array',
            'sap_refs' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
