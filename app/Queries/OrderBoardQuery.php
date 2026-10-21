<?php

namespace App\Queries;

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Orders\OrderProgress;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Production order board. Only orders released in SAP are on it: planned,
 * closed and cancelled ones, and orders nobody looked up in SAP yet, stay
 * off until a refresh from SAP says they are released.
 *
 * The order list is filtered in SQL; the stage and
 * the alerts come from each order's progress, so filtering by them and
 * sorting by urgency happen after computing it (an order list is in the
 * hundreds, not the millions).
 */
class OrderBoardQuery
{
    /** Most urgent first. */
    private const STAGE_ORDER = [
        OrderStage::OverProduced->value => 0,
        OrderStage::ReadyToClose->value => 1,
        OrderStage::InProgress->value => 2,
        OrderStage::NotConsulted->value => 3,
        OrderStage::Closed->value => 4,
    ];

    public function __construct(private readonly OrderProgressQuery $progress) {}

    /**
     * @return LengthAwarePaginator<int, array{order: ProductionOrder, progress: OrderProgress}>
     */
    public function paginate(string $search, string $stage, bool $onlyAlerts, int $page, int $perPage = 25): LengthAwarePaginator
    {
        $like = '%'.addcslashes(trim($search), '%_\\').'%';

        $orders = $this->released()
            ->with('item:id,code,description')
            ->when(trim($search) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('ref', 'ilike', $like)
                ->orWhereHas('item', fn ($i) => $i->where('code', 'ilike', $like)->orWhere('description', 'ilike', $like))))
            ->get();

        $progress = $this->progress->for(array_values(array_map('intval', $orders->modelKeys())));

        $rows = $orders
            ->map(fn (ProductionOrder $order) => ['order' => $order, 'progress' => $progress[$order->id]])
            ->filter(fn (array $row) => $stage === '' || $row['progress']->stage()->value === $stage)
            ->filter(fn (array $row) => ! $onlyAlerts || $row['progress']->alerts() !== [])
            ->sort(fn (array $a, array $b) => [self::STAGE_ORDER[$a['progress']->stage()->value], $a['order']->due_date->timestamp ?? PHP_INT_MAX, -$a['order']->id]
                <=> [self::STAGE_ORDER[$b['progress']->stage()->value], $b['order']->due_date->timestamp ?? PHP_INT_MAX, -$b['order']->id])
            ->values();

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => request()->url(), 'pageName' => 'page']);
    }

    /**
     * @return Builder<ProductionOrder>
     */
    private function released(): Builder
    {
        return ProductionOrder::query()->where('sap_status', ProductionOrderStatus::Released->value);
    }

    /**
     * Counters for the top of the board: orders by stage and today's movement.
     *
     * @return array{released: int, ready: int, over: int, alerts: int, boxes_today: int, received_today: int, transferred_today: int}
     */
    public function indicators(): array
    {
        $progress = $this->progress->for(array_values(array_map('intval', $this->released()->pluck('id')->all())));
        $start = now()->startOfDay();

        return [
            'released' => count($progress),
            'ready' => count(array_filter($progress, fn (OrderProgress $p) => $p->stage() === OrderStage::ReadyToClose)),
            'over' => count(array_filter($progress, fn (OrderProgress $p) => $p->stage() === OrderStage::OverProduced)),
            'alerts' => count(array_filter($progress, fn (OrderProgress $p) => $p->alerts() !== [])),
            'boxes_today' => DB::table('pallet_scan_events')->where('processing_status', ScanEventStatus::Accepted->value)->where('event_occurred_at', '>=', $start)->count(),
            'received_today' => DB::table('production_receipt_pallets')->join('production_receipts', 'production_receipts.id', '=', 'production_receipt_pallets.production_receipt_id')
                ->where('production_receipts.status', SapDocumentStatus::Confirmed->value)->where('production_receipts.confirmed_at', '>=', $start)->count(),
            'transferred_today' => DB::table('inventory_transfer_pallets')->join('inventory_transfers', 'inventory_transfers.id', '=', 'inventory_transfer_pallets.inventory_transfer_id')
                ->where('inventory_transfers.status', SapDocumentStatus::Confirmed->value)->where('inventory_transfers.confirmed_at', '>=', $start)->count(),
        ];
    }
}
