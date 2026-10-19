<?php

namespace App\Jobs;

use App\Domain\Receipts\ProductionReceiptLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one production receipt request to SAP. Safe to run twice: it does
 * nothing unless the request is still SOLICITADO. Never retried by the
 * queue: an unknown outcome is handled by reconciliation.
 */
class SendProductionReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $receiptId)
    {
        $this->onQueue('sap');
    }

    public function handle(ProductionReceiptLifecycle $lifecycle): void
    {
        $lifecycle->send($this->receiptId);
    }
}
