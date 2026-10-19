<?php

use App\Domain\Pallets\Actions\ReprocessRejectedEvent;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\Exceptions\ScanIngestionDisabled;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Models\PalletScanEvent;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Rejected box reads, to find bad masters or misconfigured devices, and to
 * evaluate an event again once the cause was fixed.
 */
new #[Title('Lecturas rechazadas')] class extends Component {
    use WithPagination;

    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::PalletsView->value), 403);
    }

    #[Url(except: '')]
    public string $reason = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, PalletScanEvent>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        return PalletScanEvent::query()->with('device:id,code')
            ->where('processing_status', ScanEventStatus::Rejected)
            ->when(ScanRejection::tryFrom($this->reason), fn ($q, ScanRejection $r) => $q->where('rejection_reason', $r->value))
            ->latest('received_at')->latest('id')
            ->paginate(30);
    }

    public function reprocess(int $eventId, ReprocessRejectedEvent $reprocess): void
    {
        try {
            $result = $reprocess->handle(Auth::user(), $eventId, TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip()));
        } catch (PalletRuleViolation|ScanIngestionDisabled $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $result->outcome === ScanOutcome::Accepted
            ? Flux::toast(variant: 'success', text: "Lectura aceptada en la estiba {$result->palletCode}.")
            : Flux::toast(variant: 'warning', text: 'Sigue rechazada: '.($result->rejection?->label() ?? 'sin cambios').'.');

        unset($this->events);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Lecturas rechazadas</flux:heading>
        <flux:subheading>Cajas leídas que no se contaron, con su motivo</flux:subheading>
    </div>

    <div class="max-w-xs">
        <flux:select wire:model.live="reason" label="Motivo">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach (ScanRejection::cases() as $r)<flux:select.option :value="$r->value">{{ $r->label() }}</flux:select.option>@endforeach
        </flux:select>
    </div>

    @if ($this->events->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay lecturas rechazadas.</div>
    @else
        <flux:table :paginator="$this->events">
            <flux:table.columns>
                <flux:table.column>Recibida</flux:table.column>
                <flux:table.column>Dispositivo</flux:table.column>
                <flux:table.column>Evento</flux:table.column>
                <flux:table.column>Orden / producto / lote</flux:table.column>
                <flux:table.column>Motivo</flux:table.column>
                <flux:table.column><span class="sr-only">Acciones</span></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->events as $event)
                    <flux:table.row :key="$event->id">
                        <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $event->received_at->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</flux:table.cell>
                        <flux:table.cell>{{ $event->device->code }}</flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $event->external_event_id }}</flux:table.cell>
                        <flux:table.cell>{{ $event->production_order_ref }} · {{ $event->item_code }} · {{ $event->lot_code }}</flux:table.cell>
                        <flux:table.cell>{{ ScanRejection::tryFrom((string) $event->rejection_reason)?->label() ?? $event->rejection_reason }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @can('scans.reprocess')
                                <flux:button size="sm" icon="arrow-path" wire:click="reprocess({{ $event->id }})" wire:loading.attr="disabled" wire:target="reprocess({{ $event->id }})">Reprocesar</flux:button>
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
