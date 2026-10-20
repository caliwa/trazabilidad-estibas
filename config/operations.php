<?php

/*
| Operational alerts (ops:check, /operations, /health). Windows are minutes.
| Thresholds are a starting point: tune them with real plant volume.
*/
return [

    // How long the same alert waits before it is notified again.
    'notify_cooldown_minutes' => (int) env('OPS_NOTIFY_COOLDOWN_MINUTES', 60),

    'rejected_scans' => [
        'window' => (int) env('OPS_REJECTED_SCANS_WINDOW', 15),
        'threshold' => (int) env('OPS_REJECTED_SCANS_THRESHOLD', 10),
    ],

    'duplicate_scans' => [
        'window' => (int) env('OPS_DUPLICATE_SCANS_WINDOW', 15),
        'threshold' => (int) env('OPS_DUPLICATE_SCANS_THRESHOLD', 50),
    ],

    // Rejections caused by a missing, invalid or expired standard.
    'missing_standard_window' => (int) env('OPS_MISSING_STANDARD_WINDOW', 60),

    'sap_errors_window' => (int) env('OPS_SAP_ERRORS_WINDOW', 60),

    // A document pending reconciliation for longer than this is reported.
    'pending_reconciliation_minutes' => (int) env('OPS_PENDING_RECONCILIATION_MINUTES', 30),

    'queue' => [
        'max_pending' => (int) env('OPS_QUEUE_MAX_PENDING', 100),
        'max_wait_minutes' => (int) env('OPS_QUEUE_MAX_WAIT_MINUTES', 10),
    ],

    'failed_jobs_window' => (int) env('OPS_FAILED_JOBS_WINDOW', 1440),

    // The scheduler and each worker queue must have reported within this time.
    'heartbeat_max_age_minutes' => (int) env('OPS_HEARTBEAT_MAX_AGE_MINUTES', 3),

    // Queues that must have a live worker.
    'worker_queues' => ['sap', 'default'],
];
