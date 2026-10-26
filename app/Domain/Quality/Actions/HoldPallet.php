<?php

namespace App\Domain\Quality\Actions;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Quality\Enums\QualityHoldStatus;
use App\Domain\Quality\Enums\QualityStatus;
use App\Domain\Quality\Exceptions\QualityRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\QualityHold;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Quality holds a labeled pallet before the blind count. While held it is
 * neither counted nor transferred to 05.
 */
class HoldPallet
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, string $reason, TraceContext $ctx): QualityHold
    {
        Gate::forUser($user)->authorize(Permission::QualityManage->value);

        return DB::transaction(function () use ($user, $palletId, $reason, $ctx): QualityHold {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);

            if ($pallet->physical_status !== PhysicalStatus::Labeled) {
                throw QualityRuleViolation::notLabeled($pallet->pallet_code);
            }

            match ($pallet->audit_status) {
                AuditStatus::Counting => throw QualityRuleViolation::beingCounted($pallet->pallet_code),
                AuditStatus::Approved => throw QualityRuleViolation::alreadyCounted($pallet->pallet_code),
                AuditStatus::Pending, AuditStatus::Discrepancy => null,
            };

            if (! in_array($pallet->quality_status, [QualityStatus::None, QualityStatus::Released], true)) {
                throw QualityRuleViolation::notHoldable($pallet->pallet_code, $pallet->quality_status->label());
            }

            $hold = new QualityHold;
            $hold->forceFill([
                'pallet_id' => $pallet->id,
                'held_by' => $user->id,
                'held_at' => now(),
                'reason' => trim($reason),
                'status' => QualityHoldStatus::Open,
            ])->save();

            $from = $pallet->quality_status;
            $pallet->forceFill(['quality_status' => QualityStatus::Held])->save();

            $this->trace->record($ctx, TraceEventType::QualityHeld, $pallet,
                from: ['quality_status' => $from->value], to: ['quality_status' => QualityStatus::Held->value],
                reason: trim($reason), context: ['quality_hold_id' => $hold->id]);

            return $hold;
        });
    }
}
