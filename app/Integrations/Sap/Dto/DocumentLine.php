<?php

namespace App\Integrations\Sap\Dto;

use Carbon\CarbonImmutable;

/**
 * One line to send to SAP. Pallets with the same item and lot are grouped
 * into one line by the caller; the mapping back to each pallet stays in
 * the local *_pallets tables.
 *
 * A production receipt brings the batch to life in SAP, so its lines also
 * carry the batch dates: expiration (from the box labels) and
 * manufacturing (the receipt's posting date, which SAP also uses as the
 * admission date). Transfers move an existing batch and leave them null.
 */
final readonly class DocumentLine
{
    public function __construct(
        public string $itemCode,
        public string $lotCode,
        public Quantity $quantity,
        public string $warehouse,
        public ?CarbonImmutable $expirationDate = null,
        public ?CarbonImmutable $manufacturingDate = null,
    ) {}
}
