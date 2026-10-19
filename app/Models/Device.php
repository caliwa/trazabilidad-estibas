<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sensor or reader that posts scan events. Authenticates with a token whose
 * sha256 is the only thing stored.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string $token_hash
 * @property bool $active
 * @property CarbonImmutable|null $last_seen_at
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    public static function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PalletScanEvent, $this>
     */
    public function scanEvents(): HasMany
    {
        return $this->hasMany(PalletScanEvent::class);
    }
}
