<?php

namespace App\Domain\Orders;

use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Notifications\OrderReadyToClose;
use App\Queries\OrderProgressQuery;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Sends the "ready to close in SAP" alert once per order, the first time
 * its confirmed receipts reach the planned quantity while SAP still has it
 * released. The application never closes the order: people do it in SAP.
 */
class OrderClosureWatch
{
    public function __construct(private readonly OrderProgressQuery $progress, private readonly TraceLogger $trace) {}

    public function check(int $orderId): void
    {
        $notify = DB::transaction(function () use ($orderId): bool {
            $order = ProductionOrder::query()->lockForUpdate()->find($orderId);

            if ($order === null || $order->ready_to_close_notified_at !== null || $order->sap_status !== ProductionOrderStatus::Released->value) {
                return false;
            }

            $progress = $this->progress->forOrder($orderId);

            if ($progress === null || ! $progress->stage()->closable()) {
                return false;
            }

            $order->forceFill(['ready_to_close_notified_at' => now()])->save();
            $this->trace->record(TraceContext::system(Origin::Job, (string) Str::uuid()), TraceEventType::ProductionOrderReadyToClose, $order, context: [
                'planned_quantity' => $order->planned_quantity,
                'received_micro' => $progress->receivedMicro,
                'stage' => $progress->stage()->value,
            ]);

            return true;
        });

        if ($notify) {
            Notification::send(User::permission(Permission::OrdersView->value)->active()->get(), new OrderReadyToClose($orderId));
        }
    }
}
