<?php

namespace App\Integrations\Sap\Dto;

use InvalidArgumentException;

final readonly class InventoryTransferRequest
{
    /**
     * @param  list<DocumentLine>  $lines  warehouse in each line is the destination
     */
    public function __construct(
        public string $requestUuid,
        public string $fromWarehouse,
        public string $toWarehouse,
        public array $lines,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('Un traslado necesita al menos una línea.');
        }

        if ($fromWarehouse === $toWarehouse) {
            throw new InvalidArgumentException('El almacén de origen y el de destino deben ser distintos.');
        }
    }
}
