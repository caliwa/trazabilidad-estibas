<?php

namespace App\Console\Commands;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Integration\SapDocumentLifecycle;
use App\Domain\Receipts\ProductionReceiptLifecycle;
use App\Domain\Transfers\InventoryTransferLifecycle;
use App\Jobs\ReconcileInventoryTransfer;
use App\Jobs\ReconcileProductionReceipt;
use App\Jobs\SendInventoryTransfer;
use App\Jobs\SendProductionReceipt;
use App\Models\InventoryTransfer;
use App\Models\ProductionReceipt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Safety net for SAP documents (receipts and transfers), run by the
 * scheduler every minute:
 *  - requests still SOLICITADO (their job was lost) are queued again;
 *  - sends stuck in ENVIANDO (a worker died) go to reconciliation;
 *  - pending reconciliations are checked again on their interval.
 */
#[Signature('sap:sweep')]
#[Description('Reencola envíos perdidos, pasa a conciliación los envíos colgados y concilia los pendientes.')]
class SapSweep extends Command
{
    public function handle(ProductionReceiptLifecycle $receipts, InventoryTransferLifecycle $transfers): int
    {
        $r = $this->sweep(ProductionReceipt::query(), $receipts, fn (int $id) => SendProductionReceipt::dispatch($id), fn (int $id) => ReconcileProductionReceipt::dispatch($id));
        $t = $this->sweep(InventoryTransfer::query(), $transfers, fn (int $id) => SendInventoryTransfer::dispatch($id), fn (int $id) => ReconcileInventoryTransfer::dispatch($id));

        $this->info("Recibos · reencolados: {$r[0]} · envíos colgados: {$r[1]} · a conciliar: {$r[2]}");
        $this->info("Traslados · reencolados: {$t[0]} · envíos colgados: {$t[1]} · a conciliar: {$t[2]}");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<ProductionReceipt>|Builder<InventoryTransfer>  $documents
     * @return array{int, int, int}
     */
    private function sweep(Builder $documents, SapDocumentLifecycle $lifecycle, callable $send, callable $reconcile): array
    {
        $queued = (clone $documents)
            ->where('status', SapDocumentStatus::Requested)
            ->where('requested_at', '<=', now()->subMinutes(config()->integer('sap.reconciliation.requeue_after_minutes')))
            ->pluck('id')->each(fn (int $id) => $send($id))->count();

        $stale = (clone $documents)
            ->where('status', SapDocumentStatus::Sending)
            ->where('sent_at', '<=', now()->subMinutes(config()->integer('sap.reconciliation.stale_sending_minutes')))
            ->pluck('id')->each(fn (int $id) => $lifecycle->abandonStaleSend($id))->count();

        $checkEvery = config()->integer('sap.reconciliation.check_every_minutes');
        $reconciling = (clone $documents)
            ->where('status', SapDocumentStatus::PendingReconciliation)
            ->where(fn ($q) => $q->whereNull('reconciliation->last_checked_at')
                ->orWhere('reconciliation->last_checked_at', '<=', now()->subMinutes($checkEvery)->toIso8601String()))
            ->pluck('id')->each(fn (int $id) => $reconcile($id))->count();

        return [$queued, $stale, $reconciling];
    }
}
