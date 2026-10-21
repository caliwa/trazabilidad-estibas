<?php

namespace App\Domain\Orders\Actions;

use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Orders\OrderClosureWatch;
use App\Domain\Orders\ProductionOrderCache;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reads production orders again from SAP, on request (the board's button):
 * status, planned and completed quantities and the rest of the header.
 * Each order is stored in its own transaction, so one that fails does not
 * hold back the others. An order SAP no longer has, or that could not be
 * read, keeps what it had and says why.
 */
class RefreshProductionOrders
{
    public function __construct(
        private readonly SapGateway $sap,
        private readonly ProductionOrderCache $cache,
        private readonly OrderClosureWatch $closure,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @return array<string, string> order ref => problem, only for the orders that could not be refreshed
     */
    public function handle(User $user, array $orderIds, TraceContext $ctx): array
    {
        Gate::forUser($user)->authorize(Permission::OrdersView->value);

        $problems = [];
        foreach (ProductionOrder::query()->whereKey($orderIds)->orderBy('id')->get(['id', 'ref']) as $order) {
            try {
                $sapOrder = $this->sap->findProductionOrder($order->ref);
            } catch (SapLookupFailed) {
                $problems[$order->ref] = 'SAP no respondió. Quedan los datos de la última actualización.';

                continue;
            }

            if ($sapOrder === null) {
                $problems[$order->ref] = 'SAP no tiene una orden con ese número.';

                continue;
            }

            try {
                DB::transaction(fn () => $this->cache->store($sapOrder, $user, $ctx, TraceEventType::ProductionOrderRefreshed));
            } catch (BoxLabelRuleViolation $e) {
                $problems[$order->ref] = $e->getMessage();

                continue;
            }

            $this->closure->check($order->id);
        }

        return $problems;
    }

    /**
     * Orders worth asking SAP about: everything not closed or cancelled there yet.
     *
     * @return list<int>
     */
    public static function openOrderIds(): array
    {
        return array_values(array_map('intval', ProductionOrder::query()
            ->where(fn ($q) => $q->whereNull('sap_status')->orWhereNotIn('sap_status', ['CERRADO', 'CANCELADO']))
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }
}
