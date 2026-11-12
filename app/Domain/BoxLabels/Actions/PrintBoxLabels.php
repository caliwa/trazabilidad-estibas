<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\BoxLabels\BoxLabelZplOverride;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\BoxLabelBatch;
use App\Models\BoxLabelPrint;
use App\Models\User;
use App\Services\TraceLogger;
use App\Services\ZebraPrintService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sends a batch of box labels to the Zebra and, when the printer took it,
 * records the print: from then on the lot of that batch is on paper and
 * cannot be changed (GenerateBoxLabels).
 *
 * A batch is printed once. Printing it again would put a second label with
 * the same QR token on another box, which could never be counted: labels
 * for boxes still without one are made with "Generar etiquetas", which
 * continues the lot's sequence with new tokens. The batch row is locked so
 * two clicks at once cannot both print, and the database refuses a second
 * print record as well (box_label_prints_once).
 */
class PrintBoxLabels
{
    public function __construct(
        private readonly ZebraPrintService $zebra,
        private readonly TraceLogger $trace,
    ) {}

    /**
     * @return array<string, mixed> What ZebraPrintService::printToSocket answered.
     */
    public function handle(User $user, int $batchId, string $ip, int $port, TraceContext $ctx): array
    {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        return DB::transaction(function () use ($user, $batchId, $ip, $port, $ctx): array {
            $batch = BoxLabelBatch::query()->lockForUpdate()->findOrFail($batchId);

            if (BoxLabelPrint::query()->where('box_label_batch_id', $batch->id)->exists()) {
                return ['success' => false, 'message' => 'Estas etiquetas ya se imprimieron y no se vuelven a imprimir: saldrían cajas con el mismo QR, que no se pueden contar. Para las cajas que faltan, usa «Generar etiquetas»: el consecutivo sigue con QR nuevos.'];
            }

            $result = $this->zebra->printToSocket($ip, $port, BoxLabelZplOverride::resolveZpl($batch));

            if (($result['success'] ?? false) !== true) {
                return $result;
            }

            $print = new BoxLabelPrint;
            $print->forceFill([
                'box_label_batch_id' => $batch->id,
                'production_order_id' => $batch->production_order_id,
                'lot_code' => $batch->lot_code,
                'labels' => $batch->labels,
                'printer' => mb_substr(trim($ip).':'.$port, 0, 100),
                'printed_by' => $user->id,
                'printed_at' => now(),
            ])->save();

            $this->trace->record($ctx, TraceEventType::BoxLabelsPrinted, $batch->productionOrder()->firstOrFail(), context: [
                'box_label_batch_id' => $batch->id,
                'labels' => $batch->labels,
                'lot_code' => $batch->lot_code,
                'printer' => $print->printer,
            ]);

            return $result;
        });
    }
}
