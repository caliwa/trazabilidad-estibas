<?php

namespace App\Jobs;

use App\Domain\Receipts\ProductionReceiptLifecycle;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks a pending receipt up in SAP. One at a time per receipt.
 */
class ReconcileProductionReceipt implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $receiptId)
    {
        $this->onQueue('sap');
    }

    public function uniqueId(): string
    {
        return (string) $this->receiptId;
    }

    public function handle(ProductionReceiptLifecycle $lifecycle): void
    {
        $lifecycle->reconcile($this->receiptId);
    }
}
