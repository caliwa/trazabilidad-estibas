<?php

namespace App\Domain\Integration;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferPallet;
use App\Models\Pallet;
use App\Models\ProductionReceipt;
use Carbon\CarbonImmutable;

/**
 * Waiting on screen for SAP's answer to documents just sent (or just
 * reconciled). The documents go to SAP from the queue, so the page polls
 * this until every one has an answer: confirmed, refused, or pending
 * reconciliation. After TIMEOUT_SECONDS without one (SAP or the worker
 * down) the wait gives up and says the documents are still in process.
 */
final class SapWait
{
    public const TIMEOUT_SECONDS = 90;

    /**
     * @param  list<array{type: 'receipt'|'transfer', id: int}>  $documents
     * @return array{title: string, documents: list<array{type: 'receipt'|'transfer', id: int}>, reconcile: bool, started_at: int}
     */
    public static function start(string $title, array $documents, bool $reconcile = false): array
    {
        return ['title' => $title, 'documents' => $documents, 'reconcile' => $reconcile, 'started_at' => now()->getTimestamp()];
    }

    /**
     * Transfers requested for these pallets since $sinceId (the last transfer id before the action).
     *
     * @param  list<int>  $palletIds
     * @return list<array{type: 'transfer', id: int}>
     */
    public static function transfersFor(array $palletIds, int $sinceId): array
    {
        $ids = InventoryTransferPallet::query()->whereIn('pallet_id', $palletIds)->where('inventory_transfer_id', '>', $sinceId)
            ->distinct()->orderBy('inventory_transfer_id')->pluck('inventory_transfer_id')->all();

        return array_values(array_map(fn ($id) => ['type' => 'transfer', 'id' => (int) $id], $ids));
    }

    public static function lastTransferId(): int
    {
        return (int) InventoryTransfer::query()->max('id');
    }

    /**
     * @param  array{title: string, documents: list<array{type: 'receipt'|'transfer', id: int}>, reconcile: bool, started_at: int}  $wait
     * @return array{done: bool, timed_out: bool, ok: bool, seconds: int, rows: list<array{label: string, state: 'waiting'|'ok'|'failed'|'pending', message: string}>}
     */
    public static function status(array $wait): array
    {
        $started = CarbonImmutable::createFromTimestamp($wait['started_at']);
        $rows = [];

        foreach ($wait['documents'] as $doc) {
            $document = $doc['type'] === 'receipt'
                ? ProductionReceipt::query()->find($doc['id'])
                : InventoryTransfer::query()->with('lines:id,inventory_transfer_id,pallet_id')->find($doc['id']);

            if ($document !== null) {
                $rows[] = self::row($document, $wait['reconcile'], $started);
            }
        }

        $seconds = (int) $started->diffInSeconds(now());
        $done = collect($rows)->every(fn (array $r) => $r['state'] !== 'waiting');

        return [
            'done' => $done,
            'timed_out' => ! $done && $seconds >= self::TIMEOUT_SECONDS,
            'ok' => $done && collect($rows)->every(fn (array $r) => $r['state'] === 'ok'),
            'seconds' => $seconds,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{label: string, state: 'waiting'|'ok'|'failed'|'pending', message: string}
     */
    private static function row(ProductionReceipt|InventoryTransfer $document, bool $reconcile, CarbonImmutable $started): array
    {
        $label = $document instanceof ProductionReceipt
            ? "Recibo de producción #{$document->id}"
            : 'Traslado '.$document->from_warehouse.' → '.$document->to_warehouse.' · '.self::pallets($document);

        $checked = $document->reconciliation['last_checked_at'] ?? null;
        $answered = match ($document->status) {
            SapDocumentStatus::Confirmed, SapDocumentStatus::Failed => true,
            // A reconciliation is answered once it checked SAP after the button was pressed.
            SapDocumentStatus::PendingReconciliation => ! $reconcile || (is_string($checked) && CarbonImmutable::parse($checked)->greaterThanOrEqualTo($started)),
            SapDocumentStatus::Requested, SapDocumentStatus::Sending => false,
        };

        if (! $answered) {
            return ['label' => $label, 'state' => 'waiting', 'message' => $document->status === SapDocumentStatus::Sending ? 'Enviando a SAP…' : ($reconcile ? 'Consultando SAP…' : 'En cola para SAP…')];
        }

        return match ($document->status) {
            SapDocumentStatus::Confirmed => ['label' => $label, 'state' => 'ok', 'message' => 'Confirmado en SAP'.($document->sap_doc_num === null ? '' : ' · Doc. '.$document->sap_doc_num)],
            SapDocumentStatus::Failed => ['label' => $label, 'state' => 'failed', 'message' => 'SAP lo rechazó: '.($document->last_error_message ?? 'sin detalle')],
            default => ['label' => $label, 'state' => 'pending', 'message' => self::pendingMessage($document)],
        };
    }

    private static function pendingMessage(ProductionReceipt|InventoryTransfer $document): string
    {
        return match ($document->reconciliation['last_result'] ?? null) {
            'consulta_fallida' => 'No se pudo consultar SAP: '.($document->reconciliation['last_error'] ?? 'sin detalle').'. Queda pendiente de conciliación.',
            'no_existe_en_espera' => 'Todavía no aparece en SAP. Queda pendiente de conciliación y se reenvía pasado el tiempo de espera.',
            'no_existe_limite_de_envios' => 'No aparece en SAP y se agotaron los envíos. Revisa y márcalo como fallido si corresponde.',
            default => 'SAP no confirmó la respuesta. Queda pendiente de conciliación: usa Conciliar.',
        };
    }

    private static function pallets(InventoryTransfer $transfer): string
    {
        return Pallet::query()->whereKey($transfer->lines->pluck('pallet_id'))->orderBy('pallet_code')->pluck('pallet_code')->implode(', ');
    }
}
