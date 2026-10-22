<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Orders\ProductionOrderCache;
use App\Domain\Pallets\Data\ManualBoxScan;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\ScanMasterResolver;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\Device;
use App\Models\Item;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * One box label read by hand at the production station.
 *
 * The label's OF number decides everything: the order must exist (it is
 * brought from SAP if it is not here yet) and be released, the product on
 * the label must be the order's, and the lot must have box labels printed
 * by this application for that order. Then the box is counted with the
 * same rules as a sensor read (ProcessScanEvent): it goes to the open
 * pallet of that order + product + lot, which closes when it reaches the
 * standard.
 *
 * Every label has its own box code (last line of the QR). It must be one
 * this application printed for that order and lot, and it is the read's
 * event id: the database accepts a box code once, so reading the same box
 * again, a retried request or a reader firing twice never counts twice.
 * Labels printed before box codes existed are refused.
 */
class RegisterManualBoxScan
{
    public const DEVICE_CODE = 'LECTOR-MANUAL';

    public function __construct(
        private readonly ProcessScanEvent $process,
        private readonly ScanMasterResolver $masters,
        private readonly SapGateway $sap,
        private readonly ProductionOrderCache $cache,
        private readonly TraceLogger $trace,
    ) {}

    public function handle(User $user, string $scanned, TraceContext $ctx): ManualBoxScan
    {
        Gate::forUser($user)->authorize(Permission::PalletsOperate->value);

        $label = BoxLabelPayload::read($scanned) ?? throw PalletRuleViolation::notABoxLabel();
        $boxCode = $label['box'] ?? throw PalletRuleViolation::boxLabelWithoutCode();
        $order = $this->order($label['order'], $user, $ctx);
        $item = Item::query()->findOrFail($order->item_id);

        if (strcasecmp($item->code, $label['item']) !== 0) {
            throw PalletRuleViolation::boxItemMismatch($order->ref, $item->code, $label['item']);
        }

        if (! BoxLabelBatch::query()->where('production_order_id', $order->id)->where('lot_code', $label['lot'])->exists()) {
            throw PalletRuleViolation::lotWithoutLabels($label['lot'], $order->ref);
        }

        if (! BoxLabel::query()->where('code', $boxCode)->where('production_order_id', $order->id)->where('lot_code', $label['lot'])->exists()) {
            throw PalletRuleViolation::unknownBoxCode($boxCode, $order->ref);
        }

        // Already read: said plainly, without sending it to be counted (that would log a reused-id alarm).
        $previous = PalletScanEvent::query()->with('pallet:id,pallet_code')->where('device_id', $this->device()->id)->where('external_event_id', $boxCode)->first();
        if ($previous !== null) {
            throw $previous->processing_status === ScanEventStatus::Accepted && $previous->pallet !== null
                ? PalletRuleViolation::boxAlreadyRead($boxCode, $previous->pallet->pallet_code)
                : PalletRuleViolation::boxHasRejectedRead($boxCode);
        }

        $testStandard = $this->ensureStandard($item, $ctx);

        // Checked before counting so a refused box leaves no rejected read under its code.
        $rejection = $this->masters->resolve(new ScanEventData($boxCode, $order->ref, $item->code, $label['lot'], CarbonImmutable::now()), now());
        if ($rejection instanceof ScanRejection) {
            throw PalletRuleViolation::boxRejected(mb_strtolower($rejection->label()));
        }

        $result = $this->process->handleManual($this->device(), new ScanEventData($boxCode, $order->ref, $item->code, $label['lot'], CarbonImmutable::now()), $ctx);

        return match ($result->outcome) {
            ScanOutcome::Accepted => new ManualBoxScan($order->ref, $item->code, $item->description, $label['lot'], $label['expiration'], $boxCode, $result, $testStandard),
            ScanOutcome::Duplicate => throw ($result->palletCode !== null && $result->rejection === null
                ? PalletRuleViolation::boxAlreadyRead($boxCode, $result->palletCode)
                : PalletRuleViolation::boxHasRejectedRead($boxCode)),
            ScanOutcome::Rejected => throw PalletRuleViolation::boxRejected(mb_strtolower($result->rejection?->label() ?? 'regla no cumplida')),
        };
    }

    /**
     * The order on the label, brought from SAP when it is not here yet. Only a
     * released order of a finished product fills pallets.
     */
    private function order(string $ref, User $user, TraceContext $ctx): ProductionOrder
    {
        $order = ProductionOrder::query()->where('ref', $ref)->first();

        if ($order === null) {
            try {
                $sapOrder = $this->sap->findProductionOrder($ref);
            } catch (SapLookupFailed) {
                throw PalletRuleViolation::sapUnavailable($ref);
            }

            if ($sapOrder === null) {
                throw PalletRuleViolation::orderNotInSap($ref);
            }

            try {
                $order = DB::transaction(fn () => $this->cache->store($sapOrder, $user, $ctx, TraceEventType::ProductionOrderConsulted));
            } catch (BoxLabelRuleViolation $e) {
                throw new PalletRuleViolation($e->getMessage());
            }
        }

        if ($order->sap_status !== ProductionOrderStatus::Released->value || ! $order->is_open) {
            throw PalletRuleViolation::orderNotReleased($order->ref, ProductionOrderStatus::tryFrom((string) $order->sap_status)?->label() ?? 'sin consultar');
        }

        return $order;
    }

    /**
     * While the real pallet standard is not loaded (it will come from SAP), a
     * product without one gets the test standard (TRACE_TEST_PALLET_BOXES boxes
     * per pallet), marked PRUEBA so it is visible and easy to replace.
     * Returns whether the current standard is that test one.
     */
    private function ensureStandard(Item $item, TraceContext $ctx): bool
    {
        $current = PalletStandard::query()->where('item_id', $item->id)->where('status', MasterStatus::Current)->first();

        if ($current !== null) {
            return $current->sap_source_key === PalletStandard::TEST_SOURCE;
        }

        $boxes = config()->integer('traceability.test_pallet_boxes');

        if ($boxes <= 0) {
            return false;
        }

        DB::transaction(function () use ($item, $boxes, $ctx): void {
            Item::query()->whereKey($item->id)->lockForUpdate()->first();

            if (PalletStandard::query()->where('item_id', $item->id)->where('status', MasterStatus::Current)->exists()) {
                return;
            }

            $standard = new PalletStandard;
            $standard->forceFill([
                'item_id' => $item->id,
                'version' => (int) PalletStandard::query()->where('item_id', $item->id)->max('version') + 1,
                'boxes_per_pallet' => $boxes,
                'sap_source_key' => PalletStandard::TEST_SOURCE,
                'status' => MasterStatus::Current,
                'synced_at' => now(),
            ])->save();

            $this->trace->record($ctx, TraceEventType::MastersSynced, $item,
                to: ['boxes_per_pallet' => $boxes, 'version' => $standard->version],
                context: ['source' => PalletStandard::TEST_SOURCE, 'note' => 'Estándar de estiba de prueba mientras se carga el real en SAP.'],
            );
        });

        return true;
    }

    private function device(): Device
    {
        return Device::query()->where('code', self::DEVICE_CODE)->first() ?? tap(new Device, function (Device $device): void {
            $device->forceFill([
                'code' => self::DEVICE_CODE,
                'name' => 'Lectura manual en la estación de producción',
                'kind' => 'LECTOR',
                // No token: nothing authenticates as this device through the API.
                'token_hash' => hash('sha256', Str::random(64)),
                'active' => true,
            ])->save();
        });
    }
}
