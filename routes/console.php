<?php

use App\Domain\Operations\Heartbeat;
use App\Jobs\RecordWorkerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for SAP documents (lost jobs, dead workers, pending reconciliations).
Schedule::command('sap:sweep')->everyMinute()->withoutOverlapping();

// Blind counts nobody finished.
Schedule::command('audits:expire-counts')->everyFiveMinutes()->withoutOverlapping();

// Liveness: the scheduler records its own heartbeat and asks each worker queue for one.
Schedule::call(function (): void {
    Heartbeat::record(Heartbeat::SCHEDULER);

    foreach (config()->array('operations.worker_queues') as $queue) {
        RecordWorkerHeartbeat::dispatch($queue);
    }
})->everyMinute()->name('ops:heartbeat');

// Operational alerts (section 13).
Schedule::command('ops:check')->everyFiveMinutes()->withoutOverlapping();
