<?php

return [

    'pallet_code' => [
        // Final format depends on DP-07 (label design).
        'prefix' => env('PALLET_CODE_PREFIX', 'EST'),
        'digits' => (int) env('PALLET_CODE_DIGITS', 8),
    ],

    // Printable label size. Final design, printer and protocol are DP-07.
    'label' => [
        'width_mm' => (int) env('LABEL_WIDTH_MM', 100),
        'height_mm' => (int) env('LABEL_HEIGHT_MM', 150),
    ],

    // A blind count left open longer than this is cancelled so the pallet can be counted again.
    'count_timeout_minutes' => (int) env('TRACE_COUNT_TIMEOUT_MINUTES', 60),

    /*
    | Tries a blind count has before a discrepancy opens. They count per
    | pallet and round (the first count, or each reaudit enabled), even if the
    | count is cancelled and started again.
    */
    'count_tries' => max(1, (int) env('TRACE_COUNT_TRIES', 3)),

    /*
    | Boxes per pallet for a product that has no pallet standard yet, used by
    | the manual box reads at the production station while the real standard
    | is loaded in SAP. The standard it creates is marked PRUEBA. 0 turns it off.
    */
    'test_pallet_boxes' => (int) env('TRACE_TEST_PALLET_BOXES', 40),

    // Scan events accepted per device per minute before answering 429.
    'scan_rate_per_minute' => (int) env('TRACE_SCAN_RATE_PER_MINUTE', 300),

    /*
    | Switches for behavior that depends on a pending business decision.
    | They stay off until the decision is approved; the code paths that need
    | them refuse to run while they are off.
    */
    'decisions' => [
        // DP-01: sensor event id, its uniqueness scope and late events.
        'scan_ingestion_enabled' => (bool) env('TRACE_SCAN_INGESTION_ENABLED', false),

        // DP-03: how the label is confirmed as applied: "print", "button" or "scan".
        // Decided: the QR of the label stuck on the pallet is read ("scan").
        'label_confirmation' => env('TRACE_LABEL_CONFIRMATION', 'scan'),

        // DP-04: how discrepancies are resolved. The reaudit was approved: a supervisor
        // enables a new blind count, by someone who has not counted that pallet.
        // Closing with an action stays off until those actions are approved.
        'discrepancy_reaudit_enabled' => (bool) env('TRACE_DISCREPANCY_REAUDIT_ENABLED', true),
        // Actions an administrator may close a case with, e.g. "AJUSTE_SAP_REGISTRADO,RECHAZO_FISICO".
        'discrepancy_close_actions' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRACE_DISCREPANCY_CLOSE_ACTIONS', ''))))),
    ],

];
