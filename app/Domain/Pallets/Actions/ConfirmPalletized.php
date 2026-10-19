<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The finished pallet was wrapped and secured on the floor.
 */
class ConfirmPalletized
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, TraceContext $ctx): Pallet
    {
        Gate::forUser($user)->authorize(Permission::PalletsPalletize->value);

        return DB::transaction(function () use ($user, $palletId, $ctx): Pallet {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);

            match ($pallet->physical_status) {
                PhysicalStatus::Stacking => throw PalletRuleViolation::notFinished($pallet->pallet_code),
                PhysicalStatus::Palletized, PhysicalStatus::Labeled => throw PalletRuleViolation::alreadyPalletized($pallet->pallet_code),
                PhysicalStatus::Finished => null,
            };

            $pallet->forceFill(['physical_status' => PhysicalStatus::Palletized, 'palletized_at' => now(), 'palletized_by' => $user->id])->save();

            $this->trace->record($ctx, TraceEventType::PalletPalletized, $pallet,
                from: ['physical_status' => PhysicalStatus::Finished->value],
                to: ['physical_status' => PhysicalStatus::Palletized->value],
            );

            return $pallet;
        });
    }
}
