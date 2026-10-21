<?php

namespace App\Integrations\Sap\Dto;

use Carbon\CarbonImmutable;

final readonly class SapDocumentLine
{
    public function __construct(
        public int $lineNum,
        public string $itemCode,
        public string $lotCode,
        public Quantity $quantity,
        public string $warehouse,
        public ?CarbonImmutable $expirationDate = null,
        public ?CarbonImmutable $manufacturingDate = null,
    ) {}
}
