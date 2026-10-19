<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One synchronization run of SAP masters.
 */
class SapSyncRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
