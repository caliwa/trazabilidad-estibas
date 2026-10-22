<?php

namespace App\Integrations\Sap\ServiceLayer;

use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\DocumentLine;
use App\Integrations\Sap\Dto\InventoryTransferRequest;
use App\Integrations\Sap\Dto\ProductionReceiptRequest;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Dto\SapDocumentLine;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Dto\SapWriteResult;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

/**
 * SAP Business One through Service Layer.
 *
 * Connected:
 *  - production receipts: POST InventoryGenEntries, one document line per
 *    lot against the production order (BaseType 202), with its batch and
 *    dates; found again by the correlation field carrying the request_uuid;
 *  - production orders: ProductionOrders, one by number or all released,
 *    only for finished products (ItemNo starting with "PT"), with the
 *    product from Items.
 *
 * Not connected yet: stock transfers 04 → 05. They are refused, so nothing
 * is ever sent with an unverified payload.
 *
 * Contract (see SapGateway): a write that may have reached SAP is
 * UNCERTAIN, never REJECTED; a lookup that proves nothing throws
 * SapLookupFailed, never returns null.
 */
class ServiceLayerGateway implements SapGateway
{
    /** InventoryGenEntries line based on a production order (BaseType). */
    private const BASE_TYPE_PRODUCTION_ORDER = 202;

    private const ORDER_FIELDS = 'AbsoluteEntry,DocumentNumber,ProductionOrderStatus,ProductionOrderType,ItemNo,PlannedQuantity,Warehouse,Priority,PostingDate,StartDate,DueDate,ProductionOrderOrigin';

    private const MAX_PAGES = 200;

    private const PAGE_SIZE = 500;

    /** Items read per request when bringing many orders (keeps the URL short). */
    private const ITEMS_PER_REQUEST = 20;

    private const ITEM_FIELDS = 'ItemCode,ItemName,BarCode,InventoryUOM,SalesQtyPerPackUnit,SalesPackagingUnit';

