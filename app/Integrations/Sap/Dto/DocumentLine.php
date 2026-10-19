<?php

namespace App\Integrations\Sap\Dto;

/**
 * One line to send to SAP. Pallets with the same item and lot are grouped
 * into one line by the caller; the mapping back to each pallet stays in
 * the local *_pallets tables.
 */
final readonly class DocumentLine
{
    public function __construct(
        public string $itemCode,
        public string $lotCode,
        public Quantity $quantity,
        public string $warehouse,
    ) {}
}
