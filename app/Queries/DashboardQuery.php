<?php

namespace App\Queries;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Audits\Enums\CountStatus;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Quality\Enums\QualityStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Models\DiscrepancyCase;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\PalletAudit;
use App\Models\PalletScanEvent;
use App\Models\ProductionReceipt;
use Carbon\CarbonImmutable;

/**
 * Management indicators of the home page for supervisors: today's
 * production against yesterday at the same time, where the pallets are
 * piling up along the flow, the open orders, CEDI and SAP. Read-only and
 * computed from what the database says now.
 */
class DashboardQuery
{
    /** Pending SAP documents: sent and not yet answered, or waiting for reconciliation. */
    private const PENDING = [SapDocumentStatus::Requested, SapDocumentStatus::Sending, SapDocumentStatus::PendingReconciliation];

    public function __construct(private readonly OrderBoardQuery $orders) {}

    /**
     * Today against yesterday up to the same time of day, so the comparison is fair at any hour.
     *
     * @return array{boxes: int, boxes_before: int, pallets: int, pallets_before: int, last_hour: int, hourly_average: int, stacking: int, stacking_percent: int, hours: list<array{hour: int, today: int, yesterday: int}>, days: list<array{date: CarbonImmutable, boxes: int}>}
     */
    public function production(): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $yesterday = $today->subDay();

        $boxes = $this->boxesBetween($today, $now);
        // The average pace counts from the first box of the day, not from midnight.
        $firstRead = PalletScanEvent::query()->where('processing_status', ScanEventStatus::Accepted)->where('received_at', '>=', $today)->min('received_at');
        $hoursSoFar = max(1, ($firstRead === null ? 0 : CarbonImmutable::parse((string) $firstRead)->diffInMinutes($now)) / 60);
        $stacking = Pallet::query()->where('physical_status', PhysicalStatus::Stacking);

