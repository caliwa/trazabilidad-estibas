<?php

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
