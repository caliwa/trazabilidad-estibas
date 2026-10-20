<?php

namespace Tests\Feature\Transfers;

use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferMode;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Domain\Transfers\InventoryTransferLifecycle;
use App\Enums\Role;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\ProductionReceiptPallet;
use App\Models\TraceEvent;
use App\Models\User;
use App\Queries\PalletTimeline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTransfers;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * RF-18 to RF-21, RN-09 and the "Traslados" scenarios: only audited pallets,
 * individual by default, grouped only with DP-05, the same verification and
 * reconciliation as receipts, and the full chain pallet → receipt → audit →
 * transfer.
 */
class InventoryTransferFlowTest extends TestCase
{
    use BuildsTransfers, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    private function lifecycle(): InventoryTransferLifecycle
    {
        return app(InventoryTransferLifecycle::class);
    }

    private function expectViolation(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('Debía rechazarse: '.$message);
        } catch (TransferRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_an_audited_pallet_moves_from_04_to_05_with_sap_references_and_links_its_audit(): void
    {
        [$pallet] = $this->auditedPallets(['L-1'])->all();

        [$transfer] = $this->requestTransfers($this->admin, [$pallet]);

        $transfer->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $transfer->status);
        $this->assertSame(TransferMode::Individual, $transfer->mode);
        $this->assertSame(['04', '05'], [$transfer->from_warehouse, $transfer->to_warehouse]);
        $this->assertNotNull($transfer->sap_doc_entry);
        $this->assertNotNull($transfer->sap_doc_num);
        $this->assertSame(1, $this->sap()->transferWriteCalls());
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);

        $document = $this->sap()->transfers()[0];
        $this->assertSame($transfer->sap_doc_entry, $document->docEntry);
        $this->assertSame('05', $document->lines[0]->warehouse);

