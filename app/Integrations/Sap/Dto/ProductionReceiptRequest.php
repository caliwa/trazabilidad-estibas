<?php

namespace App\Integrations\Sap\Dto;

use Carbon\CarbonImmutable;
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
        // Fixed when the receipt is requested, so a resend after midnight keeps the same date.
        public ?CarbonImmutable $postingDate = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('Un recibo de producción necesita al menos una línea.');
        }
    }
}
