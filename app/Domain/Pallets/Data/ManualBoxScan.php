<?php

namespace App\Domain\Pallets\Data;

use Carbon\CarbonImmutable;

/**
 * Outcome of one box label read by hand at the production station: what
 * the label says and where the box went.
 */
final readonly class ManualBoxScan
{
    public function __construct(
        public string $orderRef,
        public string $itemCode,
        public string $itemDescription,
        public string $lotCode,
        public CarbonImmutable $expiration,
        public string $boxCode,
        public ScanResult $result,
        /** True when the pallet standard is the test value (no real one loaded yet). */
        public bool $testStandard = false,
    ) {}
}
