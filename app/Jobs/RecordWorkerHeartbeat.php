<?php

namespace App\Jobs;

use App\Domain\Operations\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued every minute on each watched queue. Running it proves a worker
 * is taking jobs from that queue.
 */
class RecordWorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $watchedQueue)
    {
        $this->onQueue($watchedQueue);
    }

    public function handle(): void
    {
        Heartbeat::record(Heartbeat::worker($this->watchedQueue));
    }
}
