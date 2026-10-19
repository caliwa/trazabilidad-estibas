<?php

namespace App\Integrations\Sap\Dto;

use InvalidArgumentException;

final readonly class ProductionReceiptRequest
{
    /**
     * @param  list<DocumentLine>  $lines
     */
    public function __construct(
        public string $requestUuid,
        public string $productionOrderRef,
        public array $lines,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('Un recibo de producción necesita al menos una línea.');
        }
    }
}
