<?php

namespace Tests\Concurrency;

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Role;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Models\InventoryTransferPallet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Concerns\BuildsTransfers;

/**
 * Intentos concurrentes de trasladar la misma estiba. The count requests the
 * transfer once; what can race is the manual retry after SAP rejected it.
 * Several people retry the same pallets at once: each pallet ends up in at
 * most one active or confirmed transfer.
 */
class TransferConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsTransfers;

    public function test_parallel_retries_for_the_same_pallets_hold_each_pallet_once(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->sap()->queueTransferScenarios(FakeScenario::Reject, FakeScenario::Reject, FakeScenario::Reject);
        $pallets = $this->auditedPallets(['L-1', 'L-1', 'L-1']);
        $this->assertTrue($pallets->every(fn ($p) => $p->transfer_status === TransferStatus::Failed));
        $user = User::factory()->create()->assignRole(Role::TraceabilityAdmin->value);

        // Overlapping selections in different orders, so the lock order matters too.
        $selections = [[0, 1, 2], [2, 1, 0], [1, 2], [0], [2, 0], [0, 1, 2]];
        $summaries = $this->runInParallel(array_map(fn (array $s) => [
            'mode' => 'transfer', 'user_id' => $user->id, 'pallet_ids' => array_map(fn (int $i) => $pallets[$i]->id, $s),
        ], $selections));

        $accepted = $this->total($summaries, 'accepted');
        $this->assertGreaterThanOrEqual(1, $accepted);
        $this->assertSame(count($selections), $accepted + $this->total($summaries, 'rejected'));

        foreach ($pallets as $pallet) {
            $holds = InventoryTransferPallet::query()->where('pallet_id', $pallet->id)->whereIn('hold_status', [PalletHold::Active, PalletHold::Confirmed])->count();

            $this->assertLessThanOrEqual(1, $holds, "La estiba {$pallet->pallet_code} quedó en dos traslados");
            $this->assertSame($holds === 1 ? TransferStatus::Confirmed : TransferStatus::Failed, $pallet->refresh()->transfer_status);
        }
    }
}
