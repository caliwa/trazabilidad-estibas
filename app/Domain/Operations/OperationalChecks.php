<?php

namespace App\Domain\Operations;

use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Integration\Enums\AttemptOutcome;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Domain\Shared\TraceEventType;
use App\Models\Device;
use App\Models\DiscrepancyCase;
use App\Models\IntegrationAttempt;
use App\Models\InventoryTransfer;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\TraceEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The alerts of section 13 of the brief, computed from what the database
 * and the heartbeats say right now. Read-only: it never fixes anything.
 */
class OperationalChecks
{
    /**
     * @return list<Alert>
     */
    public function run(): array
    {
        return array_values(array_filter([
            $this->scheduler(),
            ...$this->workers(),
            ...$this->boxReaders(),
            $this->boxesNotCountedByReaders(),
            $this->queueBacklog(),
            $this->failedJobs(),
            $this->rejectedScans(),
            $this->duplicateScans(),
            $this->missingStandards(),
            $this->openOrdersWithoutStandard(),
            $this->sapErrors(),
            $this->mismatchedDocuments(),
            $this->pendingReconciliation(),
            $this->openDiscrepancies(),
        ]));
    }

    private function scheduler(): ?Alert
    {
        if (Heartbeat::isFresh(Heartbeat::SCHEDULER)) {
            return null;
        }

        return new Alert('scheduler', AlertLevel::Critical, 'El scheduler no está corriendo',
            'Sin barrido de SAP, sin vencimiento de conteos y sin estas alertas. Revisar el cron de schedule:run.', 1);
    }

    /**
     * @return list<Alert|null>
     */
    private function workers(): array
    {
        return array_values(array_map(fn (string $queue) => Heartbeat::isFresh(Heartbeat::worker($queue)) ? null : new Alert(
            "worker:{$queue}", AlertLevel::Critical, "No hay worker procesando la cola {$queue}",
            'Los trabajos se acumulan sin procesarse. Revisar Supervisor.', 1,
        ), config()->array('operations.worker_queues')));
    }

    /**
     * A fixed box reader that was connected (boxes:reader) and no longer is.
     * A reader that never connected is not flagged: it may not be installed yet.
     *
     * @return list<Alert|null>
     */
    private function boxReaders(): array
    {
        return Device::query()->where('kind', 'LECTOR')->where('active', true)->where('code', '!=', RegisterManualBoxScan::DEVICE_CODE)->orderBy('code')->get()
            ->map(fn (Device $reader) => Heartbeat::last(Heartbeat::reader($reader->code)) === null || Heartbeat::isFresh(Heartbeat::reader($reader->code)) ? null : new Alert(
                "reader:{$reader->code}", AlertLevel::Critical, "El lector de cajas {$reader->code} no está conectado",
                'Las cajas que pasan por esa línea no se están contando. Revisar el lector, la red y el proceso boxes:reader en Supervisor.', 1,
            ))->values()->all();
    }

    private function boxesNotCountedByReaders(): ?Alert
    {
        $window = config()->integer('operations.rejected_scans.window');
        $count = TraceEvent::query()->where('event', TraceEventType::ScanRejected->value)->where('subject_type', 'device')
            ->where('occurred_at', '>=', now()->subMinutes($window))->count();

        return $count === 0 ? null : new Alert('reader_not_counted', AlertLevel::Warning, 'Cajas no contadas por el lector',
            "{$count} lecturas del lector fijo en los últimos {$window} min no sumaron caja (lectura fallida, etiqueta desconocida u OF no liberada). Ver la bitácora del lector.", $count);
    }

    private function queueBacklog(): ?Alert
    {
        $ready = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', now()->getTimestamp());
        $count = (clone $ready)->count();
        $oldest = (clone $ready)->min('available_at');
        $waitMinutes = $oldest === null ? 0 : intdiv(now()->getTimestamp() - (int) $oldest, 60);

        if ($count <= config()->integer('operations.queue.max_pending') && $waitMinutes < config()->integer('operations.queue.max_wait_minutes')) {
            return null;
        }

        return new Alert('queue_backlog', AlertLevel::Critical, 'Trabajos acumulados en la cola',
            "{$count} trabajos esperando; el más viejo lleva {$waitMinutes} min.", $count);
    }

    private function failedJobs(): ?Alert
    {
        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subMinutes(config()->integer('operations.failed_jobs_window')))->count();

