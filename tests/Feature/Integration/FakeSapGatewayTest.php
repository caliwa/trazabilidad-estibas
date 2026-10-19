<?php

namespace Tests\Feature\Integration;

use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\DocumentLine;
use App\Integrations\Sap\Dto\InventoryTransferRequest;
use App\Integrations\Sap\Dto\ProductionReceiptRequest;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\WriteOutcome;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Integrations\Sap\Fake\FakeSapGateway;
use App\Integrations\Sap\Fake\FakeScenario;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The simulator must reproduce the situations the real integration has to
 * survive: lost answers, refused documents, failed lookups and a system
 * that does not deduplicate on its own.
 */
class FakeSapGatewayTest extends TestCase
{
    private FakeSapGateway $sap;

    protected function setUp(): void
    {
        parent::setUp();

        $sap = app(SapGateway::class);
        $this->assertInstanceOf(FakeSapGateway::class, $sap);
        $this->sap = $sap;
    }

    private function receipt(?string $uuid = null): ProductionReceiptRequest
    {
        return new ProductionReceiptRequest($uuid ?? (string) Str::uuid(), 'OP-1', [
            new DocumentLine('PT-1', 'L1', Quantity::ofBoxes(40, '12'), '04'),
        ]);
    }

    public function test_a_created_document_can_be_found_by_correlation(): void
    {
        $request = $this->receipt();

        $result = $this->sap->createProductionReceipt($request);

        $this->assertSame(WriteOutcome::Created, $result->outcome);
        $found = $this->sap->findProductionReceiptByCorrelation($request->requestUuid);
        $this->assertSame($result->docEntry, $found?->docEntry);
        $this->assertSame('480', $found->lines[0]->quantity->value);
    }

    public function test_a_lost_answer_is_uncertain_but_the_document_exists(): void
    {
        $this->sap->queueReceiptScenarios(FakeScenario::CreateThenTimeout);
        $request = $this->receipt();

        $result = $this->sap->createProductionReceipt($request);

        $this->assertSame(WriteOutcome::Uncertain, $result->outcome);
        $this->assertNull($result->docEntry);
        $this->assertNotNull($this->sap->findProductionReceiptByCorrelation($request->requestUuid));
    }

    public function test_a_timeout_before_reaching_sap_leaves_no_document(): void
    {
        $this->sap->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating);
        $request = $this->receipt();

        $this->assertSame(WriteOutcome::Uncertain, $this->sap->createProductionReceipt($request)->outcome);
        $this->assertNull($this->sap->findProductionReceiptByCorrelation($request->requestUuid));
    }

    public function test_a_rejection_creates_nothing(): void
    {
        $this->sap->queueReceiptScenarios(FakeScenario::Reject);

        $result = $this->sap->createProductionReceipt($this->receipt());

        $this->assertSame(WriteOutcome::Rejected, $result->outcome);
        $this->assertSame([], $this->sap->receipts());
    }

    public function test_a_failed_lookup_is_never_reported_as_not_found(): void
    {
        $this->sap->failNextLookups();

        $this->expectException(SapLookupFailed::class);
        $this->sap->findProductionReceiptByCorrelation((string) Str::uuid());
    }

    public function test_sending_twice_duplicates_like_a_real_system_and_lookups_refuse_to_choose(): void
    {
        $uuid = (string) Str::uuid();
        $this->sap->createProductionReceipt($this->receipt($uuid));
        $this->sap->createProductionReceipt($this->receipt($uuid));

        $this->assertSame(2, $this->sap->receiptWriteCalls());
        $this->expectException(SapLookupFailed::class);
        $this->sap->findProductionReceiptByCorrelation($uuid);
    }

    public function test_transfers_have_their_own_documents(): void
    {
        $request = new InventoryTransferRequest((string) Str::uuid(), '04', '05', [
            new DocumentLine('PT-1', 'L1', Quantity::ofBoxes(40, '12'), '05'),
        ]);

        $this->sap->createInventoryTransfer($request);

        $this->assertSame('04', $this->sap->findInventoryTransferByCorrelation($request->requestUuid)?->fromWarehouse);
        $this->assertNull($this->sap->findProductionReceiptByCorrelation($request->requestUuid));
    }
}
