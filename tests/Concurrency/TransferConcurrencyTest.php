<?php

namespace Tests\Concurrency;

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Transfers\Enums\TransferMode;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Role;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Concerns\BuildsTransfers;

/**
 * Intentos concurrentes de trasladar la misma estiba: individual and grouped
 * requests race for the same audited pallets; each pallet ends up in at most
 * one transfer.
 */
class TransferConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsTransfers;

    public function test_parallel_transfer_requests_for_the_same_pallets_hold_each_pallet_once(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $pallets = $this->auditedPallets(['L-1', 'L-1', 'L-1']);
        $user = User::factory()->create()->assignRole(Role::TraceabilityAdmin->value);

        $jobs = [];
        foreach ([[0, 1, 2], [2, 1, 0], [1, 2], [0], [2, 0], [0, 1, 2]] as $i => $selection) {
            $jobs[] = [
                'mode' => 'transfer', 'user_id' => $user->id,
                'transfer_mode' => ($i % 2 === 0 ? TransferMode::Grouped : TransferMode::Individual)->value,
                'pallet_ids' => array_map(fn (int $p) => $pallets[$p]->id, $selection),
            ];
        }

        $summaries = $this->runInParallel($jobs);

        $accepted = $this->total($summaries, 'accepted');
        $this->assertGreaterThanOrEqual(1, $accepted);
        $this->assertSame(count($jobs), $accepted + $this->total($summaries, 'rejected'));

        foreach ($pallets as $pallet) {
            $holds = InventoryTransferPallet::query()->where('pallet_id', $pallet->id)->whereIn('hold_status', [PalletHold::Active, PalletHold::Confirmed])->count();

            $this->assertLessThanOrEqual(1, $holds, "La estiba {$pallet->pallet_code} quedó en dos traslados");
            $this->assertSame($holds === 1 ? TransferStatus::Confirmed : TransferStatus::Ready, $pallet->refresh()->transfer_status);
        }

        $this->assertSame(
            InventoryTransferPallet::query()->count(),
            InventoryTransferPallet::query()->distinct('pallet_id')->count('pallet_id'),
        );
        $this->assertSame(0, InventoryTransfer::query()->where('status', '!=', 'CONFIRMADO')->count());
    }
}
