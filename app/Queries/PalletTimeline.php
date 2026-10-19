<?php

namespace App\Queries;

use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Shared\TraceEventType;
use App\Models\InventoryTransferPallet;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\ProductionReceiptPallet;
use App\Models\TraceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything that happened to one pallet, oldest first: the traceability
 * log, a summary of its box reads and the SAP documents that hold it.
 */
class PalletTimeline
{
    /**
     * @return Collection<int, array{at: CarbonImmutable, title: string, detail: string|null, who: string}>
     */
    public function entries(Pallet $pallet): Collection
    {
        $traces = TraceEvent::query()
            ->with(['user:id,name', 'device:id,code'])
            ->where('subject_type', $pallet->getMorphClass())
            ->where('subject_id', $pallet->id)
            ->orderBy('occurred_at')->orderBy('id')
            ->get()
            ->map(fn (TraceEvent $t) => [
                'at' => $t->occurred_at,
                'title' => TraceEventType::tryFrom($t->event)?->label() ?? $t->event,
                'detail' => $t->reason,
                'who' => $t->user->name ?? ($t->device === null ? 'Sistema' : 'Dispositivo '.$t->device->code),
            ]);

        return $traces->values();
    }

    /**
     * @return array{accepted: int, late: int, first: CarbonImmutable|null, last: CarbonImmutable|null}
     */
    public function scanSummary(Pallet $pallet): array
    {
        $row = PalletScanEvent::query()
            ->where('pallet_id', $pallet->id)
            ->where('processing_status', ScanEventStatus::Accepted)
            ->selectRaw('count(*) AS accepted, count(*) FILTER (WHERE is_late) AS late, min(event_occurred_at) AS first, max(event_occurred_at) AS last')
            ->toBase()
            ->first();

        return [
            'accepted' => (int) ($row->accepted ?? 0),
            'late' => (int) ($row->late ?? 0),
            'first' => $row?->first === null ? null : new CarbonImmutable($row->first),
            'last' => $row?->last === null ? null : new CarbonImmutable($row->last),
        ];
    }

    /**
     * @return Collection<int, array{kind: string, status: string, doc_num: int|null, doc_entry: int|null, request_uuid: string, sap_quantity: string, hold: string, sap_line_num: int|null}>
     */
    public function documents(Pallet $pallet): Collection
    {
        $receipts = ProductionReceiptPallet::query()->with('productionReceipt')->where('pallet_id', $pallet->id)->orderBy('id')->get()
            ->map(fn (ProductionReceiptPallet $l) => [
                'kind' => 'Recibo de producción',
                'status' => $l->productionReceipt->status->label(),
                'doc_num' => $l->productionReceipt->sap_doc_num,
                'doc_entry' => $l->productionReceipt->sap_doc_entry,
                'request_uuid' => $l->productionReceipt->request_uuid,
                'sap_quantity' => (string) $l->sap_quantity,
                'hold' => $l->hold_status->value,
                'sap_line_num' => $l->sap_line_num,
            ]);

        $transfers = InventoryTransferPallet::query()->with('inventoryTransfer')->where('pallet_id', $pallet->id)->orderBy('id')->get()
            ->map(fn (InventoryTransferPallet $l) => [
                'kind' => 'Traslado '.$l->inventoryTransfer->from_warehouse.' → '.$l->inventoryTransfer->to_warehouse,
                'status' => $l->inventoryTransfer->status->label(),
                'doc_num' => $l->inventoryTransfer->sap_doc_num,
                'doc_entry' => $l->inventoryTransfer->sap_doc_entry,
                'request_uuid' => $l->inventoryTransfer->request_uuid,
                'sap_quantity' => (string) $l->sap_quantity,
                'hold' => $l->hold_status->value,
                'sap_line_num' => $l->sap_line_num,
            ]);

        return $receipts->concat($transfers)->values();
    }
}
