<?php

return [

    /*
    | Which SapGateway implementation is bound.
    |
    | "fake": in-memory simulator. Refused when APP_ENV=production.
    | "service_layer": SAP Business One Service Layer. Production receipts
    | and production order lookups go to SAP; stock transfers are not
    | connected yet and are refused (see docs/conector-sap.md).
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

    /*
    | Service Layer. The credentials come from SAP_API_URL_<ENV>,
    | SAP_COMPANY_<ENV>, SAP_USER_<ENV> and SAP_PASSWORD_<ENV>, where <ENV>
    | is SAP_ENVIRONMENT (DEV by default). Secrets only from the environment,
    | never logged. The URL is the Service Layer root, e.g.
    | https://servidor:50000/b1s/v1
    */
    'environment' => strtoupper((string) env('SAP_ENVIRONMENT', 'DEV')),

    'service_layer' => [
        'base_url' => env('SAP_API_URL_'.strtoupper((string) env('SAP_ENVIRONMENT', 'DEV'))),
        'company_db' => env('SAP_COMPANY_'.strtoupper((string) env('SAP_ENVIRONMENT', 'DEV'))),
        'username' => env('SAP_USER_'.strtoupper((string) env('SAP_ENVIRONMENT', 'DEV'))),
        'password' => env('SAP_PASSWORD_'.strtoupper((string) env('SAP_ENVIRONMENT', 'DEV'))),
        'verify_tls' => (bool) env('SAP_SL_VERIFY_TLS', true),
        'timeout_seconds' => (int) env('SAP_SL_TIMEOUT', 30),
        'connect_timeout_seconds' => (int) env('SAP_SL_CONNECT_TIMEOUT', 10),
    ],

    /*
    | Production receipt (InventoryGenEntries) fixed values.
    |
    | correlation_field: header field that carries the request_uuid, so a
    | receipt can be found after a timeout and is never created twice. It
    | must exist in SAP (a user field on the receipt header, alphanumeric
    | 36). If it does not, SAP rejects the receipt and nothing is created.
    */
    'receipt' => [
        'correlation_field' => env('SAP_RECEIPT_CORRELATION_FIELD', 'U_TRZ_UUID'),
        'costing_code' => env('SAP_RECEIPT_COSTING_CODE', 'PCC'),
        'costing_code2' => env('SAP_RECEIPT_COSTING_CODE2', 'PRODUCCI'),
        'costing_code3' => env('SAP_RECEIPT_COSTING_CODE3', 'ANTIOQUI'),
    ],

];
