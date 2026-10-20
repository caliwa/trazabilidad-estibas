<?php

namespace App\Notifications;

use App\Models\DiscrepancyCase;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the people who resolve discrepancies. Never to the operator
 * who counted: it names the system quantity.
 */
class DiscrepancyOpened extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $caseId) {}

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
        $case = DiscrepancyCase::query()->with('pallet:id,pallet_code,lot_code,production_order_ref')->findOrFail($this->caseId);

        return [
            'title' => 'Discrepancia en '.$case->pallet->pallet_code,
            'body' => 'Conteo ciego distinto a la cantidad del sistema. Orden '.$case->pallet->production_order_ref.', lote '.$case->pallet->lot_code.'. Traslado bloqueado.',
            'url' => route('discrepancies.index'),
            'icon' => 'exclamation-triangle',
            'level' => 'warning',
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)->subject($data['title'])->line($data['body'])->action('Revisar discrepancias', $data['url']);
    }
}
