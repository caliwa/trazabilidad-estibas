<?php

namespace App\Integrations\Sap\Dto;

final readonly class SapDocumentLine
{
    public function __construct(
        public int $lineNum,
        public string $itemCode,
        public string $lotCode,
        public Quantity $quantity,
        public string $warehouse,
    ) {}
}
