<?php

use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Queries\OrderBoardQuery;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * KPI board of the production orders released in SAP B1: how far each one
 * got with confirmed receipts and how much of it is already in the CEDI.
 * SAP is only read when someone presses "Actualizar desde SAP".
 */
new #[Title('Tablero de OF')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::OrdersView->value), 403);
    }

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $stage = '';

    /** @var array<string, string> */
    public array $problems = [];

    public function syncFromSap(): void
    {
        $result = app(SyncReleasedOrders::class)->handle(Auth::user(), TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip()));
        $this->problems = $result->problems;

        if ($result->error !== null) {
            Flux::toast(variant: 'danger', text: $result->error);
        } else {
            Flux::toast(variant: $result->problems === [] ? 'success' : 'warning', text: "SAP tiene {$result->released} órdenes liberadas. Tablero actualizado.");
        }

        unset($this->rows, $this->kpis);
    }

    /**
     * @return Collection<int, array{order: \App\Models\ProductionOrder, progress: \App\Domain\Orders\OrderProgress}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(OrderBoardQuery::class)->rows($this->search, $this->stage);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function kpis(): array
    {
        return app(OrderBoardQuery::class)->kpis();
    }
}; ?>

@php
    use App\Support\Quantities;
    $kpis = $this->kpis;
    $pct = fn (int $part, int $whole) => $whole === 0 ? 0 : (int) min(100, intdiv($part * 100, $whole));
    $lastSync = \App\Models\ProductionOrder::query()->where('sap_status', 'LIBERADO')->max('synced_at');
@endphp

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">Tablero de OF</flux:heading>
            <flux:subheading>Órdenes de fabricación liberadas en SAP B1: avance por recibos y transferencia a CEDI</flux:subheading>
        </div>
        <div class="text-end">
            <flux:button icon="arrow-path" wire:click="syncFromSap" wire:loading.attr="disabled" wire:target="syncFromSap">
                <span wire:loading.remove wire:target="syncFromSap">Actualizar desde SAP</span>
                <span wire:loading wire:target="syncFromSap">Consultando SAP…</span>
            </flux:button>
            <div class="mt-1 text-xs text-zinc-500">Última actualización: {{ $lastSync ? \Carbon\CarbonImmutable::parse($lastSync)->timezone(config('app.timezone'))->format('d/m/Y H:i') : 'nunca' }}</div>
        </div>
    </div>

    @if ($problems !== [])
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Algunas órdenes no se pudieron actualizar">
            <flux:callout.text>
                <ul class="list-disc ps-5">
                    @foreach ($problems as $ref => $problem)<li><strong>OF {{ $ref }}:</strong> {{ $problem }}</li>@endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- KPIs --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">OF liberadas</div>
            <div class="mt-1 text-4xl font-semibold tabular-nums">{{ $kpis['orders'] }}</div>
            <div class="mt-2 text-xs text-zinc-500">{{ $kpis['no_progress'] }} sin avance</div>
        </div>
        @foreach ($kpis['units'] as $uom => $u)
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Recibido en 04</div>
                <div class="mt-1 text-4xl font-semibold tabular-nums">{{ $pct($u['received'], $u['planned']) }}%</div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full bg-blue-500" style="width: {{ $pct($u['received'], $u['planned']) }}%"></div></div>
                <div class="mt-2 text-xs text-zinc-500 tabular-nums">{{ Quantities::format($u['received']) }} de {{ Quantities::format($u['planned']) }} {{ $uom }}</div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Transferido a CEDI</div>
                <div class="mt-1 text-4xl font-semibold tabular-nums">{{ $pct($u['transferred'], $u['planned']) }}%</div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full bg-violet-500" style="width: {{ $pct($u['transferred'], $u['planned']) }}%"></div></div>
                <div class="mt-2 text-xs text-zinc-500 tabular-nums">{{ Quantities::format($u['transferred']) }} de {{ Quantities::format($u['planned']) }} {{ $uom }}</div>
            </div>
        @endforeach
        <div @class(['rounded-xl border p-5', 'border-green-400 bg-green-50 dark:border-green-700 dark:bg-green-950' => $kpis['ready'] + $kpis['over'] > 0, 'border-zinc-200 dark:border-zinc-700' => $kpis['ready'] + $kpis['over'] === 0])>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Listas para cerrar en SAP</div>
            <div class="mt-1 text-4xl font-semibold tabular-nums">{{ $kpis['ready'] + $kpis['over'] }}</div>
            <div class="mt-2 text-xs text-zinc-500">{{ $kpis['in_cedi'] }} ya completas en CEDI @if ($kpis['over'] > 0)· <span class="text-red-600">{{ $kpis['over'] }} con sobreproducción</span>@endif</div>
        </div>
    </div>

    @if ($kpis['pending_in_sap'] > 0)
        <flux:callout variant="warning" icon="clock">{{ $kpis['pending_in_sap'] }} OF con recibos o transferencias pendientes de conciliar con SAP. Esas cantidades todavía no suman.</flux:callout>
    @endif

    <div class="grid items-end gap-3 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.400ms="search" label="Buscar" placeholder="OF, código o descripción" clearable />
        <flux:select wire:model.live="stage" label="Estado">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach ([OrderStage::NoProgress, OrderStage::InProgress, OrderStage::ReadyToClose, OrderStage::OverProduced] as $s)<flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>@endforeach
        </flux:select>
    </div>

    <div wire:loading.class="opacity-60" class="transition-opacity">
        @if ($this->rows->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay órdenes liberadas con esos filtros. Usa «Actualizar desde SAP» para traerlas.</div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>OF</flux:table.column>
                    <flux:table.column>Producto</flux:table.column>
                    <flux:table.column align="end">Planificado</flux:table.column>
                    <flux:table.column>Recibido en 04</flux:table.column>
                    <flux:table.column>Transferido a CEDI</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->rows as ['order' => $order, 'progress' => $p])
                        @php($stage = $p->stage())
                        <flux:table.row :key="$order->id">
                            <flux:table.cell>
                                <span class="font-mono font-semibold">{{ $order->ref }}</span>
                                @if ($order->due_date)<div class="text-xs text-zinc-500">Fin {{ $order->due_date->format('d/m/Y') }}</div>@endif
                            </flux:table.cell>
                            <flux:table.cell class="max-w-64 whitespace-normal"><div>{{ $order->item->code }}</div><div class="text-xs text-zinc-500">{{ $order->item->description }}</div></flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap tabular-nums">{{ Quantities::format((int) $p->plannedMicro) }} <span class="text-xs text-zinc-500">{{ $order->uom_name }}</span></flux:table.cell>
                            @foreach ([[$p->receivedPercent(), $p->receivedMicro, 'bg-blue-500'], [$p->transferredPercent(), $p->transferredMicro, 'bg-violet-500']] as [$percent, $micro, $color])
                                <flux:table.cell class="min-w-44">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2.5 w-28 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full {{ $color }}" style="width: {{ $percent }}%"></div></div>
                                        <span class="w-10 text-end text-sm font-medium tabular-nums">{{ $percent }}%</span>
                                    </div>
                                    <div class="mt-0.5 text-xs text-zinc-500 tabular-nums">{{ Quantities::format($micro) }} {{ $order->uom_name }}</div>
                                </flux:table.cell>
                            @endforeach
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$stage->color()">{{ $stage->label() }}</flux:badge>
                                @if ($stage->closable() && $p->allInCedi())<div class="mt-1"><flux:badge size="sm" color="violet">Completa en CEDI</flux:badge></div>@endif
                                @if ($p->pendingInSap > 0)<div class="mt-1 text-xs text-amber-700 dark:text-amber-400">Pendiente de conciliar en SAP</div>@endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
