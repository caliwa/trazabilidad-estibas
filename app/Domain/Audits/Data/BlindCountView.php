<?php

namespace App\Domain\Audits\Data;

/**
 * Everything the logistics operator may see while counting: identity, never
 * quantities. Built on purpose instead of handing a Pallet model to a page.
 */
final readonly class BlindCountView
{
    public function __construct(
        public int $auditId,
        public string $palletCode,
        public string $productionOrderRef,
        public string $itemCode,
        public string $itemDescription,
        public string $lotCode,
        public bool $isReaudit,
    ) {}
}
