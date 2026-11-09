<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\BoxLabels\BoxQr;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletLocks;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\BoxLabel;
use App\Models\BoxVoid;
use App\Models\BoxVoidReason;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Voids a box already counted (damaged, misread). The read stays on record
 * and a BoxVoid says it no longer counts; the box cannot be counted again.
 *
 * Only while its pallet is not palletized. The pallet loses one box:
 * - open: it just has one box less;
 * - closed automatically (it was full): it opens again, unless the line
 *   already opened another pallet for the same order + product + lot; then
 *   it stays closed as incomplete, closed by the person who voided the box;
 * - closed by hand: it stays closed with one box less; if that leaves it
 *   empty it opens again, or the void is refused when another one is open.
 *
 * Same combination lock as ProcessScanEvent and CloseManually, so a void,
 * a read and a close of the same pallet run one after the other.
 */
class VoidBox
{
    public function __construct(private readonly TraceLogger $trace) {}

    /**
     * The counted read of a box QR, with its pallet, before asking for the reason.
     */
    public function preview(User $user, string $scanned): PalletScanEvent
    {
        Gate::forUser($user)->authorize(Permission::BoxesVoid->value);

        $event = $this->countedRead($scanned);
        $pallet = $event->pallet;

        if ($pallet === null || $pallet->palletized_at !== null) {
            throw PalletRuleViolation::boxVoidTooLate($event->external_event_id, (string) $pallet?->pallet_code);
        }

        return $event;
    }

    public function handle(User $user, string $scanned, int $reasonId, ?string $note, TraceContext $ctx): BoxVoid
    {
        Gate::forUser($user)->authorize(Permission::BoxesVoid->value);

        $note = $note === null || trim($note) === '' ? null : trim($note);

        return DB::transaction(function () use ($user, $scanned, $reasonId, $note, $ctx): BoxVoid {
            $reason = BoxVoidReason::query()->active()->find($reasonId) ?? throw PalletRuleViolation::voidReasonUnavailable();
            $event = $this->countedRead($scanned);
            $boxCode = $event->external_event_id;

            $identity = Pallet::query()->findOrFail($event->pallet_id, ['production_order_id', 'item_id', 'lot_code']);
            PalletLocks::combination($identity->production_order_id, $identity->item_id, $identity->lot_code);

            $pallet = Pallet::query()->lockForUpdate()->findOrFail($event->pallet_id);
            // Read again under the lock: another void of the same box may have just won.
            if (BoxVoid::query()->where('pallet_scan_event_id', $event->id)->exists()) {
                throw PalletRuleViolation::boxAlreadyVoided($boxCode);
            }
            if ($pallet->palletized_at !== null || ! in_array($pallet->physical_status, [PhysicalStatus::Stacking, PhysicalStatus::Finished], true)) {
                throw PalletRuleViolation::boxVoidTooLate($boxCode, $pallet->pallet_code);
            }

            $before = ['boxes' => $pallet->boxes_count, 'physical_status' => $pallet->physical_status->value, 'close_type' => $pallet->close_type?->value];
            $open = $pallet->physical_status === PhysicalStatus::Finished ? $this->otherOpen($pallet) : null;
            $boxes = $pallet->boxes_count - 1;

            // Full and closed by the line: it opens again. Closed by hand: only if it is left empty.
            $reopen = $open === null && match ($pallet->close_type) {
                CloseType::Automatic => true,
                CloseType::Manual => $boxes === 0,
                null => false,
            };

            if ($open !== null && $boxes === 0) {
                throw PalletRuleViolation::boxVoidWouldEmptyClosed($boxCode, $pallet->pallet_code, $open->pallet_code);
            }

            $after = match (true) {
                $reopen => ['boxes_count' => $boxes, 'physical_status' => PhysicalStatus::Stacking, 'close_type' => null, 'finished_at' => null, 'closed_by' => null, 'close_reason_id' => null, 'close_note' => null],
                // It was full; with one box less it is an incomplete pallet, closed by whoever voided the box.
                $pallet->close_type === CloseType::Automatic => ['boxes_count' => $boxes, 'close_type' => CloseType::Manual, 'closed_by' => $user->id],
                default => ['boxes_count' => $boxes],
            };

            $void = new BoxVoid;
            $void->forceFill([
                'pallet_scan_event_id' => $event->id,
                'pallet_id' => $pallet->id,
                'box_code' => $boxCode,
                'box_void_reason_id' => $reason->id,
                'note' => $note,
                'voided_by' => $user->id,
                'voided_at' => now(),
                'pallet_before' => $before,
                'pallet_after' => [],
            ]);

            // The void row goes first: the pallet guards only let the count go down inside its transaction.
            $pallet->forceFill($after);
            $void->pallet_after = ['boxes' => $boxes, 'physical_status' => $pallet->physical_status->value, 'close_type' => $pallet->close_type?->value];
            $void->save();
            $pallet->save();

            $this->trace->record($ctx, TraceEventType::BoxVoided, $pallet,
                from: ['boxes_count' => $before['boxes'], 'physical_status' => $before['physical_status'], 'close_type' => $before['close_type']],
                to: ['boxes_count' => $boxes, 'physical_status' => $pallet->physical_status->value, 'close_type' => $pallet->close_type?->value],
                reason: $note === null ? "Caja {$boxCode}: {$reason->name}" : "Caja {$boxCode}: {$reason->name}: {$note}",
                context: ['box_code' => $boxCode, 'pallet_scan_event_id' => $event->id, 'box_void_reason_id' => $reason->id, 'box_void_reason' => $reason->name, 'note' => $note, 'other_open_pallet' => $open?->pallet_code],
            );

            if ($reopen) {
                $this->trace->record($ctx, TraceEventType::PalletReopened, $pallet,
                    from: ['physical_status' => PhysicalStatus::Finished->value, 'close_type' => $before['close_type']],
                    to: ['physical_status' => PhysicalStatus::Stacking->value, 'boxes_count' => $boxes],
                    reason: "Se anuló la caja {$boxCode}.",
                    context: ['box_code' => $boxCode],
                );
            }

            return $void;
        });
    }

