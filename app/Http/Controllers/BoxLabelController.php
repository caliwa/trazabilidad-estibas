<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Models\BoxLabelBatch;
use App\Services\TraceLogger;
use App\Support\Correlation\CorrelationId;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ZPL of a generated batch, as a file to send to the Zebra. Every
 * download is logged: downloading twice means the boxes may be labelled
 * twice.
 */
class BoxLabelController extends Controller
{
    public function zpl(Request $request, BoxLabelBatch $batch, TraceLogger $trace, CorrelationId $correlation): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $trace->record(TraceContext::forUser($user, Origin::Web, $correlation->get(), $request->ip()), TraceEventType::BoxLabelsDownloaded, $batch->productionOrder()->firstOrFail(), context: [
            'box_label_batch_id' => $batch->id,
            'labels' => $batch->labels,
            'lot_code' => $batch->lot_code,
        ]);

        $name = "OF{$batch->production_order_ref}-lote{$batch->lot_code}-{$batch->labels}cajas.zpl";

        return response($batch->zpl, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
