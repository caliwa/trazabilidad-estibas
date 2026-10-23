<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Enums\Permission;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Looks a production order up by its number in this application's copy of
 * SAP, kept up to date by the order board's refresh ("Actualizar desde SAP"
 * or sap:sync-orders). It never calls SAP. Only a released order is
 * returned; anything else is refused with the reason.
 */
class ConsultProductionOrder
{
    public function handle(User $user, string $docNum): ProductionOrder
    {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        $docNum = trim($docNum);

        if (preg_match('/^\d{1,20}$/', $docNum) !== 1) {
            throw BoxLabelRuleViolation::invalidNumber();
        }

        $order = ProductionOrder::query()->with('item')->where('ref', $docNum)->first()
            ?? throw BoxLabelRuleViolation::notSynced($docNum);

        if ($order->sap_status !== ProductionOrderStatus::Released->value) {
            $status = ProductionOrderStatus::tryFrom((string) $order->sap_status)?->label() ?? (string) $order->sap_status;

            throw BoxLabelRuleViolation::notReleased($docNum, $status);
        }

        return $order;
    }
}
