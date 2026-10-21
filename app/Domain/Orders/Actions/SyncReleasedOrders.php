<?php

namespace App\Domain\Orders\Actions;

use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Orders\OrderClosureWatch;
use App\Domain\Orders\ProductionOrderCache;
use App\Domain\Orders\SyncResult;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Brings the released production orders from SAP, on request (the board's
 * button): every order SAP has released is stored or updated here, and
 * every order released here that SAP no longer lists is read again one by
 * one, so a closed or cancelled order stops being released here too.
 *
 * Each order is stored in its own transaction. If SAP cannot be asked,
 * nothing changes and the board says so.
 */
class SyncReleasedOrders
{
    public function __construct(
        private readonly SapGateway $sap,
        private readonly ProductionOrderCache $cache,
        private readonly OrderClosureWatch $closure,
    ) {}

    public function handle(User $user, TraceContext $ctx): SyncResult
    {
        Gate::forUser($user)->authorize(Permission::OrdersView->value);

        try {
            $released = $this->sap->releasedProductionOrders();
        } catch (SapLookupFailed) {
            return new SyncResult(0, error: 'SAP no respondió. El tablero sigue con los datos de la última actualización.');
        }

        $problems = [];
        $store = function (SapProductionOrder $sapOrder) use ($user, $ctx, &$problems): void {
            try {
                $order = DB::transaction(fn () => $this->cache->store($sapOrder, $user, $ctx, TraceEventType::ProductionOrderRefreshed));
                $this->closure->check($order->id);
            } catch (BoxLabelRuleViolation $e) {
                $problems[$sapOrder->docNum] = $e->getMessage();
            }
        };

        foreach ($released as $sapOrder) {
            $store($sapOrder);
        }

        // Released here but no longer in SAP's list: closed, cancelled or gone. Ask for each one.
        $listed = array_map(fn (SapProductionOrder $o) => $o->docNum, $released);
        $stale = ProductionOrder::query()->where('sap_status', ProductionOrderStatus::Released->value)->whereNotIn('ref', $listed)->orderBy('id')->pluck('ref');

        foreach ($stale as $ref) {
            try {
                $sapOrder = $this->sap->findProductionOrder($ref);
            } catch (SapLookupFailed) {
                $problems[$ref] = 'SAP no respondió al preguntar por esta orden. Queda como estaba.';

                continue;
            }

            if ($sapOrder === null) {
                $problems[$ref] = 'SAP ya no tiene una orden con ese número. Queda como estaba para revisarla.';

                continue;
            }

            $store($sapOrder);
        }

        return new SyncResult(count($released), $problems);
    }
}
