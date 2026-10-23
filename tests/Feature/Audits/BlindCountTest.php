<?php

namespace Tests\Feature\Audits;

use App\Domain\Audits\Actions\AuthorizeReaudit;
use App\Domain\Audits\Actions\CancelBlindCount;
use App\Domain\Audits\Actions\ResolveDiscrepancy;
use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Audits\Data\BlindCountOutcome;
use App\Domain\Audits\Data\BlindCountView;
use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Pallets\PalletQr;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Role;
use App\Models\DiscrepancyCase;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\TraceEvent;
use App\Models\User;
use App\Notifications\DiscrepancyOpened;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Logistics audit: CA-14 (only received, not transferred), CA-15 (the
 * expected quantity never reaches the operator), CA-16 (difference opens a
 * case, blocks the transfer and notifies), RN-14 (no automatic adjustment),
 * segregation of duties and the DP-04 switches.
 */
class BlindCountTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::LogisticsOperator);
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    private function ctx(User $user): TraceContext
    {
        return TraceContext::forUser($user, Origin::Web, (string) Str::uuid());
    }

    private function start(Pallet $pallet, ?User $user = null): BlindCountView
    {
        $user ??= $this->operator;

        return app(StartBlindCount::class)->handle($user, PalletQr::payload($pallet), $this->ctx($user));
    }

    private function submit(BlindCountView $view, int $counted, ?User $user = null): BlindCountOutcome
    {
        $user ??= $this->operator;

        // The same number on every try: a wrong count ends in a discrepancy, as before the tries existed.
        do {
            $outcome = app(SubmitBlindCount::class)->handle($user, $view->auditId, $counted, $this->ctx($user));
        } while ($outcome === BlindCountOutcome::Retry);

        return $outcome;
    }

    private function expectViolation(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Debía rechazarse: {$message}");
        } catch (AuditRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_only_received_and_not_transferred_pallets_are_counted(): void
    {
        $this->expectViolation(fn () => $this->start(Pallet::factory()->labeled()->create()), 'no tiene recibo confirmado');
        $this->expectViolation(fn () => app(StartBlindCount::class)->handle($this->operator, 'EST-00000001', $this->ctx($this->operator)), 'no es una etiqueta');
        $this->expectViolation(fn () => app(StartBlindCount::class)->handle($this->operator, PalletQr::PREFIX.str_repeat('a', 32), $this->ctx($this->operator)), 'No existe');

        $approved = Pallet::factory()->approved()->create();
        $this->expectViolation(fn () => $this->start($approved), 'ya tiene la auditoría aprobada');

        $approved->forceFill(['transfer_status' => TransferStatus::Requested])->save();
        $approved->forceFill(['transfer_status' => TransferStatus::Confirmed])->save();
        $this->expectViolation(fn () => $this->start($approved), 'ya fue trasladada');
    }

    public function test_what_the_operator_gets_has_no_quantity_at_all(): void
    {
        $pallet = Pallet::factory()->receivedInSap()->create(['standard_boxes_snapshot' => 8861]);

        $view = $this->start($pallet);

        $this->assertSame($pallet->pallet_code, $view->palletCode);
        $properties = array_map(fn ($p) => $p->getName(), (new ReflectionClass(BlindCountView::class))->getProperties());
        $this->assertSame(['auditId', 'palletCode', 'productionOrderRef', 'itemCode', 'itemDescription', 'lotCode', 'isReaudit'], $properties);
        $this->assertStringNotContainsString('8861', (string) json_encode((array) $view));
    }

    public function test_a_matching_count_approves_the_audit_and_transfers_the_pallet_to_05(): void
    {
        $pallet = Pallet::factory()->receivedInSap()->create();

        $outcome = $this->submit($this->start($pallet), $pallet->boxes_count);

        $this->assertSame(BlindCountOutcome::Approved, $outcome);
        $pallet->refresh();
        $this->assertSame(AuditStatus::Approved, $pallet->audit_status);
        $this->assertSame(TransferStatus::Confirmed, $pallet->transfer_status);
        $this->assertSame(1, InventoryTransfer::count());
        $audit = PalletAudit::sole();
        $this->assertSame([CountStatus::Approved, $pallet->boxes_count, $this->operator->id], [$audit->status, $audit->counted_boxes, $audit->operator_id]);
        $this->assertSame(0, DiscrepancyCase::count());
    }

    public function test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter(): void
    {
        Notification::fake();
        $resolverWhoAlsoCounts = $this->userWithRole(Role::Admin);
        $pallet = Pallet::factory()->receivedInSap()->create();

        $outcome = $this->submit($this->start($pallet), $pallet->boxes_count - 2);

        $this->assertSame(BlindCountOutcome::Discrepancy, $outcome);
        $pallet->refresh();
        $this->assertSame(AuditStatus::Discrepancy, $pallet->audit_status);
        $this->assertSame(TransferStatus::NotEligible, $pallet->transfer_status);
        $this->assertSame(ReceiptStatus::Confirmed, $pallet->receipt_status);
        $case = DiscrepancyCase::sole();
        $this->assertSame(DiscrepancyStatus::Open, $case->status);
        $wrong = $pallet->boxes_count - 2;
        $this->assertEquals(['counted_boxes' => $wrong, 'expected_boxes' => $pallet->boxes_count, 'attempt' => 1, 'tries' => [$wrong, $wrong, $wrong]], $case->evidence);

        Notification::assertSentTo([$this->admin, $resolverWhoAlsoCounts], DiscrepancyOpened::class);
        Notification::assertNotSentTo($this->operator, DiscrepancyOpened::class);
    }

    public function test_a_count_belongs_to_whoever_started_it(): void
    {
        $pallet = Pallet::factory()->receivedInSap()->create();
        $view = $this->start($pallet);
        $colleague = $this->userWithRole(Role::LogisticsOperator);

        $this->expectViolation(fn () => $this->start($pallet, $colleague), 'la está contando otra persona');
        $this->expectViolation(fn () => $this->submit($view, 10, $colleague), 'no es tuyo');
        $this->assertSame($view->auditId, $this->start($pallet)->auditId);

        $this->submit($view, $pallet->boxes_count);
        $this->expectViolation(fn () => $this->submit($view, $pallet->boxes_count), 'ya terminó');
    }

    public function test_cancelling_or_expiring_a_count_frees_the_pallet(): void
    {
        $pallet = Pallet::factory()->receivedInSap()->create();
        app(CancelBlindCount::class)->handle($this->operator, $this->start($pallet)->auditId, 'Me llamaron', $this->ctx($this->operator));
        $this->assertSame(AuditStatus::Pending, $pallet->refresh()->audit_status);

        $this->start($pallet);
        $this->travel(config()->integer('traceability.count_timeout_minutes') + 1)->minutes();
        Artisan::call('audits:expire-counts');

        $this->assertSame(AuditStatus::Pending, $pallet->refresh()->audit_status);
        $this->assertSame(2, PalletAudit::where('status', CountStatus::Cancelled)->count());
    }

    public function test_a_discrepancy_blocks_new_counts_until_dp04_allows_a_reaudit(): void
    {
        // The reaudit is approved and on by default; with it off nothing reopens the count.
        config(['traceability.decisions.discrepancy_reaudit_enabled' => false]);
        $pallet = Pallet::factory()->receivedInSap()->create();
        $this->submit($this->start($pallet), 1);
        $other = $this->userWithRole(Role::LogisticsOperator);

        $this->expectViolation(fn () => $this->start($pallet, $other), 'discrepancia abierta');
        $this->expectViolation(fn () => app(AuthorizeReaudit::class)->handle($this->admin, DiscrepancyCase::sole()->id, 'Recontar', $this->ctx($this->admin)), 'DP-04');
    }

    public function test_an_authorized_reaudit_by_the_same_operator_that_matches_closes_the_case(): void
    {
        config(['traceability.decisions.discrepancy_reaudit_enabled' => true]);
        Notification::fake();
        $pallet = Pallet::factory()->receivedInSap()->create();
        $this->submit($this->start($pallet), 1);
        $this->expectViolation(fn () => $this->start($pallet), 'discrepancia abierta');
        $case = app(AuthorizeReaudit::class)->handle($this->admin, DiscrepancyCase::sole()->id, 'Recontar', $this->ctx($this->admin));
        $this->assertSame(DiscrepancyStatus::InReview, $case->status);

        // The operator who counted before can do the reaudit.
        $view = $this->start($pallet);
        $this->assertTrue($view->isReaudit);
        $this->assertSame(BlindCountOutcome::Approved, $this->submit($view, $pallet->boxes_count));

        $case->refresh();
        $this->assertSame([DiscrepancyStatus::Resolved, 'REAUDITORIA_COINCIDE', $this->admin->id], [$case->status, $case->resolution_action, $case->resolved_by]);
        $this->assertSame(TransferStatus::Confirmed, $pallet->refresh()->transfer_status);
    }

    public function test_a_reaudit_that_differs_again_opens_a_new_case(): void
    {
        config(['traceability.decisions.discrepancy_reaudit_enabled' => true]);
        Notification::fake();
        $pallet = Pallet::factory()->receivedInSap()->create();
        $this->submit($this->start($pallet), 1);
        app(AuthorizeReaudit::class)->handle($this->admin, DiscrepancyCase::sole()->id, 'Recontar', $this->ctx($this->admin));

        $other = $this->userWithRole(Role::LogisticsOperator);
        $this->submit($this->start($pallet, $other), 2, $other);

        $cases = DiscrepancyCase::orderBy('id')->get();
        $this->assertSame([DiscrepancyStatus::Resolved, DiscrepancyStatus::Open], $cases->pluck('status')->all());
        $this->assertSame('REAUDITORIA_DIFIERE', $cases[0]->resolution_action);
        $this->assertSame(AuditStatus::Discrepancy, $pallet->refresh()->audit_status);
        $this->assertSame(TransferStatus::NotEligible, $pallet->transfer_status);
    }

    public function test_closing_a_case_needs_an_approved_action_a_different_person_and_never_adjusts_anything(): void
    {
        $pallet = Pallet::factory()->receivedInSap()->create();
        $boxes = $pallet->boxes_count;
        $counter = $this->userWithRole(Role::Admin);
        $this->submit($this->start($pallet, $counter), 1, $counter);
        $caseId = DiscrepancyCase::sole()->id;
        $resolve = fn (User $u, string $action) => app(ResolveDiscrepancy::class)->handle($u, $caseId, $action, 'Ajuste registrado en SAP', ['sap_reference' => 'DOC-77'], $this->ctx($u));

        $this->expectViolation(fn () => $resolve($this->admin, 'AJUSTE_SAP_REGISTRADO'), 'DP-04');

        config(['traceability.decisions.discrepancy_close_actions' => ['AJUSTE_SAP_REGISTRADO']]);
        $this->expectViolation(fn () => $resolve($this->admin, 'BORRAR_TODO'), 'no está entre las aprobadas');
        $this->expectViolation(fn () => $resolve($counter, 'AJUSTE_SAP_REGISTRADO'), 'su propia discrepancia');

        $case = $resolve($this->admin, 'AJUSTE_SAP_REGISTRADO');

        $this->assertSame([DiscrepancyStatus::Resolved, $this->admin->id], [$case->status, $case->resolved_by]);
        $this->assertSame('DOC-77', $case->evidence['resolution']['sap_reference']);
        $pallet->refresh();
        $this->assertSame($boxes, $pallet->boxes_count);
        $this->assertSame(TransferStatus::NotEligible, $pallet->transfer_status);
        $this->assertSame(AuditStatus::Discrepancy, $pallet->audit_status);
    }

    public function test_people_without_the_permissions_are_refused_on_the_server(): void
    {
        $pallet = Pallet::factory()->receivedInSap()->create();

        foreach ([Role::Viewer, Role::ProductionOperator, Role::TraceabilityAdmin] as $role) {
            try {
                $this->start($pallet, $this->userWithRole($role));
                $this->fail("{$role->value} no debe contar");
            } catch (AuthorizationException) {
            }
        }

        $this->submit($this->start($pallet), 1);
        $this->expectException(AuthorizationException::class);
        app(ResolveDiscrepancy::class)->handle($this->operator, DiscrepancyCase::sole()->id, 'X', 'Y', [], $this->ctx($this->operator));
    }

    /** One number typed, without retrying: what the operator gets back for that try. */
    private function type(BlindCountView $view, int $counted, ?User $user = null): BlindCountOutcome
    {
        $user ??= $this->operator;

        return app(SubmitBlindCount::class)->handle($user, $view->auditId, $counted, $this->ctx($user));
    }

    public function test_a_wrong_number_gets_another_try_and_a_right_one_still_approves(): void
    {
        $pallet = Pallet::factory()->receivedInSap()->create();
        $view = $this->start($pallet);

        $this->assertSame(BlindCountOutcome::Retry, $this->type($view, $pallet->boxes_count - 1));
        $this->assertSame(2, app(SubmitBlindCount::class)->triesLeft($view->auditId));
        $this->assertSame(BlindCountOutcome::Retry, $this->type($view, $pallet->boxes_count + 1));
        $this->assertSame(AuditStatus::Counting, $pallet->refresh()->audit_status);
        $this->assertSame(BlindCountOutcome::Approved, $this->type($view, $pallet->boxes_count));

        $this->assertSame(AuditStatus::Approved, $pallet->refresh()->audit_status);
        $this->assertSame(0, DiscrepancyCase::count());
        // Every number typed stays as evidence, the wrong ones too.
        $this->assertSame([$pallet->boxes_count - 1, $pallet->boxes_count + 1, $pallet->boxes_count], PalletAudit::sole()->tries->pluck('counted_boxes')->all());
        $this->assertSame(2, TraceEvent::query()->where('event', TraceEventType::AuditRetry->value)->count());
    }

    public function test_three_wrong_tries_open_the_discrepancy(): void
    {
        Notification::fake();
        $pallet = Pallet::factory()->receivedInSap()->create();
        $view = $this->start($pallet);

        $this->assertSame(BlindCountOutcome::Retry, $this->type($view, 1));
        $this->assertSame(BlindCountOutcome::Retry, $this->type($view, 2));
        $this->assertSame(0, DiscrepancyCase::count());
        $this->assertSame(BlindCountOutcome::Discrepancy, $this->type($view, 3));

        $this->assertSame([1, 2, 3], DiscrepancyCase::sole()->evidence['tries']);
        $this->assertSame(AuditStatus::Discrepancy, $pallet->refresh()->audit_status);
        Notification::assertSentTo($this->admin, DiscrepancyOpened::class);
    }

    public function test_cancelling_and_starting_again_gives_no_new_tries(): void
    {
        $pallet = Pallet::factory()->receivedInSap()->create();
        $view = $this->start($pallet);
        $this->type($view, 1);
        $this->type($view, 2);
        app(CancelBlindCount::class)->handle($this->operator, $view->auditId, 'Me equivoqué de estiba', $this->ctx($this->operator));

        // Another person, or the same one, starts again: the tries of this round are already spent.
        $other = $this->userWithRole(Role::LogisticsOperator);
        $again = $this->start($pallet, $other);
        $this->assertSame(1, app(SubmitBlindCount::class)->triesLeft($again->auditId));
        $this->assertSame(BlindCountOutcome::Discrepancy, $this->type($again, 3, $other));
    }

    public function test_the_tries_limit_comes_from_the_configuration(): void
    {
        config(['traceability.count_tries' => 1]);
        $pallet = Pallet::factory()->receivedInSap()->create();

        $this->assertSame(BlindCountOutcome::Discrepancy, $this->type($this->start($pallet), 1));
    }

    public function test_a_reaudit_enabled_by_a_supervisor_has_its_own_three_tries(): void
    {
        Notification::fake();
        $supervisor = $this->userWithRole(Role::ProductionSupervisor);
        $pallet = Pallet::factory()->receivedInSap()->create();
        $this->submit($this->start($pallet), 1);

        // Reaudit is on by default: the supervisor enables it from the case.
        app(AuthorizeReaudit::class)->handle($supervisor, DiscrepancyCase::sole()->id, 'Recontar', $this->ctx($supervisor));

        $other = $this->userWithRole(Role::LogisticsOperator);
        $view = $this->start($pallet, $other);
        $this->assertSame(BlindCountOutcome::Retry, $this->type($view, 2, $other));
        $this->assertSame(BlindCountOutcome::Retry, $this->type($view, 2, $other));
        $this->assertSame(BlindCountOutcome::Approved, $this->type($view, $pallet->boxes_count, $other));

        $case = DiscrepancyCase::sole();
        $this->assertSame([DiscrepancyStatus::Resolved, $supervisor->id], [$case->status, $case->resolved_by]);
    }

    public function test_the_supervisor_enables_recounts_but_never_counts(): void
    {
        $supervisor = $this->userWithRole(Role::ProductionSupervisor);
        $pallet = Pallet::factory()->receivedInSap()->create();

        $this->assertTrue($supervisor->can('discrepancies.resolve'));
        $this->expectException(AuthorizationException::class);
        $this->start($pallet, $supervisor);
    }
}
