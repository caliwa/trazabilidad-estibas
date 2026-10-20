<?php

use App\Domain\Operations\Alert;
use App\Domain\Operations\AlertLevel;
use App\Domain\Operations\Heartbeat;
use App\Domain\Operations\OperationalChecks;
use App\Enums\Permission;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What ops:check sees right now, plus the heartbeats. Refreshes on its own.
 */
new #[Title('Estado operativo')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::OpsAlerts->value), 403);
    }

    /**
     * @return list<Alert>
     */
    #[Computed]
    public function alerts(): array
    {
        return app(OperationalChecks::class)->run();
    }

    /**
     * @return array<string, \Carbon\CarbonImmutable|null>
     */
    #[Computed]
    public function heartbeats(): array
    {
        $beats = ['Scheduler' => Heartbeat::last(Heartbeat::SCHEDULER)];
        foreach (config()->array('operations.worker_queues') as $queue) {
            $beats["Worker cola {$queue}"] = Heartbeat::last(Heartbeat::worker($queue));
        }

        return $beats;
    }
}; ?>

<section class="flex w-full flex-col gap-6" wire:poll.30s.visible>
    <div>
        <flux:heading size="xl" level="1">Estado operativo</flux:heading>
        <flux:subheading>Se revisa cada 5 minutos y avisa a quienes tienen este permiso. Esta vista se refresca sola.</flux:subheading>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ($this->heartbeats as $name => $at)
            @php($fresh = $at !== null && $at->greaterThanOrEqualTo(now()->subMinutes(config('operations.heartbeat_max_age_minutes'))))
            <div class="rounded-xl border p-4 {{ $fresh ? 'border-zinc-200 dark:border-zinc-700' : 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950' }}">
                <flux:text class="text-xs">{{ $name }}</flux:text>
                <div class="mt-1 font-medium {{ $fresh ? '' : 'text-red-700 dark:text-red-300' }}">
                    {{ $at === null ? 'Sin señal' : 'Última señal '.$at->timezone(config('app.timezone'))->format('d/m H:i:s') }}
                </div>
            </div>
        @endforeach
    </div>

    @if ($this->alerts === [])
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Sin alertas activas.</div>
    @else
        <div class="flex flex-col gap-3">
            @foreach ($this->alerts as $alert)
                <div wire:key="alert-{{ $alert->key }}" class="flex items-start gap-3 rounded-xl border p-4 {{ $alert->level === AlertLevel::Critical ? 'border-red-300 dark:border-red-800' : 'border-amber-300 dark:border-amber-800' }}">
                    <flux:badge size="sm" :color="$alert->level === AlertLevel::Critical ? 'red' : 'amber'">{{ $alert->level->label() }}</flux:badge>
                    <div class="flex-1">
                        <div class="font-medium">{{ $alert->title }}</div>
                        <flux:text>{{ $alert->detail }}</flux:text>
                    </div>
                    @if ($alert->route !== null && Route::has($alert->route))
                        <flux:button size="sm" variant="ghost" :href="route($alert->route)" wire:navigate>Ver</flux:button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
