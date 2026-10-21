<?php

namespace App\Queries;

use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Orders\OrderProgress;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * KPI board of production orders released in SAP. Planned, closed and
 * cancelled orders stay off. The stage comes from each order's progress,
 * so filtering by it and sorting by urgency happen after computing it.
 */
class OrderBoardQuery
{
    /** Most urgent first. */
    private const STAGE_ORDER = [
        OrderStage::OverProduced->value => 0,
        OrderStage::ReadyToClose->value => 1,
        OrderStage::InProgress->value => 2,
        OrderStage::NoProgress->value => 3,
        OrderStage::Closed->value => 4,
    ];

    public function __construct(private readonly OrderProgressQuery $progress) {}

    /**
     * @return Collection<int, array{order: ProductionOrder, progress: OrderProgress}>
     */
    public function rows(string $search = '', string $stage = ''): Collection
    {
        $like = '%'.addcslashes(trim($search), '%_\\').'%';

        $orders = $this->released()
            ->with('item:id,code,description')
            ->when(trim($search) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('ref', 'ilike', $like)
                ->orWhereHas('item', fn ($i) => $i->where('code', 'ilike', $like)->orWhere('description', 'ilike', $like))))
            ->get();

        $progress = $this->progress->for(array_values(array_map('intval', $orders->modelKeys())));

        return $orders
            ->map(fn (ProductionOrder $order) => ['order' => $order, 'progress' => $progress[$order->id]])
            ->filter(fn (array $row) => $stage === '' || $row['progress']->stage()->value === $stage)
            ->sort(fn (array $a, array $b) => [self::STAGE_ORDER[$a['progress']->stage()->value], $a['order']->due_date->timestamp ?? PHP_INT_MAX, -$a['order']->id]
                <=> [self::STAGE_ORDER[$b['progress']->stage()->value], $b['order']->due_date->timestamp ?? PHP_INT_MAX, -$b['order']->id])
            ->values();
    }

    /**
     * KPIs over every released order. Units are added up only across orders
     * that share the unit, which is why they are grouped by unit.
     *
     * @return array{orders: int, ready: int, over: int, no_progress: int, in_cedi: int, pending_in_sap: int, units: array<string, array{planned: int, received: int, transferred: int}>}
     */
    public function kpis(): array
    {
        $rows = $this->rows();
        $kpis = ['orders' => $rows->count(), 'ready' => 0, 'over' => 0, 'no_progress' => 0, 'in_cedi' => 0, 'pending_in_sap' => 0, 'units' => []];

        foreach ($rows as ['order' => $order, 'progress' => $p]) {
            $stage = $p->stage();
            $kpis['ready'] += (int) ($stage === OrderStage::ReadyToClose);
            $kpis['over'] += (int) ($stage === OrderStage::OverProduced);
            $kpis['no_progress'] += (int) ($stage === OrderStage::NoProgress);
            $kpis['in_cedi'] += (int) ($stage->closable() && $p->allInCedi());
            $kpis['pending_in_sap'] += (int) ($p->pendingInSap > 0);

            $uom = $order->uom_name ?? 'UNIDAD';
            $kpis['units'][$uom] ??= ['planned' => 0, 'received' => 0, 'transferred' => 0];
            $kpis['units'][$uom]['planned'] += (int) $p->plannedMicro;
            $kpis['units'][$uom]['received'] += $p->receivedMicro;
            $kpis['units'][$uom]['transferred'] += $p->transferredMicro;
        }

        return $kpis;
    }

    /**
     * @return Builder<ProductionOrder>
     */
    private function released(): Builder
    {
        return ProductionOrder::query()->where('sap_status', ProductionOrderStatus::Released->value);
    }
}
