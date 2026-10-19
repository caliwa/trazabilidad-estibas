<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\Pallets\Enums\LabelConfirmation;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The printed label is on the pallet. Refused until DP-03 picks a mode;
 * with "scan", the scanned QR must be this pallet's.
 */
class ConfirmLabeled
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, ?string $scannedQr, TraceContext $ctx): Pallet
    {
        Gate::forUser($user)->authorize(Permission::PalletsLabel->value);

        $mode = LabelConfirmation::configured() ?? throw PalletRuleViolation::labelConfirmationUndecided();

        if ($mode === LabelConfirmation::Print) {
            throw PalletRuleViolation::labelConfirmationIsAutomatic();
        }

        return DB::transaction(function () use ($user, $palletId, $scannedQr, $mode, $ctx): Pallet {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);

            match ($pallet->physical_status) {
                PhysicalStatus::Labeled => throw PalletRuleViolation::alreadyLabeled($pallet->pallet_code),
                PhysicalStatus::Stacking, PhysicalStatus::Finished => throw PalletRuleViolation::notPalletized($pallet->pallet_code),
                PhysicalStatus::Palletized => null,
            };

            if ($pallet->label_print_count === 0) {
                throw PalletRuleViolation::labelNotPrinted($pallet->pallet_code);
            }

            if ($mode === LabelConfirmation::Scan && ($scannedQr === null || ! hash_equals($pallet->qr_token, (string) PalletQr::tokenFrom($scannedQr)))) {
                throw PalletRuleViolation::qrMismatch();
            }

            $pallet->forceFill(['physical_status' => PhysicalStatus::Labeled, 'labeled_at' => now(), 'labeled_by' => $user->id])->save();

            $this->trace->record($ctx, TraceEventType::PalletLabeled, $pallet,
                from: ['physical_status' => PhysicalStatus::Palletized->value],
                to: ['physical_status' => PhysicalStatus::Labeled->value, 'confirmation' => $mode->value],
            );

            return $pallet;
        });
    }
}
