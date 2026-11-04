<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\BoxLabelBatch;
use App\Models\BoxLabelPrint;
use App\Models\BoxLabelPrintJob;
use App\Models\User;
use App\Services\TraceLogger;
use App\Services\ZebraPrintService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ManagePrintJob
{
    public function __construct(
        private readonly ZebraPrintService $zebra,
        private readonly TraceLogger $trace,
    ) {}

    /**
     * Obtiene o crea un trabajo de impresión para el lote especificado.
     */
    public function createOrGetJob(User $user, int $batchId, string $ip, int $port, int $chunkSize = 25): BoxLabelPrintJob
    {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        $batch = BoxLabelBatch::query()->with('boxLabels')->findOrFail($batchId);

        // Si ya hay un trabajo activo o en pausa para este lote, retomarlo
        $existingJob = BoxLabelPrintJob::query()
            ->where('box_label_batch_id', $batchId)
            ->whereIn('status', ['pending', 'printing', 'paused_error'])
            ->latest('id')
            ->first();

        if ($existingJob) {
            $existingJob->printer_ip = trim($ip);
            $existingJob->printer_port = $port;
            $existingJob->chunk_size = max(10, min(100, $chunkSize));
            $existingJob->save();

            return $existingJob;
        }

        $minSeq = (int) ($batch->boxLabels()->min('sequence') ?? 1);
        $maxSeq = (int) ($batch->boxLabels()->max('sequence') ?? ($minSeq + $batch->labels - 1));

        $job = new BoxLabelPrintJob;
        $job->forceFill([
            'box_label_batch_id' => $batch->id,
            'production_order_id' => $batch->production_order_id,
            'lot_code' => $batch->lot_code,
            'total_labels' => $batch->labels,
            'printed_labels' => 0,
            'pending_labels' => $batch->labels,
            'start_sequence' => $minSeq,
            'end_sequence' => $maxSeq,
            'last_printed_sequence' => 0,
            'next_sequence' => $minSeq,
            'status' => 'printing',
            'error_reason' => null,
            'printer_ip' => trim($ip),
            'printer_port' => $port,
            'chunk_size' => max(10, min(100, $chunkSize)),
            'created_by' => $user->id,
            'started_at' => now(),
        ]);
        $job->save();

        return $job;
    }

    /**
     * Procesa el siguiente sub-lote (chunk) del trabajo de impresión.
     *
     * @return array<string, mixed>
     */
    public function processChunk(BoxLabelPrintJob $job, TraceContext $ctx): array
    {
        if ($job->status === 'completed' || $job->pending_labels <= 0) {
            return [
                'success' => true,
                'status' => 'completed',
                'job' => $job->fresh(),
                'message' => 'El trabajo de impresión ya ha concluido con todas las etiquetas impresas.',
            ];
        }

        $allLabels = $this->zebra->splitZplIntoLabels($job->batch->zpl ?? '');
        $totalLabelsInZpl = count($allLabels);

        if ($totalLabelsInZpl === 0) {
            $job->status = 'paused_error';
            $job->error_reason = 'El lote no contiene formatos ZPL válidos.';
            $job->save();

            return [
                'success' => false,
                'status' => 'paused_error',
                'job' => $job->fresh(),
                'message' => 'No se encontraron etiquetas ZPL en el lote.',
            ];
        }

        $offset = $job->printed_labels;
        $chunkLabels = array_slice($allLabels, $offset, $job->chunk_size);
        $countInChunk = count($chunkLabels);

        if ($countInChunk === 0) {
            // Ya no quedan etiquetas por enviar
            $job->status = 'completed';
            $job->pending_labels = 0;
            $job->completed_at = now();
            $job->save();

            $this->recordPrintCompletion($job, $ctx);

            return [
                'success' => true,
                'status' => 'completed',
                'job' => $job->fresh(),
                'message' => 'Todas las etiquetas han sido impresas.',
            ];
        }

        $chunkZpl = implode("\n", $chunkLabels);
        $result = $this->zebra->printChunk(
            $job->printer_ip,
            $job->printer_port,
            $chunkZpl,
            $countInChunk
        );

        if ($result['success']) {
            $job->printed_labels += $countInChunk;
            $job->pending_labels = max(0, $job->total_labels - $job->printed_labels);
            $job->last_printed_sequence = $job->start_sequence + $job->printed_labels - 1;
            $job->next_sequence = $job->last_printed_sequence + 1;
            $job->error_reason = null;

            if ($job->pending_labels <= 0) {
                $job->status = 'completed';
                $job->completed_at = now();
                $job->save();

                $this->recordPrintCompletion($job, $ctx);
            } else {
                $job->status = 'printing';
                $job->save();
            }

            return [
                'success' => true,
                'status' => $job->status,
                'job' => $job->fresh(),
                'message' => "Sub-lote impreso ({$job->printed_labels} de {$job->total_labels}).",
            ];
        }

        // Si ocurrió error físico (falta papel, cabezal abierto, etc.)
        $job->status = 'paused_error';
        $job->error_reason = $result['error_reason'] ?? $result['message'];
        $job->save();

        return [
            'success' => false,
            'status' => 'paused_error',
            'job' => $job->fresh(),
            'message' => $result['message'],
            'error_reason' => $job->error_reason,
        ];
    }

    /**
     * Reanuda un trabajo pausado por error permitiendo especificar exactamente desde qué caja continuar.
     */
    public function resumeJob(BoxLabelPrintJob $job, ?int $fromSequence = null): void
    {
        if ($fromSequence !== null && $fromSequence >= $job->start_sequence && $fromSequence <= $job->end_sequence) {
            $printed = $fromSequence - $job->start_sequence;
            $job->printed_labels = $printed;
            $job->pending_labels = max(0, $job->total_labels - $printed);
            $job->last_printed_sequence = $printed > 0 ? ($fromSequence - 1) : 0;
            $job->next_sequence = $fromSequence;
        }

        $job->status = 'printing';
        $job->error_reason = null;
        $job->save();
    }

    /**
     * Cancela las etiquetas pendientes de un trabajo.
     */
    public function cancelJob(BoxLabelPrintJob $job): void
    {
        $job->status = 'cancelled';
        $job->completed_at = now();
        $job->save();
    }

    /**
     * Registra la finalización en la tabla histórica de impresiones y auditoría.
     */
    private function recordPrintCompletion(BoxLabelPrintJob $job, TraceContext $ctx): void
    {
        DB::transaction(function () use ($job, $ctx): void {
            $print = new BoxLabelPrint;
            $print->forceFill([
                'box_label_batch_id' => $job->box_label_batch_id,
                'production_order_id' => $job->production_order_id,
                'lot_code' => $job->lot_code,
                'labels' => $job->printed_labels,
                'printer' => mb_substr(trim($job->printer_ip).':'.$job->printer_port, 0, 100),
                'printed_by' => $job->created_by,
                'printed_at' => now(),
            ])->save();

            $order = $job->productionOrder;
            if ($order) {
                $this->trace->record($ctx, TraceEventType::BoxLabelsPrinted, $order, context: [
                    'box_label_batch_id' => $job->box_label_batch_id,
                    'labels' => $job->printed_labels,
                    'lot_code' => $job->lot_code,
                    'printer' => $print->printer,
                ]);
            }
        });
    }
}
