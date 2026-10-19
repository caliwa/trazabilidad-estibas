<?php

namespace App\Http\Controllers\Api;

use App\Domain\Pallets\Actions\ProcessScanEvent;
use App\Domain\Pallets\Data\ScanOutcome;
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
 * 201 accepted, 200 already processed (safe to resend), 422 rejected or
 * malformed, 401 unknown device, 429 too many requests, 503 ingestion off.
 */
class ScanEventController extends Controller
{
    public function store(StoreScanEventRequest $request, ProcessScanEvent $process, CorrelationId $correlation): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get(AuthenticateDevice::ATTRIBUTE);

        try {
            $result = $process->handle($device, $request->toData(), TraceContext::forDevice($device, $correlation->get(), $request->ip()));
        } catch (ScanIngestionDisabled $e) {
            return response()->json(['status' => 'disabled', 'message' => $e->getMessage()], 503);
        }

        $body = array_filter([
            'status' => $result->outcome->value,
            'pallet_code' => $result->palletCode,
            'boxes_count' => $result->boxesCount,
            'standard' => $result->standard,
            'pallet_closed' => $result->palletCode === null ? null : $result->palletClosed,
            'rejection' => $result->rejection?->value,
            'message' => $result->rejection?->label(),
            'payload_mismatch' => $result->payloadMismatch ?: null,
        ], fn ($value) => $value !== null);

        return response()->json($body, match ($result->outcome) {
            ScanOutcome::Accepted => 201,
            ScanOutcome::Duplicate => 200,
            ScanOutcome::Rejected => 422,
        });
    }
}
