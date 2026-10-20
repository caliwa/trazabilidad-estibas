<?php

namespace Tests\Concerns;

use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Transfers\Actions\RequestInventoryTransfers;
use App\Domain\Transfers\Enums\TransferMode;
use App\Enums\Role;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pallets that went through the real flow: received in 04 by the fake SAP
 * and counted with the right number, so each one has a confirmed receipt
 * line and an approved audit.
 */
trait BuildsTransfers
{
    use BuildsReceipts;

    /**
     * @param  list<string>  $lots
     * @return Collection<int, Pallet>
     */
    protected function auditedPallets(array $lots, string $unitsPerBox = '12'): Collection
    {
        $receiver = User::factory()->create()->assignRole(Role::TraceabilityAdmin->value);
        $counter = User::factory()->create()->assignRole(Role::LogisticsOperator->value);
        $pallets = $this->labeledPallets($lots, $unitsPerBox);

        $this->requestReceipts($receiver, $pallets);

        foreach ($pallets as $pallet) {
            $pallet->refresh();
            $view = app(StartBlindCount::class)->handle($counter, PalletQr::payload($pallet), $this->transferCtx($counter));
            app(SubmitBlindCount::class)->handle($counter, $view->auditId, $pallet->boxes_count, $this->transferCtx($counter));
        }

        return $pallets->map(fn (Pallet $p) => $p->refresh());
    }

    /**
     * @param  iterable<Pallet>  $pallets
     * @return list<InventoryTransfer>
     */
    protected function requestTransfers(User $user, iterable $pallets, TransferMode $mode = TransferMode::Individual, bool $groupingConfirmed = false): array
    {
        return app(RequestInventoryTransfers::class)->handle($user, collect($pallets)->pluck('id')->all(), $mode, $groupingConfirmed, $this->transferCtx($user));
    }

    protected function transferCtx(User $user): TraceContext
    {
        return TraceContext::forUser($user, Origin::Web, (string) Str::uuid());
    }
}