    /**
     * What voiding one box would do to this pallet, said for the operator.
     */
    public function consequence(Pallet $pallet): string
    {
        $boxes = $pallet->boxes_count - 1;
        $left = $boxes === 1 ? '1 caja' : "{$boxes} cajas";

        if ($pallet->physical_status === PhysicalStatus::Stacking) {
            return "{$pallet->pallet_code} sigue en estibado y queda con {$left}.";
        }

        $open = $this->otherOpen($pallet);

        return match (true) {
            $open !== null && $boxes === 0 => "No se puede: {$pallet->pallet_code} quedaría cerrada y sin cajas, y {$open->pallet_code} está abierta para la misma orden y lote.",
            $pallet->close_type === CloseType::Automatic && $open === null => "{$pallet->pallet_code} se reabre con {$left}: la siguiente caja de esta orden y lote vuelve a ella.",
            $pallet->close_type === CloseType::Automatic => "{$pallet->pallet_code} no se puede reabrir porque {$open->pallet_code} ya está abierta: queda cerrada como incompleta con {$left}.",
            $boxes === 0 => "{$pallet->pallet_code} queda sin cajas y se reabre.",
            default => "{$pallet->pallet_code} sigue cerrada (se cerró a mano) y queda con {$left}.",
        };
    }

    private function countedRead(string $scanned): PalletScanEvent
    {
        // The QR read, or its token typed by hand when the label is too damaged to read.
        $token = BoxQr::tokenFrom($scanned) ?? BoxQr::typedToken($scanned)
            ?? throw (PalletQr::tokenFrom($scanned) !== null ? PalletRuleViolation::palletQrInBoxField() : PalletRuleViolation::notABoxLabel());
        $code = BoxLabel::query()->where('qr_token', $token)->value('code') ?? throw PalletRuleViolation::unknownBoxQr();

        // Every box read, by hand or by a fixed reader, is counted under the shared box-read device.
        $event = PalletScanEvent::query()
            ->with('pallet')
            ->where('device_id', Device::query()->where('code', RegisterManualBoxScan::DEVICE_CODE)->value('id'))
            ->where('external_event_id', $code)
            ->where('processing_status', ScanEventStatus::Accepted)
            ->first() ?? throw PalletRuleViolation::boxNotCounted((string) $code);

        if (BoxVoid::query()->where('pallet_scan_event_id', $event->id)->exists()) {
            throw PalletRuleViolation::boxAlreadyVoided((string) $code);
        }

        return $event;
    }

    private function otherOpen(Pallet $pallet): ?Pallet
    {
        return Pallet::query()
            ->where('production_order_id', $pallet->production_order_id)
            ->where('item_id', $pallet->item_id)
            ->where('lot_code', $pallet->lot_code)
            ->where('physical_status', PhysicalStatus::Stacking)
            ->whereKeyNot($pallet->id)
            ->first(['id', 'pallet_code']);
    }
}
