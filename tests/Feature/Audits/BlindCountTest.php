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
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Role;
use App\Models\DiscrepancyCase;
use App\Models\Pallet;
use App\Models\PalletAudit;
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

        return app(SubmitBlindCount::class)->handle($user, $view->auditId, $counted, $this->ctx($user));
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
        $pallet = Pallet::factory()->received()->create(['standard_boxes_snapshot' => 8861]);

        $view = $this->start($pallet);

        $this->assertSame($pallet->pallet_code, $view->palletCode);
        $properties = array_map(fn ($p) => $p->getName(), (new ReflectionClass(BlindCountView::class))->getProperties());
        $this->assertSame(['auditId', 'palletCode', 'productionOrderRef', 'itemCode', 'itemDescription', 'lotCode', 'isReaudit'], $properties);
        $this->assertStringNotContainsString('8861', (string) json_encode((array) $view));
    }

    public function test_a_matching_count_approves_the_audit_and_readies_the_transfer(): void
    {
        $pallet = Pallet::factory()->received()->create();

        $outcome = $this->submit($this->start($pallet), $pallet->boxes_count);

        $this->assertSame(BlindCountOutcome::Approved, $outcome);
        $pallet->refresh();
        $this->assertSame(AuditStatus::Approved, $pallet->audit_status);
        $this->assertSame(TransferStatus::Ready, $pallet->transfer_status);
        $audit = PalletAudit::sole();
        $this->assertSame([CountStatus::Approved, $pallet->boxes_count, $this->operator->id], [$audit->status, $audit->counted_boxes, $audit->operator_id]);
        $this->assertSame(0, DiscrepancyCase::count());
    }

    public function test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter(): void
    {
        Notification::fake();
        $resolverWhoAlsoCounts = $this->userWithRole(Role::Admin);
        $pallet = Pallet::factory()->received()->create();

        $outcome = $this->submit($this->start($pallet), $pallet->boxes_count - 2);

        $this->assertSame(BlindCountOutcome::Discrepancy, $outcome);
        $pallet->refresh();
        $this->assertSame(AuditStatus::Discrepancy, $pallet->audit_status);
        $this->assertSame(TransferStatus::NotEligible, $pallet->transfer_status);
        $this->assertSame(ReceiptStatus::Confirmed, $pallet->receipt_status);
        $case = DiscrepancyCase::sole();
        $this->assertSame(DiscrepancyStatus::Open, $case->status);
        $this->assertEquals(['counted_boxes' => $pallet->boxes_count - 2, 'expected_boxes' => $pallet->boxes_count, 'attempt' => 1], $case->evidence);

        Notification::assertSentTo([$this->admin, $resolverWhoAlsoCounts], DiscrepancyOpened::class);
        Notification::assertNotSentTo($this->operator, DiscrepancyOpened::class);
    }

    public function test_a_count_belongs_to_whoever_started_it(): void
    {
        $pallet = Pallet::factory()->received()->create();
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
        $pallet = Pallet::factory()->received()->create();
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
        $pallet = Pallet::factory()->received()->create();
        $this->submit($this->start($pallet), 1);
        $other = $this->userWithRole(Role::LogisticsOperator);

        $this->expectViolation(fn () => $this->start($pallet, $other), 'discrepancia abierta');
        $this->expectViolation(fn () => app(AuthorizeReaudit::class)->handle($this->admin, DiscrepancyCase::sole()->id, 'Recontar', $this->ctx($this->admin)), 'DP-04');
    }

    public function test_an_authorized_reaudit_by_another_operator_that_matches_closes_the_case(): void
    {
        config(['traceability.decisions.discrepancy_reaudit_enabled' => true]);
        Notification::fake();
        $pallet = Pallet::factory()->received()->create();
        $this->submit($this->start($pallet), 1);
        $case = app(AuthorizeReaudit::class)->handle($this->admin, DiscrepancyCase::sole()->id, 'Recontar con otra persona', $this->ctx($this->admin));
        $this->assertSame(DiscrepancyStatus::InReview, $case->status);

        $this->expectViolation(fn () => $this->start($pallet), 'persona distinta');

        $other = $this->userWithRole(Role::LogisticsOperator);
        $view = $this->start($pallet, $other);
        $this->assertTrue($view->isReaudit);
        $this->assertSame(BlindCountOutcome::Approved, $this->submit($view, $pallet->boxes_count, $other));

        $case->refresh();
        $this->assertSame([DiscrepancyStatus::Resolved, 'REAUDITORIA_COINCIDE', $this->admin->id], [$case->status, $case->resolution_action, $case->resolved_by]);
        $this->assertSame(TransferStatus::Ready, $pallet->refresh()->transfer_status);
    }

    public function test_a_reaudit_that_differs_again_opens_a_new_case(): void
    {
        config(['traceability.decisions.discrepancy_reaudit_enabled' => true]);
        Notification::fake();
        $pallet = Pallet::factory()->received()->create();
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
        $pallet = Pallet::factory()->received()->create();
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
        $pallet = Pallet::factory()->received()->create();

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
}
