<?php

namespace App\Domain\Orders\Actions;

use App\Domain\Receipts\ReceiptLimit;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\TraceLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The production supervisor lets an order that reached the receipt limit
 * take receipts again, for the rest of its life (it is not closed in SAP
 * because production goes on). Who, when and why stay on the order and in
 * its trace.
 */
class UnlockOrderReceipts
{
    public function __construct(private readonly ReceiptLimit $limit, private readonly TraceLogger $trace) {}

    public function handle(User $user, int $orderId, string $reason, TraceContext $ctx): ProductionOrder
    {
        Gate::forUser($user)->authorize(Permission::OrdersUnlockReceipts->value);

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Escribe por qué se habilitan de nuevo los recibos de esta OF.');
        }

        return DB::transaction(function () use ($user, $orderId, $reason, $ctx): ProductionOrder {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($orderId);
            $status = $this->limit->status($order);

            if (! $status['blocked']) {
                throw new DomainException($status['unlocked']
                    ? "La OF {$order->ref} ya tiene los recibos habilitados."
                    : "La OF {$order->ref} no ha llegado al límite de recibos: no hay nada que habilitar.");
            }

            $order->forceFill(['receipts_unlocked_at' => now(), 'receipts_unlocked_by' => $user->id, 'receipts_unlock_reason' => mb_substr($reason, 0, 300)])->save();

            $this->trace->record($ctx, TraceEventType::ProductionOrderReceiptsUnlocked, $order,
                from: ['receipts' => 'bloqueados'],
                to: ['receipts' => 'habilitados'],
                reason: $reason,
                context: ['limit_percent' => $status['limit'], 'percent' => $status['percent'], 'committed_micro' => $status['committed_micro'], 'planned_micro' => $status['planned_micro']],
            );

            return $order;
        });
    }
}
