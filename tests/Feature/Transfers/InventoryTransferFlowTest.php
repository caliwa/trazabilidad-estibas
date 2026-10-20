<?php

namespace Tests\Feature\Transfers;

use App\Domain\Audits\Actions\AuthorizeReaudit;
use App\Domain\Audits\Data\BlindCountOutcome;
use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferMode;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Domain\Transfers\InventoryTransferLifecycle;
use App\Enums\Role;
use App\Integrations\Sap\Dto\SapDocument;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Models\DiscrepancyCase;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\PalletAudit;
use App\Models\ProductionReceiptPallet;
use App\Models\TraceEvent;
use App\Models\User;
use App\Queries\PalletTimeline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\BuildsTransfers;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * RF-18, RF-20, RF-21 and RN-09 after the business decision to drop the
 * separate transfer step: an approved blind count requests the stock
 * transfer 04 → 05 in its own transaction, one document per pallet, with
 * the same verification and reconciliation as receipts.
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

    public function test_an_approved_count_transfers_the_pallet_from_04_to_05_with_sap_references(): void
    {
        [$pallet] = $this->receivedPallets(['L-1'])->all();
        $counter = $this->userWithRole(Role::LogisticsOperator);

        $this->assertSame(BlindCountOutcome::Approved, $this->countPallet($pallet, counter: $counter));

        $transfer = $this->transferOf($pallet);
        $this->assertSame(SapDocumentStatus::Confirmed, $transfer->status);
        $this->assertSame(TransferMode::Individual, $transfer->mode);
        $this->assertSame(['04', '05'], [$transfer->from_warehouse, $transfer->to_warehouse]);
        $this->assertNotNull($transfer->sap_doc_entry);
        $this->assertNotNull($transfer->sap_doc_num);
        $this->assertSame($counter->id, $transfer->requested_by);
        $this->assertSame(1, $this->sap()->transferWriteCalls());
        $this->assertSame('05', $this->sap()->transfers()[0]->lines[0]->warehouse);
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);

        $line = InventoryTransferPallet::sole();
        $this->assertSame(PalletHold::Confirmed, $line->hold_status);
        $this->assertNotNull($line->sap_line_num);
        $this->assertSame(PalletAudit::query()->where('pallet_id', $pallet->id)->where('status', CountStatus::Approved)->value('id'), $line->pallet_audit_id);
    }

    public function test_what_is_transferred_is_exactly_what_was_received(): void
    {
        $pallets = $this->auditedPallets(['L-1', 'L-2'], unitsPerBox: '2.5');

        foreach ($pallets as $pallet) {
            $received = ProductionReceiptPallet::query()->where('pallet_id', $pallet->id)->where('hold_status', PalletHold::Confirmed)->sole();
            $moved = InventoryTransferPallet::query()->where('pallet_id', $pallet->id)->sole();

            $this->assertSame($received->sap_quantity, $moved->sap_quantity);
            $this->assertSame($received->units_per_box, $moved->units_per_box);
            $this->assertSame($received->box_conversion_factor_id, $moved->box_conversion_factor_id);
            $this->assertSame($received->boxes, $moved->boxes);
        }

        $this->assertSame(['100', '100'], array_map(fn (SapDocument $d) => $d->lines[0]->quantity->value, $this->sap()->transfers()));
    }

    public function test_a_count_that_does_not_match_moves_nothing(): void
    {
        Notification::fake();
        [$pallet] = $this->receivedPallets(['L-1'])->all();

        $this->assertSame(BlindCountOutcome::Discrepancy, $this->countPallet($pallet, $pallet->boxes_count - 1));

        $this->assertSame(0, InventoryTransfer::count());
        $this->assertSame(0, $this->sap()->transferWriteCalls());
        $this->assertSame(TransferStatus::NotEligible, $pallet->refresh()->transfer_status);
        $this->expectViolation(fn () => $this->retryTransfers($this->admin, [$pallet]), 'no tiene la auditoría aprobada');
    }

    public function test_an_approved_reaudit_also_transfers_the_pallet(): void
    {
        config(['traceability.decisions.discrepancy_reaudit_enabled' => true]);
        Notification::fake();
        [$pallet] = $this->receivedPallets(['L-1'])->all();
        $this->countPallet($pallet, 1);
        app(AuthorizeReaudit::class)->handle($this->admin, DiscrepancyCase::sole()->id, 'Recontar', $this->transferCtx($this->admin));

        $this->assertSame(BlindCountOutcome::Approved, $this->countPallet($pallet));

        $this->assertSame(SapDocumentStatus::Confirmed, $this->transferOf($pallet)->status);
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);
    }

    public function test_the_transfer_request_and_the_count_commit_together_or_not_at_all(): void
    {
        [$pallet] = $this->receivedPallets(['L-1'])->all();

        try {
            DB::transaction(function () use ($pallet): void {
                $this->countPallet($pallet);

                throw new RuntimeException('falla después de contar');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, InventoryTransfer::count());
        $this->assertSame(0, $this->sap()->transferWriteCalls());
        $this->assertSame(AuditStatus::Pending, $pallet->refresh()->audit_status);
    }

    public function test_the_send_waits_for_the_commit_and_a_lost_job_is_queued_again(): void
    {
        [$pallet] = $this->receivedPallets(['L-1'])->all();
        // The real database queue, as in production, so after-commit is honored.
        config(['queue.default' => 'database']);

        $this->countPallet($pallet);

        $transfer = $this->transferOf($pallet);
        $this->assertSame(SapDocumentStatus::Requested, $transfer->status);
        $this->assertStringContainsString('SendInventoryTransfer', (string) DB::table('jobs')->value('payload'));

        DB::table('jobs')->delete();
        $this->travel(config()->integer('sap.reconciliation.requeue_after_minutes') + 1)->minutes();
        Artisan::call('sap:sweep');

        $this->assertStringContainsString('SendInventoryTransfer', (string) DB::table('jobs')->value('payload'));
    }

    public function test_a_pallet_with_an_active_transfer_cannot_be_requested_again(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::TimeoutWithoutCreating);
        [$pallet] = $this->auditedPallets(['L-1'])->all();
        $this->assertSame(TransferStatus::PendingReconciliation, $pallet->transfer_status);

        $this->expectViolation(fn () => $this->retryTransfers($this->admin, [$pallet]), 'no se puede trasladar');
        $this->expectViolation(fn () => $this->retryTransfers($this->admin, []), 'Selecciona');
        $this->assertSame(1, $this->sap()->transferWriteCalls());
    }

    public function test_a_lost_answer_is_reconciled_without_a_second_create(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout);
        [$pallet] = $this->auditedPallets(['L-1'])->all();
        $transfer = $this->transferOf($pallet);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->status);

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
        $transfer = $this->transferOf($this->auditedPallets(['L-1'])->first());

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
        $transfer = $this->transferOf($this->auditedPallets(['L-1'])->first());
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
        $transfer = $this->transferOf($this->auditedPallets(['L-1'])->first());
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

    public function test_a_rejected_transfer_releases_the_pallet_and_can_be_retried(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::Reject);
        [$pallet] = $this->auditedPallets(['L-1'])->all();

        $first = $this->transferOf($pallet);
        $this->assertSame(SapDocumentStatus::Failed, $first->status);
        $this->assertSame(PalletHold::Released, InventoryTransferPallet::query()->where('inventory_transfer_id', $first->id)->sole()->hold_status);
        $this->assertSame(TransferStatus::Failed, $pallet->refresh()->transfer_status);

        [$second] = $this->retryTransfers($this->admin, [$pallet]);

        $this->assertSame(SapDocumentStatus::Confirmed, $second->refresh()->status);
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);
    }

    public function test_a_person_closes_a_pending_transfer_as_failed_only_with_proof_of_absence(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::CreateThenTimeout, FakeScenario::TimeoutWithoutCreating);
        $pallets = $this->auditedPallets(['L-1', 'L-2']);
        $exists = $this->transferOf($pallets[0]);
        $absent = $this->transferOf($pallets[1]);
        $this->travel(30)->minutes();

        $this->expectViolation(fn () => $this->lifecycle()->failAfterProof($exists->id, $this->transferCtx($this->admin), 'Prueba'), 'no exista');
        $this->lifecycle()->failAfterProof($absent->id, $this->transferCtx($this->admin), 'Nunca llegó a SAP');

        $this->assertSame(SapDocumentStatus::Failed, $absent->refresh()->status);
        $this->assertSame(SapDocumentStatus::PendingReconciliation, $exists->refresh()->status);
        $this->assertSame(TransferStatus::Failed, $pallets[1]->refresh()->transfer_status);
    }

    public function test_the_sweeper_sends_a_transfer_left_hanging_to_reconciliation(): void
    {
        [$pallet] = $this->receivedPallets(['L-1'])->all();
        Queue::fake();
        $this->countPallet($pallet);
        $transfer = $this->transferOf($pallet);
        $transfer->forceFill(['status' => SapDocumentStatus::Sending, 'sent_at' => now(), 'attempts' => 1])->save();

        $this->travel(config()->integer('sap.reconciliation.stale_sending_minutes') + 1)->minutes();
        Artisan::call('sap:sweep');

        $this->assertSame(SapDocumentStatus::PendingReconciliation, $transfer->refresh()->status);
        $this->assertSame(0, $this->sap()->transferWriteCalls());
    }

    public function test_running_the_send_twice_sends_once(): void
    {
        [$pallet] = $this->receivedPallets(['L-1'])->all();
        Queue::fake();
        $this->countPallet($pallet);
        $transfer = $this->transferOf($pallet);

        $this->lifecycle()->send($transfer->id);
        $this->lifecycle()->send($transfer->id);

        $this->assertSame(1, $this->sap()->transferWriteCalls());
    }

    public function test_the_full_chain_is_traceable_from_the_pallet(): void
    {
        [$pallet] = $this->auditedPallets(['L-1'])->all();

        $documents = app(PalletTimeline::class)->documents($pallet);

        $this->assertSame(['Recibo de producción', 'Traslado 04 → 05'], $documents->pluck('kind')->all());
        $this->assertTrue($documents->every(fn (array $d) => $d['doc_num'] !== null && $d['sap_line_num'] !== null));
        $this->assertSame($documents[0]['sap_quantity'], $documents[1]['sap_quantity']);

        $events = TraceEvent::query()->where('subject_type', 'pallet')->where('subject_id', $pallet->id)->orderBy('id')->pluck('event')->all();
        $approved = array_search(TraceEventType::AuditApproved->value, $events, true);
        $requested = array_search(TraceEventType::TransferRequested->value, $events, true);
        $this->assertNotFalse($approved);
        $this->assertGreaterThan($approved, $requested);
        $this->assertContains(TraceEventType::TransferConfirmed->value, $events);
    }

    public function test_only_people_with_the_permission_retry_a_transfer(): void
    {
        $this->sap()->queueTransferScenarios(FakeScenario::Reject);
        $pallets = $this->auditedPallets(['L-1']);

        $this->expectException(AuthorizationException::class);
        $this->retryTransfers($this->userWithRole(Role::LogisticsOperator), $pallets);
    }
}
