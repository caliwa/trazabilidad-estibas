<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\Operations\EventCounter;
use App\Domain\Pallets\Data\ResolvedMasters;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Data\ScanResult;
use App\Domain\Pallets\Enums\CloseType;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Domain\Pallets\Exceptions\DuplicateScanEvent;
use App\Domain\Pallets\Exceptions\ScanIngestionDisabled;
use App\Domain\Pallets\PalletCodeGenerator;
use App\Domain\Pallets\PalletLocks;
use App\Domain\Pallets\ScanMasterResolver;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Counts one box read by a sensor.
 *
 * Every accepted event adds exactly one box to the open pallet of its
 * order + item + lot, creating that pallet (with the standard frozen)
 * when there is none, and closing it exactly when it reaches the standard.
 *
 * Concurrency: the combination is serialized with a transaction-scoped
 * advisory lock and the open pallet is read FOR UPDATE. The partial unique
 * index on open pallets and the unique (device, event id) back both up in
 * the database. An event stored concurrently by another worker rolls the
 * whole transaction back, so it never counts twice.
 *
 * Ordering: events are assigned to the pallet open when they are
 * processed, not by their occurred_at. An event older than the start of
 * that pallet is still counted there and flagged is_late.
 *
 * Rejected events are kept. If the device sends the same event again with
 * the same content, it is evaluated again; it can be accepted then, once.
 */
class ProcessScanEvent
{
    public function __construct(
        private readonly ScanMasterResolver $masters,
        private readonly PalletCodeGenerator $codes,
        private readonly TraceLogger $trace,
    ) {}

    public function handle(Device $device, ScanEventData $data, TraceContext $ctx): ScanResult
    {
        if (! config()->boolean('traceability.decisions.scan_ingestion_enabled')) {
            throw new ScanIngestionDisabled;
        }

        return $this->process($device, $data, $ctx);
    }

    /**
     * A box read by a person with a hand reader at the production station
     * (RegisterManualBoxScan). Same counting rules; it does not depend on the
     * sensor switch (DP-01), which is about the automatic line sensor.
     */
    public function handleManual(Device $device, ScanEventData $data, TraceContext $ctx): ScanResult
    {
        return $this->process($device, $data, $ctx);
    }

    private function process(Device $device, ScanEventData $data, TraceContext $ctx): ScanResult
    {
        $existing = $this->find($device, $data->externalEventId);

        if ($existing !== null && ($existing->processing_status === ScanEventStatus::Accepted || $existing->payload_hash !== $data->payloadHash)) {
            return $this->duplicate($existing, $data, $ctx);
        }

        $now = CarbonImmutable::now();
        $resolved = $this->masters->resolve($data, $now);

        try {
            return $resolved instanceof ScanRejection
                ? DB::transaction(fn () => $this->reject($device, $data, $resolved, $existing, $now, $ctx))
                : DB::transaction(fn () => $this->accept($device, $data, $resolved, $existing, $now, $ctx));
        } catch (DuplicateScanEvent) {
            $winner = $this->find($device, $data->externalEventId);

            return $winner === null ? throw new \LogicException('Evento duplicado sin registro previo.') : $this->duplicate($winner, $data, $ctx);
        }
    }

    private function accept(Device $device, ScanEventData $data, ResolvedMasters $masters, ?PalletScanEvent $existing, CarbonImmutable $now, TraceContext $ctx): ScanResult
    {
        PalletLocks::combination($masters->order->id, $masters->item->id, $data->lotCode);

        $pallet = Pallet::query()
            ->where('production_order_id', $masters->order->id)
            ->where('item_id', $masters->item->id)
            ->where('lot_code', $data->lotCode)
            ->where('physical_status', PhysicalStatus::Stacking)
            ->lockForUpdate()
            ->first()
            ?? $this->open($masters, $data, $now, $ctx);

        $fields = [
            'pallet_id' => $pallet->id,
            'processing_status' => ScanEventStatus::Accepted->value,
            'rejection_reason' => null,
            'processed_at' => $now,
            'is_late' => $data->occurredAt->lessThan($pallet->started_at),
            'read_by_device_id' => $this->readBy($device, $ctx),
        ];

        if ($existing !== null) {
            // A rejected event sent again: accept it only if nobody else did meanwhile.
            $updated = PalletScanEvent::query()
                ->whereKey($existing->id)
                ->where('processing_status', ScanEventStatus::Rejected)
                ->update($fields);

            if ($updated === 0) {
                throw new DuplicateScanEvent;
            }
        } elseif ($this->insert($device, $data, $now, $fields) === 0) {
            throw new DuplicateScanEvent;
        }

        $pallet->boxes_count++;

        if ($pallet->boxes_count === $pallet->standard_boxes_snapshot) {
            $pallet->physical_status = PhysicalStatus::Finished;
            $pallet->close_type = CloseType::Automatic;
            $pallet->finished_at = $now;
        }

        $pallet->save();

        if ($pallet->physical_status === PhysicalStatus::Finished) {
            $this->trace->record($ctx, TraceEventType::PalletClosedAutomatically, $pallet,
                from: ['physical_status' => PhysicalStatus::Stacking->value],
                to: ['physical_status' => PhysicalStatus::Finished->value, 'close_type' => CloseType::Automatic->value, 'boxes_count' => $pallet->boxes_count],
                context: ['external_event_id' => $data->externalEventId],
            );
        }

        return new ScanResult(
            ScanOutcome::Accepted,
            palletCode: $pallet->pallet_code,
            boxesCount: $pallet->boxes_count,
            standard: $pallet->standard_boxes_snapshot,
            palletClosed: $pallet->physical_status === PhysicalStatus::Finished,
        );
    }

