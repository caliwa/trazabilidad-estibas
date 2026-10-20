<?php

namespace App\Jobs;

use App\Domain\Transfers\InventoryTransferLifecycle;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileInventoryTransfer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $transferId)
    {
        $this->onQueue('sap');
    }

    public function uniqueId(): string
    {
        return 'transfer-'.$this->transferId;
    }

    public function handle(InventoryTransferLifecycle $lifecycle): void
    {
        $lifecycle->reconcile($this->transferId);
    }
}
