<?php

namespace App\Queries;

/**
 * Board filters as typed in the page. Empty strings mean "no filter";
 * values that do not match a known status are ignored, never trusted.
 */
final readonly class PalletFilters
{
    public function __construct(
        public string $code = '',
        public string $order = '',
        public string $item = '',
        public string $lot = '',
        public string $physical = '',
        public string $receipt = '',
        public string $audit = '',
        public string $transfer = '',
        public string $from = '',
        public string $to = '',
        public string $sapDoc = '',
    ) {}
}
