<?php

namespace App\Notifications;

use App\Domain\Operations\Alert;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent synchronously on purpose: one of the alerts is "no worker running",
 * and a queued notification about it would never leave.
 */
class OperationalAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly Alert $alert) {}

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
        return [
            'title' => $this->alert->title,
            'body' => $this->alert->detail,
            'url' => route('operations.index'),
            'icon' => 'bell-alert',
            'level' => $this->alert->level->value === 'critical' ? 'danger' : 'warning',
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('['.$this->alert->level->label().'] '.$this->alert->title)
            ->line($this->alert->detail)
            ->action('Ver estado operativo', route('operations.index'));
    }
}
