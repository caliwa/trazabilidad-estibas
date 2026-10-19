<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Data\ScanResult;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Models\Device;
use App\Models\PalletScanEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Evaluates a stored rejected event again, exactly as if its device had
 * resent it, after someone fixed the master that caused the rejection.
 * ProcessScanEvent decides; this only rebuilds the original event.
 */
class ReprocessRejectedEvent
{
    public function __construct(private readonly ProcessScanEvent $process) {}

    public function handle(User $user, int $eventId, TraceContext $ctx): ScanResult
    {
        Gate::forUser($user)->authorize(Permission::ScansReprocess->value);

        $event = PalletScanEvent::query()->findOrFail($eventId);

        if ($event->processing_status !== ScanEventStatus::Rejected) {
            throw PalletRuleViolation::eventNotRejected();
        }

        $device = Device::query()->findOrFail($event->device_id);

        return $this->process->handle($device, new ScanEventData(
            externalEventId: $event->external_event_id,
            productionOrderRef: $event->production_order_ref,
            itemCode: $event->item_code,
            lotCode: $event->lot_code,
            occurredAt: CarbonImmutable::instance($event->event_occurred_at),
        ), $ctx);
    }
}
