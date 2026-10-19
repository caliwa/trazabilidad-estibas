<?php

namespace App\Domain\Audits\Actions;

use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\DiscrepancyCase;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An administrator allows a second blind count by a different operator.
 * Part of DP-04: refused while the policy is not approved.
 */
class AuthorizeReaudit
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $admin, int $caseId, string $notes, TraceContext $ctx): DiscrepancyCase
    {
        Gate::forUser($admin)->authorize(Permission::DiscrepanciesResolve->value);

        if (! config()->boolean('traceability.decisions.discrepancy_reaudit_enabled')) {
            throw AuditRuleViolation::resolutionUndecided();
        }

        return DB::transaction(function () use ($admin, $caseId, $notes, $ctx): DiscrepancyCase {
            $case = DiscrepancyCase::query()->lockForUpdate()->findOrFail($caseId);

            if ($case->status !== DiscrepancyStatus::Open) {
                throw AuditRuleViolation::caseNotOpen();
            }

            if (PalletAudit::query()->where('pallet_id', $case->pallet_id)->where('operator_id', $admin->id)->exists()) {
                throw AuditRuleViolation::resolverCounted();
            }

            $case->forceFill([
                'status' => DiscrepancyStatus::InReview,
                'evidence' => [...($case->evidence ?? []), 'reaudit_authorized_by' => $admin->id, 'reaudit_authorized_at' => now()->toIso8601String(), 'reaudit_notes' => trim($notes)],
            ])->save();

            $this->trace->record($ctx, TraceEventType::DiscrepancyResolved, Pallet::query()->findOrFail($case->pallet_id),
                to: ['discrepancy_status' => DiscrepancyStatus::InReview->value], reason: 'Reauditoría autorizada: '.trim($notes),
                context: ['discrepancy_case_id' => $case->id]);

            return $case;
        });
    }
}
