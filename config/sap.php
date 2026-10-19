<?php

return [

    /*
    | Which SapGateway implementation is bound.
    |
    | "fake": in-memory simulator. Refused when APP_ENV=production.
    | "service_layer": not implemented yet. It will be written only after
    | the real SAP Business One environment is verified (DP-09).
    */
    'driver' => env('SAP_DRIVER', 'fake'),

    /*
    | Warehouse codes. 04 and 05 come from the business definition and
    | still have to be checked against the real SAP company (DP-09).
    */
    'warehouses' => [
        'production_receipt' => env('SAP_RECEIPT_WAREHOUSE', '04'),
        'transfer_from' => env('SAP_TRANSFER_FROM_WAREHOUSE', '04'),
        'transfer_to' => env('SAP_TRANSFER_TO_WAREHOUSE', '05'),
    ],

    /*
    | Reconciliation of documents whose outcome is unknown. A request is only
    | sent again after SAP proved it does not exist AND resend_after_minutes
    | passed since the last send (longer than the SAP timeout, so a slow
    | original cannot still land).
    */
    'reconciliation' => [
        'resend_after_minutes' => (int) env('SAP_RESEND_AFTER_MINUTES', 10),
        'max_send_attempts' => (int) env('SAP_MAX_SEND_ATTEMPTS', 3),
        'stale_sending_minutes' => (int) env('SAP_STALE_SENDING_MINUTES', 5),
        'requeue_after_minutes' => (int) env('SAP_REQUEUE_AFTER_MINUTES', 2),
        'check_every_minutes' => (int) env('SAP_CHECK_EVERY_MINUTES', 5),
    ],

    // Read by the Service Layer adapter once it exists. Secrets only from the environment.
    'service_layer' => [
        'base_url' => env('SAP_SL_BASE_URL'),
        'company_db' => env('SAP_SL_COMPANY_DB'),
        'username' => env('SAP_SL_USERNAME'),
        'password' => env('SAP_SL_PASSWORD'),
        'verify_tls' => (bool) env('SAP_SL_VERIFY_TLS', true),
        'timeout_seconds' => (int) env('SAP_SL_TIMEOUT', 30),
    ],

];
