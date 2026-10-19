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
 * Closes a discrepancy with one of the actions approved in DP-04, with
 * notes and evidence. It never touches quantities or SAP: if inventory has
 * to be adjusted, that happens in SAP by whoever owns it, and the document
 * reference goes in the evidence. The pallet stays blocked for transfer;
 * only a matching recount unblocks it.
 */
class ResolveDiscrepancy
{
    public function __construct(private readonly TraceLogger $trace) {}

    /**
     * @param  array<string, string>  $evidence
     */
    public function handle(User $admin, int $caseId, string $action, string $notes, array $evidence, TraceContext $ctx): DiscrepancyCase
    {
        Gate::forUser($admin)->authorize(Permission::DiscrepanciesResolve->value);

        $allowed = config()->array('traceability.decisions.discrepancy_close_actions');

        if ($allowed === []) {
            throw AuditRuleViolation::resolutionUndecided();
        }

        if (! in_array($action, $allowed, true)) {
            throw AuditRuleViolation::actionNotAllowed($action);
        }

        return DB::transaction(function () use ($admin, $caseId, $action, $notes, $evidence, $ctx): DiscrepancyCase {
            $case = DiscrepancyCase::query()->lockForUpdate()->findOrFail($caseId);

            if ($case->status === DiscrepancyStatus::Resolved) {
                throw AuditRuleViolation::caseClosed();
            }

            if (PalletAudit::query()->where('pallet_id', $case->pallet_id)->where('operator_id', $admin->id)->exists()) {
                throw AuditRuleViolation::resolverCounted();
            }

            $case->forceFill([
                'status' => DiscrepancyStatus::Resolved,
                'resolution_action' => $action,
                'resolution_notes' => trim($notes),
                'evidence' => [...($case->evidence ?? []), 'resolution' => $evidence],
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ])->save();

            $this->trace->record($ctx, TraceEventType::DiscrepancyResolved, Pallet::query()->findOrFail($case->pallet_id),
                to: ['discrepancy_status' => DiscrepancyStatus::Resolved->value, 'action' => $action], reason: trim($notes),
                context: ['discrepancy_case_id' => $case->id, 'evidence' => $evidence]);

            return $case;
        });
    }
}
