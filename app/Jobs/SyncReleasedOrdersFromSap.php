<?php

namespace App\Jobs;

use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Orders\OrderSyncStatus;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Actualizar desde SAP" of the order board, in the background: bringing
 * every released order can take longer than a web request may last. One
 * run at a time (unique job and a lock shared with sap:sync-orders); the
 * board reads the outcome from OrderSyncStatus.
 */
class SyncReleasedOrdersFromSap implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $userId, public readonly string $correlationId)
    {
        $this->onQueue('sap');
    }

    public function handle(SyncReleasedOrders $sync): void
    {
        $lock = Cache::lock('orders:sync', $this->timeout);

        if (! $lock->get()) {
            OrderSyncStatus::failed('Ya hay otra actualización desde SAP en curso.');

            return;
        }

        try {
            OrderSyncStatus::running();
            $user = User::query()->find($this->userId);
            $ctx = $user === null
                ? TraceContext::system(Origin::Job, $this->correlationId)
                : TraceContext::forUser($user, Origin::Job, $this->correlationId);

            OrderSyncStatus::finished($sync->handle($user, $ctx));
        } catch (Throwable $e) {
            OrderSyncStatus::failed('La actualización desde SAP falló. El tablero sigue con los datos anteriores.');
            report($e);
        } finally {
            $lock->release();
        }
    }
}
