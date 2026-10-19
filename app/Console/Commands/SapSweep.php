<?php

namespace App\Console\Commands;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\ProductionReceiptLifecycle;
use App\Jobs\ReconcileProductionReceipt;
use App\Jobs\SendProductionReceipt;
use App\Models\ProductionReceipt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Safety net for SAP documents, run by the scheduler every minute:
 *  - requests still SOLICITADO (their job was lost) are queued again;
 *  - sends stuck in ENVIANDO (a worker died) go to reconciliation;
 *  - pending reconciliations are checked again on their interval.
 */
#[Signature('sap:sweep')]
#[Description('Reencola envíos perdidos, pasa a conciliación los envíos colgados y concilia los pendientes.')]
class SapSweep extends Command
{
    public function handle(ProductionReceiptLifecycle $receipts): int
    {
        $queued = ProductionReceipt::query()
            ->where('status', SapDocumentStatus::Requested)
            ->where('requested_at', '<=', now()->subMinutes(config()->integer('sap.reconciliation.requeue_after_minutes')))
            ->pluck('id')
            ->each(fn (int $id) => SendProductionReceipt::dispatch($id))
            ->count();

        $stale = ProductionReceipt::query()
            ->where('status', SapDocumentStatus::Sending)
            ->where('sent_at', '<=', now()->subMinutes(config()->integer('sap.reconciliation.stale_sending_minutes')))
            ->pluck('id')
            ->each(fn (int $id) => $receipts->abandonStaleSend($id))
            ->count();

        $checkEvery = config()->integer('sap.reconciliation.check_every_minutes');
        $reconciling = ProductionReceipt::query()
            ->where('status', SapDocumentStatus::PendingReconciliation)
            ->where(fn ($q) => $q->whereNull('reconciliation->last_checked_at')
                ->orWhere('reconciliation->last_checked_at', '<=', now()->subMinutes($checkEvery)->toIso8601String()))
            ->pluck('id')
            ->each(fn (int $id) => ReconcileProductionReceipt::dispatch($id))
            ->count();

        $this->info("Reencolados: {$queued} · envíos colgados: {$stale} · a conciliar: {$reconciling}");

        return self::SUCCESS;
    }
}
