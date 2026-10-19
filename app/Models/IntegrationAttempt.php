<?php

namespace App\Models;

use App\Domain\Integration\Enums\AttemptOutcome;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of each call made to SAP for a document.
 */
class IntegrationAttempt extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => AttemptOutcome::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'response_summary' => 'array',
        ];
    }
}
