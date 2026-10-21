<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Orders\ProductionOrderCache;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Exceptions\SapLookupFailed;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Looks a production order up in SAP by its number. When it exists and is
 * released, its header and its product are stored locally (production
 * orders and items are read caches of SAP) and the order is returned.
 * Anything else is refused with the reason, and nothing is stored.
 */
class ConsultProductionOrder
{
    public function __construct(private readonly SapGateway $sap, private readonly ProductionOrderCache $cache) {}

    public function handle(User $user, string $docNum, TraceContext $ctx): ProductionOrder
    {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        $docNum = trim($docNum);

        if (preg_match('/^\d{1,20}$/', $docNum) !== 1) {
            throw BoxLabelRuleViolation::invalidNumber();
        }

        try {
            $sapOrder = $this->sap->findProductionOrder($docNum);
        } catch (SapLookupFailed) {
            throw BoxLabelRuleViolation::sapUnavailable();
        }

        if ($sapOrder === null) {
            throw BoxLabelRuleViolation::notFound($docNum);
        }

        if ($sapOrder->status !== ProductionOrderStatus::Released) {
            throw BoxLabelRuleViolation::notReleased($docNum, $sapOrder->status->label());
        }

        return DB::transaction(fn () => $this->cache->store($sapOrder, $user, $ctx, TraceEventType::ProductionOrderConsulted));
    }
}
