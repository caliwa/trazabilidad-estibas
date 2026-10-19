<?php

namespace App\Integrations\Sap\Contracts;

use App\Integrations\Sap\Dto\InventoryTransferRequest;
use App\Integrations\Sap\Dto\ProductionReceiptRequest;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Dto\SapWriteResult;
use App\Integrations\Sap\Exceptions\SapLookupFailed;

/**
 * Everything the application needs from SAP Business One, and nothing
 * about how SAP is reached. The Service Layer adapter will implement it
 * once the real environment is verified (DP-09); until then only
 * FakeSapGateway exists.
 *
 * Contract for implementations:
 *  - create*() never retries on its own. A timeout, dropped connection or
 *    unreadable answer is returned as UNCERTAIN, never as an exception and
 *    never as REJECTED.
 *  - find*ByCorrelation() returns null only when SAP proved there is no
 *    document with that correlation. When it cannot tell, it throws
 *    SapLookupFailed. "Not found" and "could not ask" are never the same.
 */
interface SapGateway
{
    public function createProductionReceipt(ProductionReceiptRequest $request): SapWriteResult;

    /**
     * @throws SapLookupFailed
     */
    public function findProductionReceiptByCorrelation(string $requestUuid): ?SapDocument;

    public function createInventoryTransfer(InventoryTransferRequest $request): SapWriteResult;

    /**
     * @throws SapLookupFailed
     */
    public function findInventoryTransferByCorrelation(string $requestUuid): ?SapDocument;
}