        $line = InventoryTransferPallet::sole();
        $this->assertSame(PalletHold::Confirmed, $line->hold_status);
        $this->assertNotNull($line->sap_line_num);
        $this->assertSame(PalletAudit::query()->where('pallet_id', $pallet->id)->where('status', CountStatus::Approved)->value('id'), $line->pallet_audit_id);
    }

    public function test_what_is_transferred_is_exactly_what_was_received(): void
    {
        $pallets = $this->auditedPallets(['L-1', 'L-2'], unitsPerBox: '2.5');

        $this->requestTransfers($this->admin, $pallets);

        foreach ($pallets as $pallet) {
            $received = ProductionReceiptPallet::query()->where('pallet_id', $pallet->id)->where('hold_status', PalletHold::Confirmed)->sole();
            $moved = InventoryTransferPallet::query()->where('pallet_id', $pallet->id)->sole();

            $this->assertSame($received->sap_quantity, $moved->sap_quantity);
            $this->assertSame($received->units_per_box, $moved->units_per_box);
            $this->assertSame($received->box_conversion_factor_id, $moved->box_conversion_factor_id);
            $this->assertSame($received->boxes, $moved->boxes);
        }

        $sent = array_map(fn (SapDocument $d) => $d->lines[0]->quantity->value, $this->sap()->transfers());
        $this->assertSame(['100', '100'], $sent);
    }

    public function test_only_audited_and_free_pallets_can_be_transferred(): void
    {
        [$received] = $this->labeledPallets(['L-0'])->all();
        $this->requestReceipts($this->admin, [$received]);
        [$audited] = $this->auditedPallets(['L-1'])->all();
        $this->requestTransfers($this->admin, [$audited]);

        $this->expectViolation(fn () => $this->requestTransfers($this->admin, [$received->refresh()]), 'no tiene la auditoría aprobada');
        $this->expectViolation(fn () => $this->requestTransfers($this->admin, [$audited->refresh()]), 'no se puede trasladar');
        $this->expectViolation(fn () => $this->requestTransfers($this->admin, []), 'Selecciona');

        $this->assertSame(1, InventoryTransfer::count());
        $this->assertSame(1, $this->sap()->transferWriteCalls());
    }

    public function test_a_pallet_held_by_a_pending_transfer_cannot_be_requested_again(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::TimeoutWithoutCreating);
        [$pallet] = $this->auditedPallets(['L-1'])->all();
        $this->requestTransfers($this->admin, [$pallet]);
        $this->assertSame(TransferStatus::PendingReconciliation, $pallet->refresh()->transfer_status);

        $this->expectViolation(fn () => $this->requestTransfers($this->admin, [$pallet]), 'no se puede trasladar');
        $this->assertSame(1, $this->sap()->transferWriteCalls());
    }

    public function test_grouping_needs_dp05_and_an_explicit_confirmation(): void
    {
        $pallets = $this->auditedPallets(['L-1', 'L-1']);

        $this->expectViolation(fn () => $this->requestTransfers($this->admin, $pallets, TransferMode::Grouped, true), 'DP-05');

        config(['traceability.decisions.grouped_transfers_enabled' => true]);
        $this->expectViolation(fn () => $this->requestTransfers($this->admin, $pallets, TransferMode::Grouped, false), 'confirmarlo');

        $this->assertSame(0, InventoryTransfer::count());
        $this->assertTrue($pallets->every(fn (Pallet $p) => $p->refresh()->transfer_status === TransferStatus::Ready));
    }

    public function test_grouping_refuses_pallets_of_different_lots(): void
    {
        config(['traceability.decisions.grouped_transfers_enabled' => true]);
        $pallets = $this->auditedPallets(['L-1', 'L-2']);

        $this->expectViolation(fn () => $this->requestTransfers($this->admin, $pallets, TransferMode::Grouped, true), 'mismo producto y lote');
        $this->assertSame(0, InventoryTransfer::count());
    }

    public function test_a_grouped_transfer_keeps_each_pallet_linked_to_its_line(): void
    {
        config(['traceability.decisions.grouped_transfers_enabled' => true]);
        $pallets = $this->auditedPallets(['L-1', 'L-1', 'L-1']);

        $transfers = $this->requestTransfers($this->admin, $pallets, TransferMode::Grouped, true);

        $this->assertCount(1, $transfers);
        $transfer = $transfers[0]->refresh();
        $this->assertSame(SapDocumentStatus::Confirmed, $transfer->status);
        $this->assertSame(1, $this->sap()->transferWriteCalls());
        $this->assertCount(1, $this->sap()->transfers()[0]->lines);
        $this->assertSame('1440', $this->sap()->transfers()[0]->lines[0]->quantity->value);

        $lines = InventoryTransferPallet::query()->where('inventory_transfer_id', $transfer->id)->get();
        $this->assertEqualsCanonicalizing($pallets->pluck('id')->all(), $lines->pluck('pallet_id')->all());
        $this->assertTrue($lines->every(fn ($l) => $l->hold_status === PalletHold::Confirmed && $l->sap_line_num !== null && $l->pallet_audit_id !== null));
    }

    public function test_a_lost_answer_is_reconciled_without_a_second_create(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout);
        [$pallet] = $this->auditedPallets(['L-1'])->all();

        [$transfer] = $this->requestTransfers($this->admin, [$pallet]);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->refresh()->status);

        $this->travel(30)->minutes();
        $this->lifecycle()->reconcile($transfer->id);

        $this->assertSame(SapDocumentStatus::Confirmed, $transfer->refresh()->status);
        $this->assertSame($this->sap()->transfers()[0]->docEntry, $transfer->sap_doc_entry);
        $this->assertSame(1, $this->sap()->transferWriteCalls());
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);
    }

    public function test_a_transfer_proved_absent_is_resent_only_after_the_grace_period(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::TimeoutWithoutCreating);
        [$transfer] = $this->requestTransfers($this->admin, $this->auditedPallets(['L-1']));

        $this->lifecycle()->reconcile($transfer->id);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->refresh()->status);
        $this->assertSame(1, $this->sap()->transferWriteCalls());

        $this->travel(config()->integer('sap.reconciliation.resend_after_minutes') + 1)->minutes();
        $this->lifecycle()->reconcile($transfer->id);

        $this->assertSame(SapDocumentStatus::Confirmed, $transfer->refresh()->status);
        $this->assertSame(2, $this->sap()->transferWriteCalls());
        $this->assertCount(1, $this->sap()->transfers());
    }

    public function test_when_sap_cannot_be_asked_nothing_changes(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::TimeoutWithoutCreating);
        [$transfer] = $this->requestTransfers($this->admin, $this->auditedPallets(['L-1']));
        $this->travel(60)->minutes();

        $this->sap()->failNextLookups();
        $this->lifecycle()->reconcile($transfer->id);

        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->refresh()->status);
        $this->assertSame('consulta_fallida', $transfer->reconciliation['last_result']);
        $this->assertSame(1, $this->sap()->transferWriteCalls());
    }

    public function test_a_document_that_does_not_match_is_never_confirmed_nor_resent(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout);
        [$transfer] = $this->requestTransfers($this->admin, $this->auditedPallets(['L-1']));
        $this->sap()->alterTransfer($transfer->request_uuid, fn (SapDocument $d) => new SapDocument($d->docEntry, $d->docNum, $d->correlationId, $d->lines, $d->productionOrderRef, '01'));

        $this->travel(60)->minutes();
        $this->lifecycle()->reconcile($transfer->id);
        $this->lifecycle()->reconcile($transfer->id);

        $transfer->refresh();
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->status);
        $this->assertTrue($transfer->reconciliation['mismatch']);
        $this->assertNull($transfer->sap_doc_entry);
        $this->assertSame(1, $this->sap()->transferWriteCalls());
    }

    public function test_a_rejected_transfer_fails_releases_the_pallet_and_it_can_be_requested_again(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::Reject);
        [$pallet] = $this->auditedPallets(['L-1'])->all();

        [$first] = $this->requestTransfers($this->admin, [$pallet]);

        $this->assertSame(SapDocumentStatus::Failed, $first->refresh()->status);
        $this->assertSame(PalletHold::Released, InventoryTransferPallet::query()->where('inventory_transfer_id', $first->id)->sole()->hold_status);
        $this->assertSame(TransferStatus::Failed, $pallet->refresh()->transfer_status);

        [$second] = $this->requestTransfers($this->admin, [$pallet]);

        $this->assertSame(SapDocumentStatus::Confirmed, $second->refresh()->status);
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);
    }

    public function test_a_person_closes_a_pending_transfer_as_failed_only_with_proof_of_absence(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout, FakeScenario::TimeoutWithoutCreating);
        $pallets = $this->auditedPallets(['L-1', 'L-2']);
        [$exists] = $this->requestTransfers($this->admin, [$pallets[0]]);
        [$absent] = $this->requestTransfers($this->admin, [$pallets[1]]);
        $this->travel(30)->minutes();

        $this->expectViolation(fn () => $this->lifecycle()->failAfterProof($exists->id, $this->transferCtx($this->admin), 'Prueba'), 'no exista');
        $this->lifecycle()->failAfterProof($absent->id, $this->transferCtx($this->admin), 'Nunca llegó a SAP');

        $this->assertSame(SapDocumentStatus::Failed, $absent->refresh()->status);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $exists->refresh()->status);
        $this->assertSame(TransferStatus::Failed, $pallets[1]->refresh()->transfer_status);
    }

    public function test_the_sweeper_handles_transfers_too(): void
    {
        $pallets = $this->auditedPallets(['L-1']);
        Queue::fake();
        [$transfer] = $this->requestTransfers($this->admin, $pallets);
        $transfer->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now(), 'attempts' => 1])->save();

        $this->travel(config()->integer('sap.reconciliation.stale_sending_minutes') + 1)->minutes();
        Artisan::call('sap:sweep');

        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->refresh()->status);
        $this->assertSame(0, $this->sap()->transferWriteCalls());
    }

    public function test_running_the_send_twice_sends_once(): void
    {
        $pallets = $this->auditedPallets(['L-1']);
        Queue::fake();
        [$transfer] = $this->requestTransfers($this->admin, $pallets);

        $this->lifecycle()->send($transfer->id);
        $this->lifecycle()->send($transfer->id);

        $this->assertSame(1, $this->sap()->transferWriteCalls());
    }

    public function test_an_approved_count_starts_the_transfer_only_when_configured(): void
    {
        [$off] = $this->auditedPallets(['L-1'])->all();
        $this->assertSame(TransferStatus::Ready, $off->transfer_status);
        $this->assertSame(0, InventoryTransfer::count());

        config(['traceability.decisions.auto_transfer_after_audit' => true]);
        [$on] = $this->auditedPallets(['L-2'])->all();

        $this->assertSame(TransferStatus::Confirmed, $on->transfer_status);
        $transfer = InventoryTransfer::sole();
        $this->assertSame(TransferMode::Individual, $transfer->mode);
        $this->assertSame([$on->id], $transfer->lines()->pluck('pallet_id')->all());
        $this->assertSame(TransferStatus::Ready, $off->refresh()->transfer_status);
    }

    public function test_a_discrepancy_never_starts_a_transfer(): void
    {
        config(['traceability.decisions.auto_transfer_after_audit' => true]);
        $counter = $this->userWithRole(Role::LogisticsOperator);
        [$pallet] = $this->labeledPallets(['L-1'])->all();
        $this->requestReceipts($this->admin, [$pallet]);

        $view = app(StartBlindCount::class)->handle($counter, PalletQr::payload($pallet->refresh()), $this->transferCtx($counter));
        app(SubmitBlindCount::class)->handle($counter, $view->auditId, $pallet->boxes_count - 1, $this->transferCtx($counter));

        $this->assertSame(0, InventoryTransfer::count());
        $this->expectViolation(fn () => $this->requestTransfers($this->admin, [$pallet->refresh()]), 'no tiene la auditoría aprobada');
    }

    public function test_the_full_chain_is_traceable_from_the_pallet(): void
    {
        [$pallet] = $this->auditedPallets(['L-1'])->all();
        $this->requestTransfers($this->admin, [$pallet]);

        $documents = app(PalletTimeline::class)->documents($pallet->refresh());

        $this->assertSame(['Recibo de producción', 'Traslado 04 → 05'], $documents->pluck('kind')->all());
        $this->assertTrue($documents->every(fn (array $d) => $d['doc_num'] !== null && $d['sap_line_num'] !== null));
        $this->assertSame($documents[0]['sap_quantity'], $documents[1]['sap_quantity']);

        $events = TraceEvent::query()->where('subject_type', 'pallet')->where('subject_id', $pallet->id)->pluck('event')->all();
        $this->assertContains(TraceEventType::TransferRequested->value, $events);
        $this->assertContains(TraceEventType::TransferConfirmed->value, $events);
    }

    public function test_only_people_with_the_permission_request_transfers(): void
    {
        $pallets = $this->auditedPallets(['L-1']);

        $this->expectException(AuthorizationException::class);
        $this->requestTransfers($this->userWithRole(Role::LogisticsOperator), $pallets);
    }
}
