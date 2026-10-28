<?php

namespace App\Domain\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Last time the scheduler ran and each worker queue processed a job. A
 * stale heartbeat means the process is down or stuck.
 */
class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public static function record(string $name): void
    {
        Cache::forever(self::key($name), now()->getTimestamp());
    }

    public static function last(string $name): ?CarbonImmutable
    {
        $at = Cache::get(self::key($name));

        return is_int($at) ? CarbonImmutable::createFromTimestamp($at) : null;
    }

    public static function isFresh(string $name): bool
    {
        $last = self::last($name);

        return $last !== null && $last->greaterThanOrEqualTo(now()->subMinutes(config()->integer('operations.heartbeat_max_age_minutes')));
    }

    public static function worker(string $queue): string
    {
        return "worker:{$queue}";
    }

    /** A fixed box reader's connection (boxes:reader). */
    public static function reader(string $deviceCode): string
    {
        return "reader:{$deviceCode}";
    }

    private static function key(string $name): string
    {
        return "ops:heartbeat:{$name}";
    }
}
