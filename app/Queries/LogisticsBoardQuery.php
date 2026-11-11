<?php

namespace App\Queries;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferPurpose;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Models\DiscrepancyCase;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\PalletAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Transfer and count indicators for the logistics home page. Everything is
 * in pallets and documents, never boxes or units: the logistics operator
 * counts blind and must not see quantities before counting.
 */
class LogisticsBoardQuery
{
    private const PENDING = [SapDocumentStatus::Requested, SapDocumentStatus::Sending];

    /**
     * Transfers to the CEDI (04 → 05): done today, waiting for SAP, to reconcile, failed, and pallets ready to go.
     *
     * @return array{confirmed_docs: int, confirmed_pallets: int, pending_docs: int, pending_pallets: int, reconcile_docs: int, failed_pallets: int, ready_pallets: int, non_conforming_today: int, non_conforming_pending: int}
     */
    public function transfers(): array
    {
        $today = $this->today();
        $cedi = fn () => InventoryTransfer::query()->where('purpose', TransferPurpose::Cedi);
        $nonConforming = fn () => InventoryTransfer::query()->where('purpose', TransferPurpose::NonConforming);

        return [
            'confirmed_docs' => $cedi()->where('status', SapDocumentStatus::Confirmed)->where('confirmed_at', '>=', $today)->count(),
            'confirmed_pallets' => $this->palletsOf($cedi()->where('status', SapDocumentStatus::Confirmed)->where('confirmed_at', '>=', $today)),
            'pending_docs' => $cedi()->whereIn('status', self::PENDING)->count(),
            'pending_pallets' => $this->palletsOf($cedi()->whereIn('status', self::PENDING)),
            'reconcile_docs' => $cedi()->where('status', SapDocumentStatus::PendingReconciliation)->count(),
            'failed_pallets' => Pallet::query()->where('transfer_status', TransferStatus::Failed)->count(),
            'ready_pallets' => Pallet::query()->where('transfer_status', TransferStatus::Ready)->count(),
            'non_conforming_today' => $nonConforming()->where('status', SapDocumentStatus::Confirmed)->where('confirmed_at', '>=', $today)->count(),
            'non_conforming_pending' => $nonConforming()->whereIn('status', [...self::PENDING, SapDocumentStatus::PendingReconciliation])->count(),
        ];
    }

    /**
     * Blind counts: pallets received in 04 waiting to be counted, being counted, approved today, open discrepancies.
     *
     * @return array{waiting: int, waiting_oldest: CarbonImmutable|null, counting: int, approved_today: int, open_discrepancies: int}
     */
    public function counts(): array
    {
        $waiting = Pallet::query()->where('receipt_status', ReceiptStatus::Confirmed)->where('audit_status', AuditStatus::Pending);
        $oldest = (clone $waiting)->min('updated_at');

        return [
            'waiting' => (clone $waiting)->count(),
            'waiting_oldest' => $oldest === null ? null : CarbonImmutable::parse((string) $oldest),
            'counting' => Pallet::query()->where('audit_status', AuditStatus::Counting)->count(),
            'approved_today' => PalletAudit::query()->where('status', CountStatus::Approved)->where('completed_at', '>=', $this->today())->count(),
            'open_discrepancies' => DiscrepancyCase::query()->whereIn('status', [DiscrepancyStatus::Open, DiscrepancyStatus::InReview])->count(),
        ];
    }

    /**
     * Pallets transferred to the CEDI per day, the last 7 days (oldest first).
     *
     * @return list<array{day: CarbonImmutable, pallets: int}>
     */
    public function lastDays(int $days = 7): array
    {
        $from = $this->today()->subDays($days - 1);
        $byDay = DB::table('inventory_transfer_pallets as l')
            ->join('inventory_transfers as t', 't.id', '=', 'l.inventory_transfer_id')
            ->where('t.purpose', TransferPurpose::Cedi->value)
            ->where('t.status', SapDocumentStatus::Confirmed->value)
            ->where('t.confirmed_at', '>=', $from)
            ->selectRaw('(t.confirmed_at at time zone ?)::date as d, count(*) as n', [config('app.timezone')])
            ->groupBy('d')
            ->pluck('n', 'd')
            ->map(fn ($n) => (int) $n)
            ->all();

        return array_map(function (int $i) use ($from, $byDay): array {
            $day = $from->addDays($i);

            return ['day' => $day, 'pallets' => $byDay[$day->toDateString()] ?? 0];
        }, range(0, $days - 1));
    }

    /**
     * The latest transfer documents: number in SAP, purpose, pallets, state and when.
     *
     * @return list<array{doc: int|null, purpose: string, pallets: int, status: SapDocumentStatus, at: CarbonImmutable}>
     */
    public function recent(int $limit = 8): array
    {
        return array_values(InventoryTransfer::query()->withCount('lines')->latest('requested_at')->latest('id')->limit($limit)->get()
            ->map(fn (InventoryTransfer $t) => [
                'doc' => $t->sap_doc_num,
                'purpose' => $t->purpose === TransferPurpose::Cedi ? '04 → 05 CEDI' : '04 → 11C No conforme',
                'pallets' => (int) $t->getAttribute('lines_count'),
                'status' => $t->status,
                'at' => CarbonImmutable::instance($t->confirmed_at ?? $t->requested_at),
            ])->all());
    }

    /**
     * @param  Builder<InventoryTransfer>  $transfers
     */
    private function palletsOf(Builder $transfers): int
    {
        return (int) DB::table('inventory_transfer_pallets')->whereIn('inventory_transfer_id', $transfers->select('id'))->count();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config()->string('app.timezone'))->startOfDay();
    }
}
