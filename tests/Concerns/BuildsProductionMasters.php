<?php

namespace Tests\Concerns;

use App\Domain\Pallets\Actions\ProcessScanEvent;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Data\ScanResult;
use App\Domain\Shared\TraceContext;
use App\Models\Device;
use App\Models\Item;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait BuildsProductionMasters
{
    protected function enableScanIngestion(): void
    {
        config(['traceability.decisions.scan_ingestion_enabled' => true]);
    }

    /**
     * An open order for a new item with a current standard.
     */
    protected function orderWithStandard(int $boxes = 4, string $ref = 'OP-1001', string $itemCode = 'PT-100'): ProductionOrder
    {
        $item = Item::factory()->create(['code' => $itemCode]);
        PalletStandard::factory()->for($item)->boxes($boxes)->create();

        return ProductionOrder::factory()->for($item)->create(['ref' => $ref]);
    }

    protected function scan(ProductionOrder $order, string $lot = 'L-1', ?string $eventId = null, ?CarbonImmutable $at = null, ?string $itemCode = null): ScanEventData
    {
        return new ScanEventData(
            externalEventId: $eventId ?? (string) Str::uuid(),
            productionOrderRef: $order->ref,
            itemCode: $itemCode ?? $order->item->code,
            lotCode: $lot,
            occurredAt: $at ?? CarbonImmutable::now(),
        );
    }

    protected function process(Device $device, ScanEventData $data): ScanResult
    {
        return app(ProcessScanEvent::class)->handle($device, $data, TraceContext::forDevice($device, (string) Str::uuid()));
    }
}
