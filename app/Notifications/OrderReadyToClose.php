<?php

namespace App\Notifications;

use App\Domain\Orders\Enums\OrderStage;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Queries\OrderProgressQuery;
use App\Support\Quantities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once per order to the people who follow the order board, when its
 * confirmed receipts cover the planned quantity: time to close it in SAP.
 */
class OrderReadyToClose extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $orderId) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->email_notifications ? ['database', 'mail'] : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        $order = ProductionOrder::query()->findOrFail($this->orderId);
        $progress = app(OrderProgressQuery::class)->forOrder($order->id);
        $uom = $order->uom_name ?? 'unidades';
        $received = Quantities::format($progress->receivedMicro ?? 0);
        $planned = Quantities::format(Quantities::toMicro($order->planned_quantity));
        $over = $progress?->stage() === OrderStage::OverProduced;

        return [
            'title' => "OF {$order->ref} lista para cerrar en SAP",
            'body' => "Recibido {$received} de {$planned} {$uom}.".($over ? ' Hay sobreproducción: revisar antes de cerrar.' : ' Ya se puede cerrar la orden en SAP.'),
            'url' => route('orders.show', $order),
            'icon' => 'check-circle',
            'level' => $over ? 'warning' : 'success',
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)->subject($data['title'])->line($data['body'])->action('Ver la orden', $data['url']);
    }
}
