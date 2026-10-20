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
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Permission;
use App\Jobs\StartTransferAfterAudit;
use App\Models\DiscrepancyCase;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\User;
use App\Notifications\DiscrepancyOpened;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Compares the counted boxes with the system quantity, on the server.
 *
 * Match: audit approved, pallet ready to transfer. Difference: the count
 * is kept as evidence, a discrepancy case opens, the transfer stays
 * blocked and the people who resolve discrepancies are notified. Nothing
 * is adjusted. The caller gets only the outcome, never a number.
 */
class SubmitBlindCount
{
    public const MAX_BOXES = 99_999;

    public function __construct(private readonly TraceLogger $trace) {}

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
                    'evidence' => ['counted_boxes' => $counted, 'expected_boxes' => $expected, 'attempt' => $audit->attempt],
                ])->save();
            }

            // The trace is for administrators and may hold the numbers; the operator never reads it.
            $this->trace->record($ctx, $matches ? TraceEventType::AuditApproved : TraceEventType::AuditDiscrepancy, $pallet,
                from: ['audit_status' => AuditStatus::Counting->value],
                to: ['audit_status' => $matches ? AuditStatus::Approved->value : AuditStatus::Discrepancy->value],
                result: $matches ? TraceResult::Ok : TraceResult::Rejected,
                context: ['audit_id' => $audit->id, 'attempt' => $audit->attempt, 'counted_boxes' => $counted, 'expected_boxes' => $expected]);

            return [$matches ? BlindCountOutcome::Approved : BlindCountOutcome::Discrepancy, $case];
        });

        if ($case instanceof DiscrepancyCase) {
            $this->notify($case, $user);
        }

        if ($outcome === BlindCountOutcome::Approved && config()->boolean('traceability.decisions.auto_transfer_after_audit')) {
            $palletId = (int) PalletAudit::query()->whereKey($auditId)->value('pallet_id');
            StartTransferAfterAudit::dispatch($palletId, $user->id, $ctx->correlationId)->afterCommit();
        }

        return $outcome;
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
