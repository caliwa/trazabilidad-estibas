<?php

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * In-app notifications: menu entry with the unread count (refreshed every
 * minute) and a side panel with the latest notifications.
 */
new class extends Component {
    public bool $showAll = false;

    #[Computed]
    public function unread(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function items(): Collection
    {
        return Auth::user()->notifications()
            ->when(! $this->showAll, fn ($query) => $query->whereNull('read_at'))
            ->limit(30)
            ->get();
    }

    public function openItem(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $this->redirect((string) ($notification->data['url'] ?? route('dashboard')), navigate: true);
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->unread, $this->items);
    }
}; ?>

<div wire:poll.60s>
    <flux:modal.trigger name="notifications">
        <flux:sidebar.item icon="bell" as="button" :badge="$this->unread ?: null" badge-color="red">
            Notificaciones
        </flux:sidebar.item>
    </flux:modal.trigger>

    <flux:modal name="notifications" flyout class="w-full max-w-md">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-3 pe-8">
                <flux:heading size="lg">Notificaciones</flux:heading>
                @if ($this->unread > 0)
                    <flux:button size="sm" variant="ghost" wire:click="markAllAsRead">Marcar todas como leídas</flux:button>
                @endif
            </div>

            <div class="flex gap-4 text-sm">
                <flux:checkbox wire:model.live="showAll" label="Mostrar también las leídas" />
            </div>

            @if ($this->items->isEmpty())
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                    {{ $showAll ? 'No tienes notificaciones.' : 'Estás al día: no hay notificaciones sin leer.' }}
                </div>
            @else
                <ul class="flex flex-col gap-2">
                    @foreach ($this->items as $item)
                        @php
                            $warning = ($item->data['level'] ?? 'info') === 'warning';
                        @endphp
                        <li wire:key="notification-{{ $item->id }}">
                            <button type="button" wire:click="openItem('{{ $item->id }}')"
                                @class([
                                    'flex w-full gap-3 rounded-lg border p-3 text-start transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50',
                                    'border-red-200 dark:border-red-500/40' => $warning,
                                    'border-zinc-200 dark:border-zinc-700' => ! $warning,
                                    'opacity-70' => $item->read_at !== null,
                                ])>
                                <flux:icon :name="$item->data['icon'] ?? 'bell'" variant="mini"
                                    @class(['mt-0.5 shrink-0', 'text-red-600 dark:text-red-400' => $warning, 'text-zinc-500' => ! $warning]) />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span class="font-medium text-zinc-800 dark:text-white">{{ $item->data['title'] ?? 'Notificación' }}</span>
                                        @if ($item->read_at === null)
                                            <span class="size-2 shrink-0 rounded-full bg-blue-600 dark:bg-blue-500"></span>
                                            <span class="sr-only">Sin leer</span>
                                        @endif
                                    </span>
                                    <span class="block text-sm text-zinc-600 dark:text-zinc-300">{{ $item->data['body'] ?? '' }}</span>
                                    <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">{{ $item->created_at?->diffForHumans() }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </flux:modal>
</div>
