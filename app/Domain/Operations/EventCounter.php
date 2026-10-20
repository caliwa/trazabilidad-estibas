<?php

namespace App\Domain\Operations;

use Illuminate\Support\Facades\Cache;

/**
 * Per-minute counters in the cache, for things that leave no row behind
 * (a duplicate scan is answered but not stored again).
 */
class EventCounter
{
    public static function hit(string $name): void
    {
        $key = self::key($name, (int) floor(now()->getTimestamp() / 60));

        // add() sets the expiry only the first time; increment() keeps it.
        Cache::add($key, 0, now()->addHours(2));
        Cache::increment($key);
    }

    public static function inLast(string $name, int $minutes): int
    {
        $current = (int) floor(now()->getTimestamp() / 60);
        $keys = array_map(fn (int $m) => self::key($name, $m), range($current - $minutes + 1, $current));

        return (int) array_sum(array_map('intval', Cache::many($keys)));
    }

    private static function key(string $name, int $minute): string
    {
        return "ops:count:{$name}:{$minute}";
    }
}
