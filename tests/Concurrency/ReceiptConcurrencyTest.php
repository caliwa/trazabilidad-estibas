<?php

namespace Tests\Concurrency;

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabelBatch;
use App\Models\Pallet;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionReceiptPallet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Intentos concurrentes de recibir la misma estiba: many processes ask for
 * a receipt of the same pallets at the same moment; exactly one wins.
 */
class ReceiptConcurrencyTest extends ConcurrencyTestCase
{
    public function test_parallel_receipt_requests_for_the_same_pallets_hold_each_pallet_once(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $order = ProductionOrder::factory()->create();
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id]);
        $standard = PalletStandard::factory()->create(['item_id' => $order->item_id]);
        foreach (['L-1', 'L-2', 'L-3'] as $lot) {
            BoxLabelBatch::factory()->create(['production_order_id' => $order->id, 'lot_code' => $lot]);
        }
        $pallets = collect(['L-1', 'L-2', 'L-3'])->map(fn (string $lot) => Pallet::factory()->labeled()->create([
            'production_order_id' => $order->id, 'lot_code' => $lot, 'pallet_standard_id' => $standard->id,
        ]));
        $user = User::factory()->create()->assignRole(Role::TraceabilityAdmin->value);

        // Overlapping selections in different orders, so the lock order matters too.
        $selections = [[0, 1, 2], [2, 1, 0], [1, 2], [0], [2, 0], [0, 1, 2]];
        $summaries = $this->runInParallel(array_map(fn (array $s) => [
            'mode' => 'receipt', 'user_id' => $user->id, 'pallet_ids' => array_map(fn (int $i) => $pallets[$i]->id, $s),
        ], $selections));

        $accepted = array_sum(array_column($summaries, 'accepted'));
        $this->assertGreaterThanOrEqual(1, $accepted);
        $this->assertSame(count($selections), $accepted + array_sum(array_column($summaries, 'rejected')));

        // A pallet may end up in no receipt (if [2, 0] wins first, every other selection
        // overlaps it and is refused), but never in two.
        foreach ($pallets as $pallet) {
            $holds = ProductionReceiptPallet::query()->where('pallet_id', $pallet->id)->whereIn('hold_status', [PalletHold::Active, PalletHold::Confirmed])->count();

            $this->assertLessThanOrEqual(1, $holds, "La estiba {$pallet->lot_code} quedó en dos recibos");
            $this->assertSame($holds === 1 ? ReceiptStatus::Confirmed : ReceiptStatus::None, $pallet->refresh()->receipt_status);
        }

        $this->assertSame($accepted, ProductionReceipt::query()->count());
        $this->assertSame(
            ProductionReceiptPallet::query()->count(),
            ProductionReceiptPallet::query()->distinct('pallet_id')->count('pallet_id'),
        );
    }
}