    /**
     * @param  array{correlation_field: string, costing_code: ?string, costing_code2: ?string, costing_code3: ?string}  $receipt
     */
    public function __construct(
        private readonly ServiceLayerClient $client,
        private readonly array $receipt,
        private readonly string $itemPrefix = 'PT',
    ) {
        // It goes into an OData filter: only a plain field name is accepted.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,49}$/', $receipt['correlation_field']) !== 1) {
            throw new InvalidArgumentException('SAP_RECEIPT_CORRELATION_FIELD debe ser un nombre de campo de SAP (letras, números y _).');
        }
    }

    public function createProductionReceipt(ProductionReceiptRequest $request): SapWriteResult
    {
        if ($request->productionOrderDocEntry === null) {
            return SapWriteResult::rejected('NO_DOC_ENTRY', "La OF {$request->productionOrderRef} no tiene el DocEntry de SAP. Consúltala en SAP (etiquetas de caja o tablero de OF) y vuelve a pedir el recibo.", 0);
        }

        try {
            $body = $this->receiptBody($request);
        } catch (InvalidArgumentException $e) {
            return SapWriteResult::rejected('INVALID_REQUEST', $e->getMessage(), 0);
        }

        $started = hrtime(true);

        try {
            $response = $this->client->post('InventoryGenEntries', $body);
        } catch (ConnectionException|ServiceLayerLoginFailed $e) {
            // The receipt may or may not have reached SAP: reconciliation finds out.
            return SapWriteResult::uncertain($e instanceof ServiceLayerLoginFailed ? 'SAP_LOGIN' : 'TIMEOUT', $e->getMessage(), $this->elapsed($started));
        }

        $latency = $this->elapsed($started);

        if ($response->successful()) {
            $docEntry = $response->json('DocEntry');
            $docNum = $response->json('DocNum');

            if (! is_numeric($docEntry) || ! is_numeric($docNum)) {
                return SapWriteResult::uncertain('NO_DOC_ENTRY', 'SAP respondió sin DocEntry ni DocNum.', $latency, $response->status());
            }

            // SAP answers the created document: it is what gets verified, no correlation field needed.
            // An answer without lines proves nothing: the document is then read by its DocEntry.
            $answer = (array) $response->json();
            try {
                $document = is_array($answer['DocumentLines'] ?? null) && $answer['DocumentLines'] !== []
                    ? $this->receiptDocument($answer, $request->requestUuid)
                    : null;
            } catch (Throwable) {
                // Created all the same: an unreadable answer only means reading it by DocEntry.
                $document = null;
            }

            return SapWriteResult::created((int) $docEntry, (int) $docNum, $latency, $response->status(), $document);
        }

        if ($this->mayHaveBeenProcessed($response)) {
            return SapWriteResult::uncertain(ServiceLayerError::code($response), ServiceLayerError::message($response), $latency, $response->status());
        }

        return SapWriteResult::rejected(ServiceLayerError::code($response), ServiceLayerError::message($response), $latency, $response->status());
    }

    public function findProductionReceiptByCorrelation(string $requestUuid): ?SapDocument
    {
        $field = $this->receipt['correlation_field'];

        $found = $this->read(fn () => $this->client->get('InventoryGenEntries', [
            '$filter' => "{$field} eq '{$this->literal($requestUuid)}'",
            '$select' => 'DocEntry',
        ]));

        $value = $found->json('value');
        if (! is_array($value)) {
            throw new SapLookupFailed('Service Layer respondió sin lista de documentos.');
        }

        if (count($value) > 1) {
            throw new SapLookupFailed("Hay más de un recibo con la correlación {$requestUuid}.");
        }

        if ($value === []) {
            return null;
        }

        $docEntry = $value[0]['DocEntry'] ?? null;
        if (! is_numeric($docEntry)) {
            throw new SapLookupFailed('Service Layer respondió un recibo sin DocEntry.');
        }

        $document = $this->read(fn () => $this->client->get('InventoryGenEntries('.(int) $docEntry.')'));

        return $this->receiptDocument((array) $document->json(), $requestUuid);
    }

    public function findProductionReceipt(int $docEntry): ?SapDocument
    {
        try {
            $response = $this->client->get('InventoryGenEntries('.$docEntry.')');
        } catch (ConnectionException|ServiceLayerLoginFailed $e) {
            throw new SapLookupFailed('No se pudo consultar SAP: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new SapLookupFailed('SAP respondió '.$response->status().' a la consulta: '.ServiceLayerError::message($response));
        }

        return $this->receiptDocument((array) $response->json(), '');
    }

    public function createInventoryTransfer(InventoryTransferRequest $request): SapWriteResult
    {
        return SapWriteResult::rejected('NOT_CONNECTED', 'La transferencia 04 → 05 todavía no está conectada con SAP real. No se envió nada.', 0);
    }

    public function findInventoryTransfer(int $docEntry): ?SapDocument
    {
        throw new SapLookupFailed('La transferencia 04 → 05 todavía no está conectada con SAP real.');
    }

    public function findInventoryTransferByCorrelation(string $requestUuid): ?SapDocument
    {
        throw new SapLookupFailed('La transferencia 04 → 05 todavía no está conectada con SAP real.');
    }

    public function findProductionOrder(string $docNum): ?SapProductionOrder
    {
        $docNum = trim($docNum);

        if (preg_match('/^\d{1,20}$/', $docNum) !== 1) {
            return null;
        }

        $response = $this->read(fn () => $this->client->get('ProductionOrders', [
            '$filter' => "DocumentNumber eq {$docNum} and {$this->itemFilter()}",
            '$select' => self::ORDER_FIELDS,
        ]));

        $value = $response->json('value');
        if (! is_array($value)) {
            throw new SapLookupFailed('Service Layer respondió sin lista de órdenes de fabricación.');
        }

        if ($value === []) {
            return null;
        }

        $items = [];

        return $this->productionOrder((array) $value[0], $items);
    }

    public function releasedProductionOrders(): array
    {
        $orders = [];
        $items = [];
        $response = $this->read(fn () => $this->client->get('ProductionOrders', [
            '$filter' => "ProductionOrderStatus eq 'boposReleased' and {$this->itemFilter()}",
            '$select' => self::ORDER_FIELDS,
            '$orderby' => 'AbsoluteEntry',
        ], self::PAGE_SIZE));

        for ($page = 1; ; $page++) {
            $value = $response->json('value');
            if (! is_array($value)) {
                throw new SapLookupFailed('Service Layer respondió sin lista de órdenes de fabricación.');
            }

            // The products of the whole page in a few requests, not one per order.
            $this->loadItems(array_values(array_map(fn ($row) => (string) (((array) $row)['ItemNo'] ?? ''), $value)), $items);

            foreach ($value as $row) {
                $orders[] = $this->productionOrder((array) $row, $items);
            }

            // The key has a dot in it (v1 "odata.nextLink", v2 "@odata.nextLink"): read it as is, not as a path.
            $body = (array) $response->json();
            $next = $body['odata.nextLink'] ?? $body['@odata.nextLink'] ?? null;
            if (! is_string($next) || $next === '') {
                return $orders;
            }

            if ($page >= self::MAX_PAGES) {
                throw new SapLookupFailed('Service Layer devolvió demasiadas páginas de órdenes liberadas.');
            }

            $response = $this->read(fn () => $this->client->getLink($next, self::PAGE_SIZE));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptBody(ProductionReceiptRequest $request): array
    {
        $date = ($request->postingDate ?? CarbonImmutable::today())->toDateString();

        $lines = [];
        foreach ($request->lines as $i => $line) {
            $quantity = $this->number($line->quantity);
            $lines[] = array_filter([
                'BaseType' => self::BASE_TYPE_PRODUCTION_ORDER,
                'BaseEntry' => $request->productionOrderDocEntry,
                'Quantity' => $quantity,
                'WarehouseCode' => $line->warehouse,
                'CostingCode' => $this->receipt['costing_code'],
                'CostingCode2' => $this->receipt['costing_code2'],
                'CostingCode3' => $this->receipt['costing_code3'],
                'BatchNumbers' => [$this->batch($line, $i, $quantity, $date)],
            ], fn ($value) => $value !== null && $value !== '');
        }

        return [
            'DocDate' => $date,
            $this->receipt['correlation_field'] => $request->requestUuid,
            'DocumentLines' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batch(DocumentLine $line, int $lineNumber, int|float $quantity, string $postingDate): array
    {
        if ($line->expirationDate === null) {
            throw new InvalidArgumentException("El lote {$line->lotCode} no tiene fecha de vencimiento: SAP la exige en el recibo.");
        }

        $manufacturing = $line->manufacturingDate?->toDateString() ?? $postingDate;

        // Field names as Service Layer spells them, AddmisionDate included.
        return [
            'BaseLineNumber' => $lineNumber,
            'BatchNumber' => $line->lotCode,
            'Quantity' => $quantity,
            'ManufacturingDate' => $manufacturing,
            'ExpiryDate' => $line->expirationDate->toDateString(),
            'AddmisionDate' => $postingDate,
        ];
    }

    /**
     * @param  array<mixed>  $doc
     */
    private function receiptDocument(array $doc, string $requestUuid): SapDocument
    {
        $docEntry = $doc['DocEntry'] ?? null;
        $docNum = $doc['DocNum'] ?? null;

        if (! is_numeric($docEntry) || ! is_numeric($docNum)) {
            throw new SapLookupFailed('Service Layer respondió un recibo sin DocEntry o DocNum.');
        }

        $lines = [];
        $baseEntries = [];
        foreach ((array) ($doc['DocumentLines'] ?? []) as $row) {
            $row = (array) $row;
            $lineNum = (int) ($row['LineNum'] ?? count($lines));
            $item = (string) ($row['ItemCode'] ?? '');
            $warehouse = (string) ($row['WarehouseCode'] ?? '');

            if (isset($row['BaseEntry']) && is_numeric($row['BaseEntry'])) {
                $baseEntries[(int) $row['BaseEntry']] = true;
            }

            $batches = (array) ($row['BatchNumbers'] ?? []);
            if ($batches === []) {
                // No batch: it can never match a requested lot, so verification flags it.
                $lines[] = new SapDocumentLine($lineNum, $item, '', $this->quantity($row['Quantity'] ?? null), $warehouse);

                continue;
            }

            foreach ($batches as $batch) {
                $batch = (array) $batch;
                $lines[] = new SapDocumentLine(
                    $lineNum,
                    $item,
                    (string) ($batch['BatchNumber'] ?? ''),
                    $this->quantity($batch['Quantity'] ?? null),
                    $warehouse,
                    $this->date($batch['ExpiryDate'] ?? null),
                    $this->date($batch['ManufacturingDate'] ?? null),
                );
            }
        }

        return new SapDocument(
            docEntry: (int) $docEntry,
            docNum: (int) $docNum,
            correlationId: (string) ($doc[$this->receipt['correlation_field']] ?? $requestUuid),
            lines: $lines,
            postingDate: $this->date($doc['DocDate'] ?? null),
            // One base order per receipt; anything else is left for verification to reject.
            productionOrderDocEntry: count($baseEntries) === 1 ? (int) array_key_first($baseEntries) : null,
        );
    }

    /**
     * @param  array<mixed>  $row
     * @param  array<string, array<mixed>>  $items  item master already read in this call, by code
     */
    private function productionOrder(array $row, array &$items): SapProductionOrder
    {
        $itemCode = (string) ($row['ItemNo'] ?? '');
        $docNum = $row['DocumentNumber'] ?? null;
        $docEntry = $row['AbsoluteEntry'] ?? null;

        if ($itemCode === '' || ! is_numeric($docNum) || ! is_numeric($docEntry)) {
            throw new SapLookupFailed('Service Layer respondió una orden de fabricación sin número, DocEntry o producto.');
        }

        $items[$itemCode] ??= $this->item($itemCode);
        $item = $items[$itemCode];

        $status = match ($row['ProductionOrderStatus'] ?? null) {
            'boposPlanned' => ProductionOrderStatus::Planned,
            'boposReleased' => ProductionOrderStatus::Released,
            'boposClosed' => ProductionOrderStatus::Closed,
            'boposCancelled' => ProductionOrderStatus::Cancelled,
            default => throw new SapLookupFailed('Estado de orden de fabricación desconocido: '.json_encode($row['ProductionOrderStatus'] ?? null)),
        };

        $uom = isset($item['InventoryUOM']) && is_string($item['InventoryUOM']) && $item['InventoryUOM'] !== '' ? $item['InventoryUOM'] : null;
        $barcode = isset($item['BarCode']) && is_string($item['BarCode']) && $item['BarCode'] !== '' ? $item['BarCode'] : null;

        return new SapProductionOrder(
            docEntry: (int) $docEntry,
            docNum: (string) (int) $docNum,
            type: match ($row['ProductionOrderType'] ?? null) {
                'bopotStandard' => 'Estándar',
                'bopotSpecial' => 'Especial',
                'bopotDisassembly' => 'Desmontaje',
                default => (string) ($row['ProductionOrderType'] ?? ''),
            },
            status: $status,
            itemCode: $itemCode,
            itemDescription: (string) ($item['ItemName'] ?? $itemCode),
            plannedQuantity: $this->quantity($row['PlannedQuantity'] ?? null),
            uomName: $uom,
            warehouse: (string) ($row['Warehouse'] ?? ''),
            priority: isset($row['Priority']) && is_numeric($row['Priority']) ? (int) $row['Priority'] : null,
            orderDate: $this->date($row['PostingDate'] ?? null),
            startDate: $this->date($row['StartDate'] ?? null),
            dueDate: $this->date($row['DueDate'] ?? null),
            // ProductionOrders gives the user as an internal number, not a name.
            userName: null,
            origin: is_string($row['ProductionOrderOrigin'] ?? null) ? $row['ProductionOrderOrigin'] : null,
            linkedTo: null,
            itemBarcode: $barcode,
            itemInventoryUom: $uom,
            itemSalesQtyPerPackUnit: isset($item['SalesQtyPerPackUnit']) && is_numeric($item['SalesQtyPerPackUnit']) ? $this->decimal((float) $item['SalesQtyPerPackUnit']) : null,
            itemSalesPackagingUnit: isset($item['SalesPackagingUnit']) && is_string($item['SalesPackagingUnit']) && $item['SalesPackagingUnit'] !== '' ? $item['SalesPackagingUnit'] : null,
        );
    }

    /**
     * @return array<mixed>
     */
    private function item(string $itemCode): array
    {
        $response = $this->read(fn () => $this->client->get("Items('{$this->literal($itemCode)}')", ['$select' => self::ITEM_FIELDS]));

        return (array) $response->json();
    }

    /**
     * Reads the item master of these codes into $items, ITEMS_PER_REQUEST at a
     * time. A code SAP does not return is left out, and productionOrder()
     * then reads it on its own (and fails if SAP really does not have it).
     *
     * @param  list<string>  $codes
     * @param  array<string, array<mixed>>  $items
     */
    private function loadItems(array $codes, array &$items): void
    {
        $missing = array_values(array_diff(array_unique(array_filter($codes)), array_keys($items)));

        foreach (array_chunk($missing, self::ITEMS_PER_REQUEST) as $chunk) {
            $filter = implode(' or ', array_map(fn (string $code) => "ItemCode eq '{$this->literal($code)}'", $chunk));

            try {
                $response = $this->read(fn () => $this->client->get('Items', ['$filter' => $filter, '$select' => self::ITEM_FIELDS], self::PAGE_SIZE));
            } catch (SapLookupFailed) {
                // Only a shortcut: each product is then read on its own, and that one does fail loudly.
                return;
            }

            foreach ((array) $response->json('value') as $row) {
                $row = (array) $row;
                if (isset($row['ItemCode']) && is_string($row['ItemCode'])) {
                    $items[$row['ItemCode']] = $row;
                }
            }
        }
    }

    /**
     * Runs a read; anything but a clear answer is "could not ask".
     *
     * @param  callable(): Response  $call
     */
    private function read(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException|ServiceLayerLoginFailed $e) {
            throw new SapLookupFailed('No se pudo consultar SAP: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new SapLookupFailed('SAP respondió '.$response->status().' a la consulta: '.ServiceLayerError::message($response));
        }

        return $response;
    }

    /**
     * 401 after logging in again, timeouts, throttling and server errors may
     * hide a receipt that was created: those are never taken as rejected.
     */
    private function mayHaveBeenProcessed(Response $response): bool
    {
        return in_array($response->status(), [401, 408, 429], true) || $response->serverError();
    }

    private function quantity(mixed $value): Quantity
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new SapLookupFailed('Service Layer respondió una cantidad no válida: '.json_encode($value));
        }

        return new Quantity($this->decimal((float) $value));
    }

    private function decimal(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /**
     * Only finished products: production orders whose item code starts with the
     * configured prefix (SAP_ORDER_ITEM_PREFIX, "PT").
     */
    private function itemFilter(): string
    {
        return "startswith(ItemNo,'{$this->literal($this->itemPrefix)}')";
    }

    private function number(Quantity $quantity): int|float
    {
        $text = $quantity->display();

        return ctype_digit($text) ? (int) $text : (float) $text;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse(substr($value, 0, 10))->startOfDay() : null;
    }

    /** OData string literal: single quotes doubled. */
    private function literal(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
