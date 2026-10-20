<?php

namespace Tests\Concerns;

use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Audits\Data\BlindCountOutcome;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Transfers\Actions\RequestInventoryTransfers;
use App\Enums\Role;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pallets that went through the real flow: received in 04 by the fake SAP
 * and, when counted with the right number, transferred to 05 by the count
 * itself.
 */
trait BuildsTransfers
{
    use BuildsReceipts;

    /**
     * @param  list<string>  $lots
     * @return Collection<int, Pallet>
     */
    protected function receivedPallets(array $lots, string $unitsPerBox = '12'): Collection
    {
        $receiver = User::factory()->create()->assignRole(Role::TraceabilityAdmin->value);
        $pallets = $this->labeledPallets($lots, $unitsPerBox);
        $this->requestReceipts($receiver, $pallets);

        return $pallets->map(fn (Pallet $p) => $p->refresh());
    }

    /**
     * Blind count of one pallet; with the right number this also requests its transfer.
     */
    protected function countPallet(Pallet $pallet, ?int $boxes = null, ?User $counter = null): BlindCountOutcome
    {
        $counter ??= User::factory()->create()->assignRole(Role::LogisticsOperator->value);
        $view = app(StartBlindCount::class)->handle($counter, PalletQr::payload($pallet->refresh()), $this->transferCtx($counter));

        return app(SubmitBlindCount::class)->handle($counter, $view->auditId, $boxes ?? $pallet->boxes_count, $this->transferCtx($counter));
    }

    /**
     * Received and counted correctly, so each one already has its transfer.
     *
     * @param  list<string>  $lots
     * @return Collection<int, Pallet>
     */
    protected function auditedPallets(array $lots, string $unitsPerBox = '12'): Collection
    {
        $pallets = $this->receivedPallets($lots, $unitsPerBox);
        $pallets->each(fn (Pallet $p) => $this->countPallet($p));

        return $pallets->map(fn (Pallet $p) => $p->refresh());
    }

    protected function transferOf(Pallet $pallet): InventoryTransfer
    {
        return InventoryTransfer::query()->whereHas('lines', fn ($q) => $q->where('pallet_id', $pallet->id))->latest('id')->firstOrFail();
    }

    /**
     * The manual retry (pallet detail page), for a failed or missing transfer.
     *
     * @param  iterable<Pallet>  $pallets
     * @return list<InventoryTransfer>
     */
    protected function retryTransfers(User $user, iterable $pallets): array
    {
        return app(RequestInventoryTransfers::class)->handle($user, collect($pallets)->pluck('id')->all(), $this->transferCtx($user));
    }

    protected function transferCtx(User $user): TraceContext
    {
        return TraceContext::forUser($user, Origin::Web, (string) Str::uuid());
    }
}
