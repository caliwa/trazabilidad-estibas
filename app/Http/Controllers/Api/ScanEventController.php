<?php

namespace App\Http\Controllers\Api;

use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Exceptions\BoxAlreadyRead;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\Exceptions\ScanIngestionDisabled;
use App\Domain\Shared\TraceContext;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Requests\StoreScanEventRequest;
use App\Models\Device;
use App\Support\Correlation\CorrelationId;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/scan-events: one box read by a device.
 *
 * The event id has to be the QR (or its token) of a box printed by this
 * application; the printed box code does not identify a box. The box goes through the same rules as every other
 * read (RegisterManualBoxScan) and its token counts once, whoever reads it.
 * The order, product and lot sent must be the box's.
 *
 * 201 accepted, 200 already read (safe to resend), 422 rejected or
 * malformed, 401 unknown device, 429 too many requests, 503 ingestion off.
 */
class ScanEventController extends Controller
{
    public function store(StoreScanEventRequest $request, RegisterManualBoxScan $boxes, CorrelationId $correlation): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get(AuthenticateDevice::ATTRIBUTE);

        if (! config()->boolean('traceability.decisions.scan_ingestion_enabled')) {
            $e = new ScanIngestionDisabled;

            return response()->json(['status' => 'disabled', 'message' => $e->getMessage()], 503);
        }

        $data = $request->toData();
        $box = RegisterManualBoxScan::boxNamedBy($data->externalEventId);

        if ($box === null) {
            return $this->rejected('NO_ES_CAJA', 'El event_id debe ser el QR o el token del QR de una caja impresa por esta aplicación (el código de caja impreso no sirve). Sin la caja no se puede garantizar que se cuente una sola vez.');
        }

        if ([$box->batch->production_order_ref, $box->batch->item_code, $box->lot_code] !== [$data->productionOrderRef, $data->itemCode, $data->lotCode]) {
            return $this->rejected('DATOS_DISTINTOS_A_LA_CAJA', "La OF, el producto o el lote enviados no son los de la caja {$box->code}.");
        }

        try {
            $scan = $boxes->fromDevice($device, $data->externalEventId, TraceContext::forDevice($device, $correlation->get(), $request->ip()));
        } catch (BoxAlreadyRead $e) {
            return response()->json(['status' => 'duplicate', 'box_code' => $box->code, 'message' => $e->getMessage()], 200);
        } catch (PalletRuleViolation $e) {
            return $this->rejected('CAJA_RECHAZADA', $e->getMessage(), $box->code);
        }

        return response()->json(array_filter([
            'status' => 'accepted',
            'box_code' => $scan->boxCode,
            'pallet_code' => $scan->result->palletCode,
            'boxes_count' => $scan->result->boxesCount,
            'standard' => $scan->result->standard,
            'pallet_closed' => $scan->result->palletCode === null ? null : $scan->result->palletClosed,
        ], fn ($value) => $value !== null), 201);
    }

    private function rejected(string $code, string $message, ?string $boxCode = null): JsonResponse
    {
        return response()->json(array_filter(['status' => 'rejected', 'rejection' => $code, 'box_code' => $boxCode, 'message' => $message], fn ($v) => $v !== null), 422);
    }
}
