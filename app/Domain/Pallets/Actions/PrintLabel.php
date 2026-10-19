<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\Pallets\Enums\LabelConfirmation;
use App\Domain\Pallets\Enums\LabelPrintKind;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records a label print: the first one, or a reprint with its reason.
 * Printing never changes the pallet's identity or box count; it only
 * counts prints. With DP-03 set to "print", the first print also marks
 * the pallet as labeled.
 */
class PrintLabel
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, ?string $reason, TraceContext $ctx): PalletLabelPrint
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        return DB::transaction(function () use ($user, $palletId, $reason, $ctx): PalletLabelPrint {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);
            $isReprint = $pallet->label_print_count > 0;

            Gate::forUser($user)->authorize($isReprint ? Permission::PalletsReprint->value : Permission::PalletsLabel->value);

            if (! in_array($pallet->physical_status, [PhysicalStatus::Palletized, PhysicalStatus::Labeled], true)) {
                throw PalletRuleViolation::notPalletized($pallet->pallet_code);
            }

            if ($isReprint && $reason === null) {
                throw PalletRuleViolation::reprintNeedsReason($pallet->pallet_code);
            }

            $print = new PalletLabelPrint;
            $print->forceFill([
                'pallet_id' => $pallet->id,
                'print_number' => $pallet->label_print_count + 1,
                'kind' => $isReprint ? LabelPrintKind::Reprint : LabelPrintKind::Original,
                'reason' => $isReprint ? $reason : null,
                'printed_by' => $user->id,
                'printed_at' => now(),
            ])->save();

            $pallet->label_print_count = $print->print_number;
            $labeledNow = ! $isReprint && LabelConfirmation::configured() === LabelConfirmation::Print;

            if ($labeledNow) {
                $pallet->forceFill(['physical_status' => PhysicalStatus::Labeled, 'labeled_at' => now(), 'labeled_by' => $user->id]);
            }

            $pallet->save();

            $this->trace->record($ctx, $isReprint ? TraceEventType::LabelReprinted : TraceEventType::LabelPrinted, $pallet,
                to: ['print_number' => $print->print_number],
                reason: $reason,
            );

            if ($labeledNow) {
                $this->trace->record($ctx, TraceEventType::PalletLabeled, $pallet,
                    from: ['physical_status' => PhysicalStatus::Palletized->value],
                    to: ['physical_status' => PhysicalStatus::Labeled->value, 'confirmation' => LabelConfirmation::Print->value],
                );
            }

            return $print;
        });
    }
}
