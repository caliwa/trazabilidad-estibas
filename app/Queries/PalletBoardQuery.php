<?php

namespace App\Queries;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferPurpose;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Models\Pallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Administrative pallet board: filters, sorting and paging in SQL, with
 * the item description and the confirmed SAP document numbers joined in a
 * constant number of queries.
 */
class PalletBoardQuery
{
    /**
     * @return LengthAwarePaginator<int, Pallet>
     */
    public function paginate(PalletFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->select('pallets.*')
            ->addSelect([
                'item_description' => DB::table('items')->select('description')->whereColumn('items.id', 'pallets.item_id'),
                'receipt_doc_num' => $this->confirmedDocNum('production_receipt_pallets', 'production_receipts', 'production_receipt_id'),
                'transfer_doc_num' => $this->confirmedDocNum('inventory_transfer_pallets', 'inventory_transfers', 'inventory_transfer_id'),
            ])
            ->orderByDesc('pallets.started_at')
            ->orderByDesc('pallets.id')
            ->paginate($perPage);
    }

    /**
     * @return array{in_process: int, finished: int, labeled: int, received: int, transferred: int, receipts_pending: int, transfers_pending: int, discrepancies_open: int}
     */
    public function indicators(): array
    {
        $pending = SapDocumentStatus::PendingReconciliation->value;

        $row = DB::selectOne(
            'SELECT
                count(*) FILTER (WHERE physical_status = ?) AS in_process,
                count(*) FILTER (WHERE physical_status <> ?) AS finished,
                count(*) FILTER (WHERE physical_status = ?) AS labeled,
                count(*) FILTER (WHERE receipt_status = ?) AS received,
                count(*) FILTER (WHERE transfer_status = ?) AS transferred,
                (SELECT count(*) FROM production_receipts WHERE status = ?) AS receipts_pending,
                (SELECT count(*) FROM inventory_transfers WHERE status = ?) AS transfers_pending,
                (SELECT count(*) FROM discrepancy_cases WHERE status <> ?) AS discrepancies_open
             FROM pallets',
            [
                PhysicalStatus::Stacking->value, PhysicalStatus::Stacking->value, PhysicalStatus::Labeled->value,
                ReceiptStatus::Confirmed->value, TransferStatus::Confirmed->value, $pending, $pending, DiscrepancyStatus::Resolved->value,
            ],
        );

        $value = fn (string $key): int => (int) ($row->{$key} ?? 0);

        return [
            'in_process' => $value('in_process'),
            'finished' => $value('finished'),
            'labeled' => $value('labeled'),
            'received' => $value('received'),
            'transferred' => $value('transferred'),
            'receipts_pending' => $value('receipts_pending'),
            'transfers_pending' => $value('transfers_pending'),
            'discrepancies_open' => $value('discrepancies_open'),
        ];
    }

    /**
     * @return Builder<Pallet>
     */
    private function filtered(PalletFilters $f): Builder
    {
        $like = fn (string $value): string => '%'.addcslashes(trim($value), '\\%_').'%';

        return Pallet::query()
            ->when(trim($f->code) !== '', fn (Builder $q) => $q->where('pallets.pallet_code', 'ilike', $like($f->code)))
            ->when(trim($f->order) !== '', fn (Builder $q) => $q->where('pallets.production_order_ref', 'ilike', $like($f->order)))
            ->when(trim($f->item) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('pallets.item_code', 'ilike', $like($f->item))
                ->orWhereExists(fn (QueryBuilder $e) => $e->from('items')->whereColumn('items.id', 'pallets.item_id')->where('items.description', 'ilike', $like($f->item)))))
            ->when(trim($f->lot) !== '', fn (Builder $q) => $q->where('pallets.lot_code', 'ilike', $like($f->lot)))
            ->when(PhysicalStatus::tryFrom($f->physical), fn (Builder $q, PhysicalStatus $s) => $q->where('pallets.physical_status', $s))
            ->when(ReceiptStatus::tryFrom($f->receipt), fn (Builder $q, ReceiptStatus $s) => $q->where('pallets.receipt_status', $s))
            ->when(AuditStatus::tryFrom($f->audit), fn (Builder $q, AuditStatus $s) => $q->where('pallets.audit_status', $s))
            ->when(TransferStatus::tryFrom($f->transfer), fn (Builder $q, TransferStatus $s) => $q->where('pallets.transfer_status', $s))
            ->when($this->date($f->from), fn (Builder $q, string $d) => $q->where('pallets.started_at', '>=', $d.' 00:00:00'))
            ->when($this->date($f->to), fn (Builder $q, string $d) => $q->where('pallets.started_at', '<=', $d.' 23:59:59.999999'))
            ->when(ctype_digit(trim($f->sapDoc)), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereExists($this->documentWithNumber('production_receipt_pallets', 'production_receipts', 'production_receipt_id', (int) $f->sapDoc))
                ->orWhereExists($this->documentWithNumber('inventory_transfer_pallets', 'inventory_transfers', 'inventory_transfer_id', (int) $f->sapDoc))));
    }

    private function confirmedDocNum(string $lines, string $documents, string $foreignKey): QueryBuilder
    {
        return DB::table($lines)
            ->join($documents, "{$documents}.id", '=', "{$lines}.{$foreignKey}")
            ->whereColumn("{$lines}.pallet_id", 'pallets.id')
            ->where("{$lines}.hold_status", PalletHold::Confirmed->value)
            // The board's "Traslado" is the one to the CEDI, not the one to the non-conforming warehouse.
            ->when($lines === 'inventory_transfer_pallets', fn ($q) => $q->where("{$lines}.purpose", TransferPurpose::Cedi->value))
            ->select("{$documents}.sap_doc_num")
            ->limit(1);
    }

    private function documentWithNumber(string $lines, string $documents, string $foreignKey, int $docNum): \Closure
    {
        return fn (QueryBuilder $e) => $e->from($lines)
            ->join($documents, "{$documents}.id", '=', "{$lines}.{$foreignKey}")
            ->whereColumn("{$lines}.pallet_id", 'pallets.id')
            ->where("{$documents}.sap_doc_num", $docNum);
    }

    private function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}
