<?php

namespace App\Jobs;

use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Transfers\Actions\RequestInventoryTransfers;
use App\Domain\Transfers\Enums\TransferMode;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Requests the individual transfer of a pallet whose blind count was just
 * approved, when the operating flow is configured that way.
 */
class StartTransferAfterAudit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $palletId, public readonly int $countedBy, public readonly string $correlationId) {}

    public function handle(RequestInventoryTransfers $request): void
    {
        $pallet = Pallet::query()->find($this->palletId);
        $user = User::query()->find($this->countedBy);

        if ($pallet === null || $user === null || $pallet->transfer_status !== TransferStatus::Ready) {
            return;
        }

        try {
            $request->handle($user, [$pallet->id], TransferMode::Individual, false, TraceContext::system(Origin::Job, $this->correlationId), automatic: true);
        } catch (TransferRuleViolation) {
            // Someone requested it meanwhile, or it stopped being eligible: nothing to start.
        }
    }
}
