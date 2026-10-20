<?php

namespace App\Jobs;

use App\Domain\Transfers\InventoryTransferLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one transfer request to SAP. Does nothing unless it is still
 * SOLICITADO; never retried by the queue.
 */
class SendInventoryTransfer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $transferId)
    {
        $this->onQueue('sap');
    }

    public function handle(InventoryTransferLifecycle $lifecycle): void
    {
        $lifecycle->send($this->transferId);
    }
}
