<?php

namespace App\Queries;

use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use Illuminate\Support\Facades\DB;

/**
 * The parts of the order detail that are not totals: lots, SAP documents
 * and how long each step takes.
 */
class OrderDetailQuery
{
    /**
     * Per lot: its expiration (from the box labels), labels, boxes read and where they are.
     *
     * @return list<object{lot_code: string, expiration_date: ?string, labels: int, boxes_read: int, pallets: int, received_boxes: int, transferred_boxes: int}>
     */
    public function lots(int $orderId): array
    {
        /** @var list<object{lot_code: string, expiration_date: ?string, labels: int, boxes_read: int, pallets: int, received_boxes: int, transferred_boxes: int}> */
        return DB::select(
            'WITH b AS (
                SELECT lot_code, min(expiration_date) AS expiration_date, sum(labels) AS labels
                FROM box_label_batches WHERE production_order_id = ? GROUP BY lot_code
            ), p AS (
                SELECT lot_code, sum(boxes_count) AS boxes_read, count(*) AS pallets,
                    coalesce(sum(boxes_count) FILTER (WHERE receipt_status = ?), 0) AS received_boxes,
                    coalesce(sum(boxes_count) FILTER (WHERE transfer_status = ?), 0) AS transferred_boxes
                FROM pallets WHERE production_order_id = ? GROUP BY lot_code
            )
            SELECT coalesce(b.lot_code, p.lot_code) AS lot_code, b.expiration_date,
                coalesce(b.labels, 0)::int AS labels, coalesce(p.boxes_read, 0)::int AS boxes_read, coalesce(p.pallets, 0)::int AS pallets,
                coalesce(p.received_boxes, 0)::int AS received_boxes, coalesce(p.transferred_boxes, 0)::int AS transferred_boxes
            FROM b FULL JOIN p ON p.lot_code = b.lot_code
            ORDER BY 1',
            [$orderId, ReceiptStatus::Confirmed->value, TransferStatus::Confirmed->value, $orderId],
        );
    }

    /**
     * Receipts and transfers of the order, newest first.
     *
     * @return list<object{kind: string, id: int, status: string, sap_doc_num: ?int, pallets: int, quantity: ?string, requested_at: string, confirmed_at: ?string}>
     */
    public function documents(int $orderId): array
    {
        /** @var list<object{kind: string, id: int, status: string, sap_doc_num: ?int, pallets: int, quantity: ?string, requested_at: string, confirmed_at: ?string}> */
        return DB::select(
            "SELECT 'recibo' AS kind, r.id, r.status, r.sap_doc_num, count(l.id)::int AS pallets, sum(l.sap_quantity) AS quantity, r.requested_at, r.confirmed_at
             FROM production_receipts r JOIN production_receipt_pallets l ON l.production_receipt_id = r.id
             WHERE r.production_order_id = ?
             GROUP BY r.id
             UNION ALL
             SELECT 'transferencia', t.id, t.status, t.sap_doc_num, count(tp.id)::int, NULL, t.requested_at, t.confirmed_at
             FROM inventory_transfers t
             JOIN inventory_transfer_pallets tp ON tp.inventory_transfer_id = t.id
             JOIN pallets p ON p.id = tp.pallet_id
             WHERE p.production_order_id = ?
             GROUP BY t.id
             ORDER BY requested_at DESC, id DESC",
            [$orderId, $orderId],
        );
    }

    /**
     * Average minutes from the pallet closed to its receipt confirmed in SAP,
     * from the receipt to the approved count, and from the count to the
     * transfer confirmed in 05. Null while there is nothing to measure.
     *
     * @return array{to_receipt: ?int, to_count: ?int, to_transfer: ?int}
     */
    public function times(int $orderId): array
    {
        $row = DB::selectOne(
            'SELECT
                avg(extract(epoch FROM r.confirmed_at - p.finished_at)) AS to_receipt,
                avg(extract(epoch FROM a.completed_at - r.confirmed_at)) FILTER (WHERE a.id IS NOT NULL) AS to_count,
                avg(extract(epoch FROM t.confirmed_at - a.completed_at)) FILTER (WHERE t.id IS NOT NULL) AS to_transfer
             FROM pallets p
             JOIN production_receipt_pallets l ON l.pallet_id = p.id AND l.hold_status = ?
             JOIN production_receipts r ON r.id = l.production_receipt_id AND r.status = ?
             LEFT JOIN pallet_audits a ON a.pallet_id = p.id AND a.status = ?
             LEFT JOIN inventory_transfer_pallets tp ON tp.pallet_id = p.id AND tp.pallet_audit_id = a.id AND tp.hold_status = ?
             LEFT JOIN inventory_transfers t ON t.id = tp.inventory_transfer_id AND t.status = ?
             WHERE p.production_order_id = ?',
            [PalletHold::Confirmed->value, SapDocumentStatus::Confirmed->value, CountStatus::Approved->value, PalletHold::Confirmed->value, SapDocumentStatus::Confirmed->value, $orderId],
        );

        $minutes = fn (mixed $seconds): ?int => $seconds === null ? null : (int) round(((float) $seconds) / 60);

        return ['to_receipt' => $minutes($row->to_receipt ?? null), 'to_count' => $minutes($row->to_count ?? null), 'to_transfer' => $minutes($row->to_transfer ?? null)];
    }
}
