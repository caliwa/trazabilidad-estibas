<?php

namespace Tests\Concerns;

use App\Domain\Receipts\Actions\RequestProductionReceipts;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Fake\FakeSapGateway;
use App\Models\BoxConversionFactor;
use App\Models\Pallet;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait BuildsReceipts
{
    protected function sap(): FakeSapGateway
    {
        $sap = app(SapGateway::class);
        $this->assertInstanceOf(FakeSapGateway::class, $sap);

        return $sap;
    }

    /**
     * Labeled pallets of one order, with a current conversion factor.
     *
     * @param  list<string>  $lots
     * @return Collection<int, Pallet>
     */
    protected function labeledPallets(array $lots, string $unitsPerBox = '12', ?ProductionOrder $order = null): Collection
    {
        $order ??= ProductionOrder::factory()->create();

        if (! BoxConversionFactor::query()->where('item_id', $order->item_id)->exists()) {
            BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => $unitsPerBox]);
        }

        $standard = PalletStandard::factory()->create(['item_id' => $order->item_id, 'boxes_per_pallet' => 40, 'version' => PalletStandard::query()->where('item_id', $order->item_id)->max('version') + 1, 'status' => 'REEMPLAZADO']);

        return collect($lots)->map(fn (string $lot) => Pallet::factory()->labeled()->create([
            'production_order_id' => $order->id,
            'lot_code' => $lot,
            'pallet_standard_id' => $standard->id,
        ]));
    }

    /**
     * @param  iterable<Pallet>  $pallets
     * @return list<ProductionReceipt>
     */
    protected function requestReceipts(User $user, iterable $pallets, bool $onePerPallet = false): array
    {
        $ids = collect($pallets)->pluck('id')->all();

        return app(RequestProductionReceipts::class)->handle($user, $ids, $onePerPallet, TraceContext::forUser($user, Origin::Web, (string) Str::uuid()));
    }
}
