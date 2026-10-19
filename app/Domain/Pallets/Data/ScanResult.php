<?php

namespace App\Domain\Pallets\Data;

use App\Domain\Pallets\Enums\ScanRejection;

/**
 * What the device gets back. Box counts are fine here: this is the
 * production side, not the blind count.
 */
final readonly class ScanResult
{
    public function __construct(
        public ScanOutcome $outcome,
        public ?string $palletCode = null,
        public ?int $boxesCount = null,
        public ?int $standard = null,
        public bool $palletClosed = false,
        public ?ScanRejection $rejection = null,
        public bool $payloadMismatch = false,
    ) {}
}
