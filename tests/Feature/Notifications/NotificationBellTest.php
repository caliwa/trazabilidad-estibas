<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private function notification(string $title, string $url): Notification
    {
        return new class($title, $url) extends Notification
        {
            public function __construct(private string $title, private string $url) {}

            /**
             * @return list<string>
             */
            public function via(object $notifiable): array
            {
                return ['database'];
            }

            /**
             * @return array<string, string>
             */
            public function toArray(object $notifiable): array
            {
                return ['title' => $this->title, 'body' => 'Detalle', 'url' => $this->url];
            }
        };
    }

    public function test_bell_lists_unread_notifications_and_marks_them_read(): void
    {
        $user = User::factory()->create();
        $user->notifyNow($this->notification('Discrepancia EST-00000001', route('dashboard')));
        $user->notifyNow($this->notification('Discrepancia EST-00000002', route('dashboard')));

        $bell = Livewire::actingAs($user)->test('notifications.bell')
            ->assertSet('showAll', false)
            ->assertSee('Discrepancia EST-00000001');

        $this->assertSame(2, $bell->instance()->unread);

        $first = $user->unreadNotifications()->first();
        $bell->call('openItem', $first->id)->assertRedirect(route('dashboard'));
        $this->assertSame(1, $user->unreadNotifications()->count());

        $bell->call('markAllAsRead');
        $this->assertSame(0, $user->unreadNotifications()->count());
        $bell->assertSee('Estás al día');
    }

    public function test_bell_is_in_the_menu(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->assertSee('Notificaciones');
    }

    public function test_people_only_open_their_own_notifications(): void
    {
        $owner = User::factory()->create();
        $owner->notifyNow($this->notification('Privada', route('dashboard')));

        Livewire::actingAs(User::factory()->create())->test('notifications.bell')
            ->call('openItem', $owner->notifications()->first()->id)
            ->assertNotFound();
    }
}
