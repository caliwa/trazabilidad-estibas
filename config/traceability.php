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

        // DP-03: how the label is confirmed as applied. null = not decided.
        // Expected values once approved: "print", "button" or "scan".
        'label_confirmation' => env('TRACE_LABEL_CONFIRMATION'),

        // DP-05: several pallets in one transfer document.
        'grouped_transfers_enabled' => (bool) env('TRACE_GROUPED_TRANSFERS_ENABLED', false),
    ],

];
