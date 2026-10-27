<?php

namespace App\Domain\Quality\Actions;

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
 * Quality checked a held pallet and it is fine: it goes on to the blind
 * count. Nothing moves in SAP now; the pallet stays in the non-conforming
 * warehouse until the approved count transfers it from there to 05.
 */
class ReleasePallet
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, string $notes, TraceContext $ctx): QualityHold
    {
        Gate::forUser($user)->authorize(Permission::QualityManage->value);

        return DB::transaction(function () use ($user, $palletId, $notes, $ctx): QualityHold {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);

            if ($pallet->quality_status !== QualityStatus::NonConforming) {
                throw QualityRuleViolation::notHeld($pallet->pallet_code, $pallet->quality_status->label());
            }

            $hold = QualityHold::query()->where('pallet_id', $pallet->id)->where('status', QualityHoldStatus::NonConforming)->lockForUpdate()->firstOrFail();
            $hold->forceFill([
                'status' => QualityHoldStatus::Released,
                'released_by' => $user->id,
                'released_at' => now(),
                'release_notes' => trim($notes),
            ])->save();

            $pallet->forceFill(['quality_status' => QualityStatus::Released])->save();

            $this->trace->record($ctx, TraceEventType::QualityReleased, $pallet,
                from: ['quality_status' => QualityStatus::NonConforming->value], to: ['quality_status' => QualityStatus::Released->value],
                reason: trim($notes), context: ['quality_hold_id' => $hold->id, 'warehouse' => config()->string('sap.warehouses.non_conforming')]);

            return $hold;
        });
    }
}
