<?php

namespace App\Integrations\Sap\Fake;

use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\DocumentLine;
use App\Integrations\Sap\Dto\InventoryTransferRequest;
use App\Integrations\Sap\Dto\ProductionReceiptRequest;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Dto\SapDocumentLine;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Dto\SapWriteResult;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use Carbon\CarbonImmutable;

/**
 * In-memory SAP for tests and local work. It behaves like a system without
 * idempotency: sending the same request twice creates two documents, so
 * tests can prove the application never does that.
 *
 * Not available in production (see AppServiceProvider).
 */
class FakeSapGateway implements SapGateway
{
    private const RECEIPTS = 'receipts';

    private const TRANSFERS = 'transfers';

    /** @var array<string, list<SapDocument>> */
    private array $documents = [self::RECEIPTS => [], self::TRANSFERS => []];

    /** @var array<string, list<FakeScenario>> */
    private array $scenarios = [self::RECEIPTS => [], self::TRANSFERS => []];

    /** @var array<string, int> */
    private array $writeCalls = [self::RECEIPTS => 0, self::TRANSFERS => 0];

    private int $failingLookups = 0;

    /** @var array<string, SapProductionOrder> */
    private array $productionOrders;

    private int $nextDocEntry;

    public function __construct()
    {
        // Like SAP, DocEntry never repeats, also across processes running at once
        // (the concurrency tests): each process numbers in its own block.
        $this->nextDocEntry = (getmypid() % 20_000) * 100_000 + 1;
        $this->productionOrders = FakeSapCatalog::productionOrders();
    }

    public function queueReceiptScenarios(FakeScenario ...$scenarios): self
    {
        array_push($this->scenarios[self::RECEIPTS], ...$scenarios);

        return $this;
    }

    public function queueTransferScenarios(FakeScenario ...$scenarios): self
    {
        array_push($this->scenarios[self::TRANSFERS], ...$scenarios);

        return $this;
    }

    /**
     * The next $count lookups throw SapLookupFailed.
     */
    public function failNextLookups(int $count = 1): self
    {
        $this->failingLookups += $count;

        return $this;
    }

    /**
     * Replaces a stored receipt, to simulate a document in SAP that does not
     * match what was requested (edited by someone, or a different request).
     *
     * @param  callable(SapDocument): SapDocument  $change
     */
    public function alterReceipt(string $correlation, callable $change): void
    {
        foreach ($this->documents[self::RECEIPTS] as $i => $document) {
            if ($document->correlationId === $correlation) {
                $this->documents[self::RECEIPTS][$i] = $change($document);
            }
        }
    }

    /**
     * Same as alterReceipt(), for transfers.
     *
     * @param  callable(SapDocument): SapDocument  $change
     */
    public function alterTransfer(string $correlation, callable $change): void
    {
        foreach ($this->documents[self::TRANSFERS] as $i => $document) {
            if ($document->correlationId === $correlation) {
                $this->documents[self::TRANSFERS][$i] = $change($document);
            }
        }
    }

    /**
     * Adds or replaces a production order, for tests.
     */
    public function putProductionOrder(SapProductionOrder $order): void
    {
        $this->productionOrders[$order->docNum] = $order;
    }

    public function receiptWriteCalls(): int
    {
        return $this->writeCalls[self::RECEIPTS];
    }

    public function transferWriteCalls(): int
    {
        return $this->writeCalls[self::TRANSFERS];
    }

    /**
     * @return list<SapDocument>
     */
    public function receipts(): array
    {
        return $this->documents[self::RECEIPTS];
    }

    /**
     * @return list<SapDocument>
     */
    public function transfers(): array
    {
        return $this->documents[self::TRANSFERS];
    }

    public function createProductionReceipt(ProductionReceiptRequest $request): SapWriteResult
    {
        return $this->write(self::RECEIPTS, $request->requestUuid, $request->lines, $request->productionOrderRef, null, $request->postingDate);
    }

    public function findProductionReceiptByCorrelation(string $requestUuid): ?SapDocument
    {
        return $this->find(self::RECEIPTS, $requestUuid);
    }

    public function createInventoryTransfer(InventoryTransferRequest $request): SapWriteResult
    {
        return $this->write(self::TRANSFERS, $request->requestUuid, $request->lines, null, $request->fromWarehouse);
    }

    public function findInventoryTransferByCorrelation(string $requestUuid): ?SapDocument
    {
        return $this->find(self::TRANSFERS, $requestUuid);
    }

    public function findProductionOrder(string $docNum): ?SapProductionOrder
    {
        if ($this->failingLookups > 0) {
            $this->failingLookups--;

            throw new SapLookupFailed('Consulta a SAP sin respuesta (simulador).');
        }

        return $this->productionOrders[trim($docNum)] ?? null;
    }

    /**
     * @param  list<DocumentLine>  $lines
     */
    private function write(string $kind, string $correlation, array $lines, ?string $orderRef, ?string $fromWarehouse, ?CarbonImmutable $postingDate = null): SapWriteResult
    {
        $this->writeCalls[$kind]++;
        $scenario = array_shift($this->scenarios[$kind]) ?? FakeScenario::Create;

        if ($scenario === FakeScenario::Reject) {
            return SapWriteResult::rejected('FAKE-REJECTED', 'Documento rechazado por el simulador.', 5, 400);
        }

        if ($scenario === FakeScenario::TimeoutWithoutCreating) {
            return SapWriteResult::uncertain('TIMEOUT', 'Sin respuesta de SAP.', 30_000);
        }

        $docEntry = $this->nextDocEntry++;
        $document = new SapDocument(
            docEntry: $docEntry,
            docNum: $docEntry,
            correlationId: $correlation,
            lines: array_map(
                fn (DocumentLine $line, int $i) => new SapDocumentLine($i, $line->itemCode, $line->lotCode, $line->quantity, $line->warehouse, $line->expirationDate, $line->manufacturingDate),
                $lines,
                array_keys($lines),
            ),
            productionOrderRef: $orderRef,
            fromWarehouse: $fromWarehouse,
            postingDate: $postingDate,
        );
        $this->documents[$kind][] = $document;

        return $scenario === FakeScenario::CreateThenTimeout
            ? SapWriteResult::uncertain('TIMEOUT', 'Sin respuesta de SAP.', 30_000)
            : SapWriteResult::created($document->docEntry, $document->docNum, 12, 201);
    }

    private function find(string $kind, string $correlation): ?SapDocument
    {
        if ($this->failingLookups > 0) {
            $this->failingLookups--;

            throw new SapLookupFailed('Consulta a SAP sin respuesta (simulador).');
        }

        $matches = array_values(array_filter($this->documents[$kind], fn (SapDocument $d) => $d->correlationId === $correlation));

        if (count($matches) > 1) {
            // Two documents for one request means the correlation is not unique: nothing can be confirmed.
            throw new SapLookupFailed("Hay más de un documento con la correlación {$correlation}.");
        }

        return $matches[0] ?? null;
    }
}
