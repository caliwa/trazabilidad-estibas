<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Orders\ProductionOrderCache;
use App\Domain\Pallets\Data\ManualBoxScan;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Data\ScanResult;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Models\BoxLabelBatch;
use App\Models\Device;
use App\Models\Item;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
 * Every box of a lot carries the same QR, so a read cannot be told apart
 * from the next box. The page sends a fresh id per read (a retried request
 * is not counted twice) and the same text read again within a second by
 * the same person is taken as the reader firing twice.
 */
class RegisterManualBoxScan
{
    public const DEVICE_CODE = 'LECTOR-MANUAL';

    /** Two identical reads closer than this are one read fired twice. */
    private const REPEAT_SECONDS = 1;

    public function __construct(
        private readonly ProcessScanEvent $process,
        private readonly SapGateway $sap,
        private readonly ProductionOrderCache $cache,
        private readonly TraceLogger $trace,
    ) {}

    public function handle(User $user, string $scanned, string $scanId, TraceContext $ctx): ManualBoxScan
    {
        Gate::forUser($user)->authorize(Permission::PalletsOperate->value);

        if (! Str::isUuid($scanId)) {
            throw new \InvalidArgumentException('Identificador de lectura inválido.');
        }

        $label = BoxLabelPayload::read($scanned) ?? throw PalletRuleViolation::notABoxLabel();
        $order = $this->order($label['order'], $user, $ctx);
        $item = Item::query()->findOrFail($order->item_id);

        if (strcasecmp($item->code, $label['item']) !== 0) {
            throw PalletRuleViolation::boxItemMismatch($order->ref, $item->code, $label['item']);
        }

        if (! BoxLabelBatch::query()->where('production_order_id', $order->id)->where('lot_code', $label['lot'])->exists()) {
            throw PalletRuleViolation::lotWithoutLabels($label['lot'], $order->ref);
        }

        $testStandard = $this->ensureStandard($item, $ctx);

        $scan = fn (ScanResult $result, bool $repeated = false) => new ManualBoxScan($order->ref, $item->code, $item->description, $label['lot'], $label['expiration'], $result, $testStandard, $repeated);

        if (! Cache::add('manual-scan:'.$user->id.':'.hash('sha256', trim($scanned)), true, self::REPEAT_SECONDS)) {
            return $scan(new ScanResult(ScanOutcome::Duplicate), repeated: true);
        }

        $result = $this->process->handleManual($this->device(), new ScanEventData($scanId, $order->ref, $item->code, $label['lot'], CarbonImmutable::now()), $ctx);

        if ($result->outcome === ScanOutcome::Rejected) {
            throw PalletRuleViolation::boxRejected(mb_strtolower($result->rejection?->label() ?? 'regla no cumplida'));
        }

        return $scan($result);
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