        return [
            'boxes' => $boxes,
            'boxes_before' => $this->boxesBetween($yesterday, $now->subDay()),
            'pallets' => Pallet::query()->whereBetween('finished_at', [$today, $now])->count(),
            'pallets_before' => Pallet::query()->whereBetween('finished_at', [$yesterday, $now->subDay()])->count(),
            'last_hour' => $this->boxesBetween($now->subHour(), $now),
            'hourly_average' => (int) round($boxes / $hoursSoFar),
            'stacking' => (clone $stacking)->count(),
            'stacking_percent' => (int) round((float) ((clone $stacking)->where('standard_boxes_snapshot', '>', 0)
                ->selectRaw('avg(least(boxes_count::numeric / standard_boxes_snapshot, 1)) * 100 as p')->value('p') ?? 0)),
            'hours' => $this->hourly($today, $yesterday, $now),
            'days' => $this->daily($today->subDays(13), $now),
        ];
    }

    /**
     * Where the pallets are now, stage by stage in the order of the process,
     * with how long the oldest one has been waiting there.
     *
     * @return list<array{key: string, label: string, count: int, oldest: ?CarbonImmutable}>
     */
    public function flow(): array
    {
        $held = [QualityStatus::NonConformingRequested, QualityStatus::NonConformingPending, QualityStatus::NonConforming];
        $stages = [
            ['stacking', 'En estibado', fn ($q) => $q->where('physical_status', PhysicalStatus::Stacking), 'started_at'],
            ['finished', 'Por paletizar', fn ($q) => $q->where('physical_status', PhysicalStatus::Finished), 'finished_at'],
            ['palletized', 'Por etiquetar', fn ($q) => $q->where('physical_status', PhysicalStatus::Palletized), 'palletized_at'],
            ['labeled', 'Por pedir recibo', fn ($q) => $q->where('physical_status', PhysicalStatus::Labeled)->whereIn('receipt_status', [ReceiptStatus::None, ReceiptStatus::Failed]), 'labeled_at'],
            ['receipt', 'Recibo esperando a SAP', fn ($q) => $q->whereIn('receipt_status', [ReceiptStatus::Requested, ReceiptStatus::PendingReconciliation]), 'updated_at'],
            ['quality', 'Retenidas por calidad', fn ($q) => $q->whereIn('quality_status', $held), 'updated_at'],
            ['count', 'Por contar en CEDI', fn ($q) => $q->where('receipt_status', ReceiptStatus::Confirmed)->where('audit_status', AuditStatus::Pending)->whereNotIn('quality_status', $held), 'updated_at'],
            ['counting', 'En conteo o discrepancia', fn ($q) => $q->whereIn('audit_status', [AuditStatus::Counting, AuditStatus::Discrepancy]), 'updated_at'],
            ['transfer', 'Traslado a 05 pendiente', fn ($q) => $q->where('audit_status', AuditStatus::Approved)->whereIn('transfer_status', [TransferStatus::Ready, TransferStatus::Requested, TransferStatus::PendingReconciliation, TransferStatus::Failed]), 'updated_at'],
        ];

        return array_map(function (array $stage): array {
            [$key, $label, $filter, $since] = $stage;
            $row = $filter(Pallet::query())->selectRaw("count(*) as n, min({$since}) as oldest")->first();
            $oldest = $row?->getAttribute('oldest');

            return [
                'key' => $key,
                'label' => $label,
                'count' => (int) $row?->getAttribute('n'),
                'oldest' => $oldest === null ? null : CarbonImmutable::parse((string) $oldest),
            ];
        }, $stages);
    }

    /**
     * Released orders still in progress, the closest due date first.
     *
     * @return list<array{ref: string, item: string, due: ?CarbonImmutable, planned: ?int, read: int, received: int, transferred: int, percent: int, stage: OrderStage}> percent is of the plan, over 100 when more was read
     */
    public function openOrders(int $limit = 6): array
    {
        return $this->orders->rows()
            ->filter(fn (array $row) => ! in_array($row['progress']->stage(), [OrderStage::Closed, OrderStage::ReadyToClose], true))
            ->sortBy(fn (array $row) => $row['order']->due_date->timestamp ?? PHP_INT_MAX)
            ->take($limit)
            ->map(fn (array $row) => [
                'ref' => (string) $row['order']->ref,
                'item' => trim($row['order']->item->code.' · '.$row['order']->item->description),
                'due' => $row['order']->due_date === null ? null : CarbonImmutable::parse($row['order']->due_date->toDateString()),
                'planned' => $row['progress']->plannedBoxes(),
                'read' => $row['progress']->readBoxes,
                'received' => $row['progress']->receivedBoxes,
                'transferred' => $row['progress']->transferredBoxes,
                // Real share of the plan: over 100 when more boxes were read than planned.
                'percent' => ($planned = $row['progress']->plannedBoxes()) === null || $planned === 0 ? 0 : (int) round($row['progress']->readBoxes * 100 / $planned),
                'stage' => $row['progress']->stage(),
            ])->values()->all();
    }

    /**
     * Blind counts today, how often the count matches at the first try (last
     * 7 days), open discrepancies and pallets waiting to be counted.
     *
     * @return array{approved: int, discrepancies: int, first_try_rate: ?int, first_try_total: int, open_discrepancies: int, waiting: int, waiting_oldest: ?CarbonImmutable, held: int}
     */
    public function cedi(): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $completedToday = PalletAudit::query()->where('completed_at', '>=', $today);

        // First audits of the last 7 days that finished, and those approved with a single try.
        $first = PalletAudit::query()->where('attempt', 1)->where('completed_at', '>=', $today->subDays(6))
            ->whereIn('status', [CountStatus::Approved, CountStatus::Discrepancy]);
        $firstTotal = (clone $first)->count();
        $firstTry = (clone $first)->where('status', CountStatus::Approved)
            ->whereRaw('(select count(*) from pallet_audit_tries t where t.pallet_audit_id = pallet_audits.id) <= 1')->count();

        $waiting = Pallet::query()->where('receipt_status', ReceiptStatus::Confirmed)->where('audit_status', AuditStatus::Pending);

        return [
            'approved' => (clone $completedToday)->where('status', CountStatus::Approved)->count(),
            'discrepancies' => (clone $completedToday)->where('status', CountStatus::Discrepancy)->count(),
            'first_try_rate' => $firstTotal === 0 ? null : (int) round($firstTry * 100 / $firstTotal),
            'first_try_total' => $firstTotal,
            'open_discrepancies' => DiscrepancyCase::query()->whereIn('status', [DiscrepancyStatus::Open, DiscrepancyStatus::InReview])->count(),
            'waiting' => (clone $waiting)->count(),
            'waiting_oldest' => ($at = (clone $waiting)->min('updated_at')) === null ? null : CarbonImmutable::parse((string) $at),
            'held' => Pallet::query()->whereIn('quality_status', [QualityStatus::NonConformingRequested, QualityStatus::NonConformingPending, QualityStatus::NonConforming])->count(),
        ];
    }

    /**
     * SAP documents today and those still without an answer.
     *
     * @return array{receipts_confirmed: int, receipts_pending: int, receipts_failed: int, transfers_confirmed: int, transfers_pending: int, transfers_failed: int}
     */
    public function sap(): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        return [
            'receipts_confirmed' => ProductionReceipt::query()->where('status', SapDocumentStatus::Confirmed)->where('confirmed_at', '>=', $today)->count(),
            'receipts_pending' => ProductionReceipt::query()->whereIn('status', self::PENDING)->count(),
            'receipts_failed' => ProductionReceipt::query()->where('status', SapDocumentStatus::Failed)->where('failed_at', '>=', $today)->count(),
            'transfers_confirmed' => InventoryTransfer::query()->where('status', SapDocumentStatus::Confirmed)->where('confirmed_at', '>=', $today)->count(),
            'transfers_pending' => InventoryTransfer::query()->whereIn('status', self::PENDING)->count(),
            'transfers_failed' => InventoryTransfer::query()->where('status', SapDocumentStatus::Failed)->where('failed_at', '>=', $today)->count(),
        ];
    }

    private function boxesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return PalletScanEvent::query()->where('processing_status', ScanEventStatus::Accepted)->whereBetween('received_at', [$from, $to])->count();
    }

    /**
     * Boxes per hour of the day, today and yesterday, from the first hour with
     * reads (or 6 a.m.) to the current hour.
     *
     * @return list<array{hour: int, today: int, yesterday: int}>
     */
    private function hourly(CarbonImmutable $today, CarbonImmutable $yesterday, CarbonImmutable $now): array
    {
        $byHour = fn (CarbonImmutable $from, CarbonImmutable $to) => PalletScanEvent::query()
            ->where('processing_status', ScanEventStatus::Accepted)->whereBetween('received_at', [$from, $to])
            ->selectRaw('extract(hour from received_at at time zone ?)::int as h, count(*) as n', [config('app.timezone')])
            ->groupBy('h')->pluck('n', 'h')->map(fn ($n) => (int) $n)->all();

        $todays = $byHour($today, $now);
        $yesterdays = $byHour($yesterday, $today);
        $first = min(6, ...array_map('intval', array_keys($todays + $yesterdays) ?: [6]));

        return array_map(fn (int $h) => ['hour' => $h, 'today' => $todays[$h] ?? 0, 'yesterday' => $yesterdays[$h] ?? 0], range($first, max($first, $now->hour)));
    }

    /**
     * Boxes read per day, the last 14 days.
     *
     * @return list<array{date: CarbonImmutable, boxes: int}>
     */
    private function daily(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $byDay = PalletScanEvent::query()->where('processing_status', ScanEventStatus::Accepted)->whereBetween('received_at', [$from, $now])
            ->selectRaw('(received_at at time zone ?)::date as d, count(*) as n', [config('app.timezone')])
            ->groupBy('d')->pluck('n', 'd')->map(fn ($n) => (int) $n)->all();

        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($now); $day = $day->addDay()) {
            $days[] = ['date' => $day, 'boxes' => $byDay[$day->toDateString()] ?? 0];
        }

        return $days;
    }
}
