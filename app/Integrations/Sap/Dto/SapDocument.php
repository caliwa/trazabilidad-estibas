<?php

namespace App\Integrations\Sap\Dto;

/**
 * A document as read back from SAP, used to verify that what exists there
 * is what was requested before confirming locally.
 */
final readonly class SapDocument
{
    /**
     * @param  list<SapDocumentLine>  $lines
     */
    public function __construct(
        public int $docEntry,
        public int $docNum,
        public string $correlationId,
        public array $lines,
        public ?string $productionOrderRef = null,
        public ?string $fromWarehouse = null,
    ) {}
}
