<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Heartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * For external monitoring: database, scheduler and workers, each "ok" or
 * "fail", and 503 if anything fails. No versions, hosts, counts or error
 * messages: this endpoint is public. (`/up` only says PHP answers.)
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => $this->database()];

        try {
            $checks['scheduler'] = Heartbeat::isFresh(Heartbeat::SCHEDULER);
            foreach (config()->array('operations.worker_queues') as $queue) {
                $checks["worker_{$queue}"] = Heartbeat::isFresh(Heartbeat::worker($queue));
            }
        } catch (Throwable) {
            $checks['scheduler'] = false;
        }

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'fail',
            'checks' => array_map(fn (bool $ok) => $ok ? 'ok' : 'fail', $checks),
        ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    private function database(): bool
    {
        try {
            return DB::selectOne('SELECT 1 AS ok')?->ok === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
