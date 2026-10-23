<?php

namespace App\Domain\Audits\Actions;

use App\Domain\Audits\Data\BlindCountOutcome;
use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Domain\Transfers\Actions\RequestInventoryTransfers;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Permission;
use App\Models\DiscrepancyCase;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\PalletAuditTry;
use App\Models\User;
use App\Notifications\DiscrepancyOpened;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Compares the counted boxes with the system quantity, on the server.
 *
 * Every number typed is kept as a try. Match: audit approved and, in the
 * same transaction, the stock transfer 04 → 05 is requested; it is sent to
 * SAP after the commit. Difference with tries left (traceability.count_tries
 * per pallet and round): the count stays open for another try. Difference
 * on the last try: the count is kept as evidence, a discrepancy case opens,
 * nothing moves and the people who resolve discrepancies are notified.
 * Nothing is adjusted. The caller gets only the outcome, never a number.
 */
class SubmitBlindCount
{
    public const MAX_BOXES = 99_999;

    public function __construct(private readonly TraceLogger $trace, private readonly RequestInventoryTransfers $transfers) {}

    public function handle(User $user, int $auditId, int $counted, TraceContext $ctx): BlindCountOutcome
    {
        Gate::forUser($user)->authorize(Permission::AuditsCount->value);

        if ($counted < 0 || $counted > self::MAX_BOXES) {
            throw new \InvalidArgumentException('Cantidad fuera de rango.');
        }

        [$outcome, $case] = DB::transaction(function () use ($user, $auditId, $counted, $ctx): array {
            $audit = PalletAudit::query()->lockForUpdate()->find($auditId);

            if ($audit === null || $audit->operator_id !== $user->id || $audit->status !== CountStatus::Counting) {
                throw AuditRuleViolation::notYourCount();
            }

            $pallet = Pallet::query()->lockForUpdate()->findOrFail($audit->pallet_id);
            $expected = $pallet->boxes_count;
            $matches = $counted === $expected;

            $try = new PalletAuditTry;
            $try->forceFill([
                'pallet_audit_id' => $audit->id,
                'try_number' => (int) $audit->tries()->max('try_number') + 1,
                'counted_boxes' => $counted,
                'matches' => $matches,
                'created_at' => now(),
            ])->save();

            if (! $matches && $this->triesUsed($audit) < config()->integer('traceability.count_tries')) {
                // Not yet a discrepancy: the operator counts again. Nothing about the number is said.
                $this->trace->record($ctx, TraceEventType::AuditRetry, $pallet, result: TraceResult::Rejected,
                    context: ['audit_id' => $audit->id, 'attempt' => $audit->attempt, 'try' => $try->try_number, 'counted_boxes' => $counted, 'expected_boxes' => $expected]);

                return [BlindCountOutcome::Retry, null];
            }

            $audit->forceFill([
                'status' => $matches ? CountStatus::Approved : CountStatus::Discrepancy,
                'counted_boxes' => $counted,
                'expected_boxes' => $expected,
                'completed_at' => now(),
            ])->save();

            $case = null;

            if ($matches) {
                $pallet->forceFill(['audit_status' => AuditStatus::Approved, 'transfer_status' => TransferStatus::Ready])->save();
                $this->closeReauditedCase($audit, 'REAUDITORIA_COINCIDE', 'La reauditoría coincidió con la cantidad del sistema.');
            } else {
                $pallet->forceFill(['audit_status' => AuditStatus::Discrepancy])->save();
                $this->closeReauditedCase($audit, 'REAUDITORIA_DIFIERE', 'La reauditoría también encontró diferencia; se abrió una discrepancia nueva.');

                $case = new DiscrepancyCase;
                $case->forceFill([
                    'pallet_id' => $pallet->id,
                    'pallet_audit_id' => $audit->id,
                    'status' => DiscrepancyStatus::Open,
                    'opened_at' => now(),
                    'evidence' => ['counted_boxes' => $counted, 'expected_boxes' => $expected, 'attempt' => $audit->attempt, 'tries' => $audit->tries()->pluck('counted_boxes')->all()],
                ])->save();
            }

            // The trace is for administrators and may hold the numbers; the operator never reads it.
            $this->trace->record($ctx, $matches ? TraceEventType::AuditApproved : TraceEventType::AuditDiscrepancy, $pallet,
                from: ['audit_status' => AuditStatus::Counting->value],
                to: ['audit_status' => $matches ? AuditStatus::Approved->value : AuditStatus::Discrepancy->value],
                result: $matches ? TraceResult::Ok : TraceResult::Rejected,
                context: ['audit_id' => $audit->id, 'attempt' => $audit->attempt, 'try' => $try->try_number, 'counted_boxes' => $counted, 'expected_boxes' => $expected]);

            if ($matches) {
                // Same transaction: if the transfer request cannot be stored, the count is not approved either.
                $this->transfers->handle($user, [$pallet->id], $ctx, automatic: true);
            }

            return [$matches ? BlindCountOutcome::Approved : BlindCountOutcome::Discrepancy, $case];
        });

        if ($case instanceof DiscrepancyCase) {
            $this->notify($case, $user);
        }

        return $outcome;
    }

    /**
     * Tries left for this count: the limit minus the tries of every count of
     * the same pallet in the same round, so cancelling and starting again
     * does not give new ones.
     */
    public function triesLeft(int $auditId): int
    {
        $audit = PalletAudit::query()->findOrFail($auditId);

        return max(0, config()->integer('traceability.count_tries') - $this->triesUsed($audit));
    }

    private function triesUsed(PalletAudit $audit): int
    {
        return PalletAuditTry::query()->whereHas('audit', fn ($q) => $q
            ->where('pallet_id', $audit->pallet_id)
            ->when($audit->reaudit_of_id === null, fn ($q) => $q->whereNull('reaudit_of_id'), fn ($q) => $q->where('reaudit_of_id', $audit->reaudit_of_id)),
        )->count();
    }

    /**
     * When this count was a reaudit, the case that asked for it is closed.
     */
    private function closeReauditedCase(PalletAudit $audit, string $action, string $notes): void
    {
        if ($audit->reaudit_of_id === null) {
            return;
        }

        $case = DiscrepancyCase::query()->where('pallet_audit_id', $audit->reaudit_of_id)->where('status', DiscrepancyStatus::InReview)->lockForUpdate()->first();
        $authorizer = $case === null ? null : ($case->evidence['reaudit_authorized_by'] ?? null);

        $case?->forceFill([
            'status' => DiscrepancyStatus::Resolved,
            'resolution_action' => $action,
            'resolution_notes' => $notes,
            'resolved_by' => $authorizer,
            'resolved_at' => now(),
            'reaudit_id' => $audit->id,
        ])->save();
    }

    private function notify(DiscrepancyCase $case, User $counter): void
    {
        $recipients = User::permission('discrepancies.resolve')->active()->whereKeyNot($counter->id)->get();

        Notification::send($recipients, new DiscrepancyOpened($case->id));
    }
}