        return $count === 0 ? null : new Alert('failed_jobs', AlertLevel::Warning, 'Trabajos fallidos',
            "{$count} trabajos fallaron. Revisar con queue:failed antes de reintentar.", $count);
    }

    private function rejectedScans(): ?Alert
    {
        $window = config()->integer('operations.rejected_scans.window');
        $count = PalletScanEvent::query()->where('processing_status', ScanEventStatus::Rejected)->where('received_at', '>=', now()->subMinutes($window))->count();

        return $count < config()->integer('operations.rejected_scans.threshold') ? null : new Alert('rejected_scans', AlertLevel::Warning, 'Lecturas rechazadas',
            "{$count} lecturas rechazadas en los últimos {$window} min.", $count, 'pallets.rejected');
    }

    private function duplicateScans(): ?Alert
    {
        $window = config()->integer('operations.duplicate_scans.window');
        $count = EventCounter::inLast('scan_duplicates', $window);

        return $count < config()->integer('operations.duplicate_scans.threshold') ? null : new Alert('duplicate_scans', AlertLevel::Warning, 'Duplicados anormales',
            "{$count} lecturas repetidas en los últimos {$window} min. Puede ser un sensor reenviando.", $count);
    }

    private function missingStandards(): ?Alert
    {
        $window = config()->integer('operations.missing_standard_window');
        $count = PalletScanEvent::query()
            ->whereIn('rejection_reason', [ScanRejection::MissingStandard, ScanRejection::InvalidStandard, ScanRejection::ExpiredStandard])
            ->where('received_at', '>=', now()->subMinutes($window))
            ->count();

        return $count === 0 ? null : new Alert('missing_standard', AlertLevel::Critical, 'Lecturas rechazadas por estándar',
            "{$count} lecturas en los últimos {$window} min no tienen un estándar vigente y válido. La producción de ese producto no se está contando.", $count, 'pallets.rejected');
    }

    private function openOrdersWithoutStandard(): ?Alert
    {
        $count = ProductionOrder::query()->where('is_open', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from((new PalletStandard)->getTable())
                ->whereColumn('pallet_standards.item_id', 'production_orders.item_id')
                ->where('status', MasterStatus::Current))
            ->count();

        return $count === 0 ? null : new Alert('orders_without_standard', AlertLevel::Warning, 'Órdenes abiertas sin estándar',
            "{$count} órdenes abiertas tienen un producto sin estándar vigente.", $count);
    }

    private function sapErrors(): ?Alert
    {
        $window = config()->integer('operations.sap_errors_window');
        $count = IntegrationAttempt::query()
            ->whereIn('outcome', [AttemptOutcome::BusinessError, AttemptOutcome::Uncertain, AttemptOutcome::QueryFailed])
            ->where('started_at', '>=', now()->subMinutes($window))
            ->count();

        return $count === 0 ? null : new Alert('sap_errors', AlertLevel::Warning, 'Errores en llamadas a SAP',
            "{$count} llamadas con error, timeout o consulta fallida en los últimos {$window} min.", $count);
    }

    private function mismatchedDocuments(): ?Alert
    {
        $count = $this->pendingDocuments(fn (Builder $q) => $q->whereRaw("(reconciliation->>'mismatch')::boolean IS TRUE"));

        return $count === 0 ? null : new Alert('sap_mismatch', AlertLevel::Critical, 'Documentos en SAP que no coinciden',
            "{$count} documentos con nuestra correlación no coinciden con lo pedido. Nunca se confirman solos: revisar a mano.", $count);
    }

    private function pendingReconciliation(): ?Alert
    {
        $minutes = config()->integer('operations.pending_reconciliation_minutes');
        $count = $this->pendingDocuments(fn (Builder $q) => $q->where('requested_at', '<', now()->subMinutes($minutes)));

        return $count === 0 ? null : new Alert('pending_reconciliation', AlertLevel::Warning, 'Pendientes de conciliación',
            "{$count} documentos llevan más de {$minutes} min sin saberse si existen en SAP.", $count);
    }

    /**
     * @param  callable(Builder<ProductionReceipt>|Builder<InventoryTransfer>): mixed  $filter
     */
    private function pendingDocuments(callable $filter): int
    {
        $total = 0;
        foreach ([ProductionReceipt::query(), InventoryTransfer::query()] as $query) {
            $query->where('status', SapDocumentStatus::PendingReconciliation);
            $filter($query);
            $total += $query->count();
        }

        return $total;
    }

    private function openDiscrepancies(): ?Alert
    {
        $count = DiscrepancyCase::query()->whereIn('status', [DiscrepancyStatus::Open, DiscrepancyStatus::InReview])->count();

        return $count === 0 ? null : new Alert('open_discrepancies', AlertLevel::Warning, 'Discrepancias abiertas',
            "{$count} estibas con discrepancia, retenidas sin traslado.", $count, 'discrepancies.index');
    }
}
