<?php

namespace App\Domain\Audits\Actions;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;

/**
 * Abandons a count in progress (the operator walked away, or it expired).
 * The pallet goes back to where it was: pending, or in discrepancy when the
 * abandoned count was a reaudit.
 */
class CancelBlindCount
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(?User $user, int $auditId, string $reason, TraceContext $ctx): void
    {
        DB::transaction(function () use ($user, $auditId, $reason, $ctx): void {
            $audit = PalletAudit::query()->lockForUpdate()->find($auditId);

            if ($audit === null || $audit->status !== CountStatus::Counting || ($user !== null && $audit->operator_id !== $user->id)) {
                throw AuditRuleViolation::notYourCount();
            }

            $audit->forceFill(['status' => CountStatus::Cancelled, 'completed_at' => now()])->save();

            $pallet = Pallet::query()->lockForUpdate()->findOrFail($audit->pallet_id);
            $back = $audit->reaudit_of_id === null ? AuditStatus::Pending : AuditStatus::Discrepancy;
            $pallet->forceFill(['audit_status' => $back])->save();

            $this->trace->record($ctx, TraceEventType::AuditCancelled, $pallet,
                from: ['audit_status' => AuditStatus::Counting->value], to: ['audit_status' => $back->value],
                reason: $reason, context: ['audit_id' => $audit->id]);
        });
    }
}
