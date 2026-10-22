<?php

namespace App\Domain\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * State of the last "bring released orders from SAP" run, shared through
 * the cache so the board can show it while the queue does the work.
 *
 * @phpstan-type Status array{state: 'queued'|'running'|'done'|'failed', at: string, released?: int, problems?: array<string, string>, error?: ?string}
 */
final class OrderSyncStatus
{
    private const KEY = 'orders:sync:status';

    /** A run that never reports back stops showing as running after this. */
    private const STALE_MINUTES = 15;

    /**
     * @return Status|null
     */
    public static function get(): ?array
    {
        $status = Cache::get(self::KEY);

        if (! is_array($status) || ! in_array($status['state'] ?? null, ['queued', 'running', 'done', 'failed'], true) || ! is_string($status['at'] ?? null)) {
            return null;
        }

        /** @var Status $status */
        return $status;
    }

    public static function busy(): bool
    {
        $status = self::get();

        return $status !== null
            && in_array($status['state'], ['queued', 'running'], true)
            && CarbonImmutable::parse($status['at'])->addMinutes(self::STALE_MINUTES)->isFuture();
    }

    public static function queued(): void
    {
        self::put(['state' => 'queued', 'at' => now()->toIso8601String()]);
    }

    public static function running(): void
    {
        self::put(['state' => 'running', 'at' => now()->toIso8601String()]);
    }

    public static function finished(SyncResult $result): void
    {
        self::put([
            'state' => $result->error === null ? 'done' : 'failed',
            'at' => now()->toIso8601String(),
            'released' => $result->released,
            'problems' => $result->problems,
            'error' => $result->error,
        ]);
    }

    public static function failed(string $error): void
    {
        self::put(['state' => 'failed', 'at' => now()->toIso8601String(), 'error' => $error]);
    }

    /**
     * @param  Status  $status
     */
    private static function put(array $status): void
    {
        Cache::put(self::KEY, $status, now()->addDay());
    }
}