    private function open(ResolvedMasters $masters, ScanEventData $data, CarbonImmutable $now, TraceContext $ctx): Pallet
    {
        $pallet = new Pallet;
        $pallet->forceFill([
            'pallet_code' => $this->codes->next(),
            'qr_token' => bin2hex(random_bytes(16)),
            'production_order_id' => $masters->order->id,
            'item_id' => $masters->item->id,
            'production_order_ref' => $masters->order->ref,
            'item_code' => $masters->item->code,
            'lot_code' => $data->lotCode,
            'pallet_standard_id' => $masters->standard->id,
            'standard_boxes_snapshot' => $masters->standard->boxes_per_pallet,
            // The first box's time, but never in the future (a device clock ahead would break the date order).
            'started_at' => $data->occurredAt->min($now),
        ])->save();

        $this->trace->record($ctx, TraceEventType::PalletCreated, $pallet,
            to: [
                'pallet_code' => $pallet->pallet_code,
                'production_order_ref' => $pallet->production_order_ref,
                'item_code' => $pallet->item_code,
                'lot_code' => $pallet->lot_code,
                'standard_boxes_snapshot' => $pallet->standard_boxes_snapshot,
                'pallet_standard_version' => $masters->standard->version,
            ],
            context: ['external_event_id' => $data->externalEventId],
        );

        return $pallet;
    }

    private function reject(Device $device, ScanEventData $data, ScanRejection $reason, ?PalletScanEvent $existing, CarbonImmutable $now, TraceContext $ctx): ScanResult
    {
        // A rejected event sent again and still invalid: it is already on record.
        if ($existing === null) {
            $stored = $this->insert($device, $data, $now, [
                'pallet_id' => null,
                'processing_status' => ScanEventStatus::Rejected->value,
                'rejection_reason' => $reason->value,
                'processed_at' => null,
                'is_late' => false,
                'read_by_device_id' => $this->readBy($device, $ctx),
            ]);

            if ($stored === 0) {
                throw new DuplicateScanEvent;
            }

            $this->trace->record($ctx, TraceEventType::ScanRejected,
                result: TraceResult::Rejected,
                reason: $reason->label(),
                context: [
                    'external_event_id' => $data->externalEventId,
                    'production_order_ref' => $data->productionOrderRef,
                    'item_code' => $data->itemCode,
                    'lot_code' => $data->lotCode,
                    'rejection' => $reason->value,
                ],
            );
        }

        return new ScanResult(ScanOutcome::Rejected, rejection: $reason);
    }

    private function duplicate(PalletScanEvent $existing, ScanEventData $data, TraceContext $ctx): ScanResult
    {
        $mismatch = $existing->payload_hash !== $data->payloadHash;
        EventCounter::hit('scan_duplicates');

        if ($mismatch) {
            // Same id, different content: not counted, but someone has to look at it.
            $this->trace->record($ctx, TraceEventType::ScanDuplicateMismatch, $existing,
                result: TraceResult::Rejected,
                reason: 'El dispositivo reutilizó un identificador de evento con datos distintos.',
                context: [
                    'external_event_id' => $data->externalEventId,
                    'production_order_ref' => $data->productionOrderRef,
                    'item_code' => $data->itemCode,
                    'lot_code' => $data->lotCode,
                ],
            );
        }

        $pallet = $existing->pallet_id === null ? null : Pallet::query()->find($existing->pallet_id);

        return new ScanResult(
            ScanOutcome::Duplicate,
            palletCode: $pallet?->pallet_code,
            boxesCount: $pallet?->boxes_count,
            standard: $pallet?->standard_boxes_snapshot,
            palletClosed: $pallet !== null && $pallet->physical_status !== PhysicalStatus::Stacking,
            rejection: $existing->rejection_reason === null ? null : ScanRejection::tryFrom($existing->rejection_reason),
            payloadMismatch: $mismatch,
        );
    }

    private function find(Device $device, string $externalEventId): ?PalletScanEvent
    {
        return PalletScanEvent::query()
            ->where('device_id', $device->id)
            ->where('external_event_id', $externalEventId)
            ->first();
    }

    /**
     * The fixed reader that read a box counted under the shared box-read
     * device (RegisterManualBoxScan::fromReader); null otherwise.
     */
    private function readBy(Device $device, TraceContext $ctx): ?int
    {
        return $ctx->deviceId !== null && $ctx->deviceId !== $device->id ? $ctx->deviceId : null;
    }

    /**
     * INSERT ... ON CONFLICT DO NOTHING. Returns 0 when the event already exists.
     *
     * @param  array<string, mixed>  $outcome
     */
    private function insert(Device $device, ScanEventData $data, CarbonImmutable $now, array $outcome): int
    {
        return PalletScanEvent::query()->insertOrIgnore([
            'device_id' => $device->id,
            'external_event_id' => $data->externalEventId,
            'production_order_ref' => Str::limit($data->productionOrderRef, 50, ''),
            'item_code' => Str::limit($data->itemCode, 50, ''),
            'lot_code' => Str::limit($data->lotCode, 50, ''),
            'event_occurred_at' => $data->occurredAt,
            'received_at' => $now,
            'payload_hash' => $data->payloadHash,
            ...$outcome,
        ]);
    }
}
