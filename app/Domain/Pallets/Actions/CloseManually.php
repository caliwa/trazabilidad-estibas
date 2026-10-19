<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletLocks;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Closes an incomplete pallet by hand, keeping its real box count.
 *
 * It takes the same combination lock as ProcessScanEvent, so a scan and a
 * close for the same pallet run one after the other: if the scan goes
 * first and completes the pallet, the close is refused; if the close goes
 * first, the scan opens the next pallet.
 */
class CloseManually
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, ?string $reason, TraceContext $ctx): Pallet
    {
        Gate::forUser($user)->authorize(Permission::PalletsOperate->value);

        return DB::transaction(function () use ($user, $palletId, $reason, $ctx): Pallet {
            $identity = Pallet::query()->findOrFail($palletId, ['production_order_id', 'item_id', 'lot_code']);
            PalletLocks::combination($identity->production_order_id, $identity->item_id, $identity->lot_code);

            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);

            if ($pallet->physical_status !== PhysicalStatus::Stacking) {
                throw PalletRuleViolation::notOpen($pallet->pallet_code);
            }

            if ($pallet->boxes_count < 1 || $pallet->boxes_count >= $pallet->standard_boxes_snapshot) {
                throw PalletRuleViolation::manualCloseRange($pallet->pallet_code, $pallet->standard_boxes_snapshot);
            }

            $pallet->forceFill([
                'physical_status' => PhysicalStatus::Finished,
                'close_type' => CloseType::Manual,
                'finished_at' => now(),
                'closed_by' => $user->id,
            ])->save();

            $this->trace->record($ctx, TraceEventType::PalletClosedManually, $pallet,
                from: ['physical_status' => PhysicalStatus::Stacking->value],
                to: ['physical_status' => PhysicalStatus::Finished->value, 'close_type' => CloseType::Manual->value, 'boxes_count' => $pallet->boxes_count],
                reason: $reason === null || trim($reason) === '' ? null : trim($reason),
            );

            return $pallet;
        });
    }
}
