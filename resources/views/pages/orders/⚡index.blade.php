<?php

use App\Domain\Orders\Actions\RefreshProductionOrders;
use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Queries\OrderBoardQuery;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Production order board: each released order's progress from the box
 * labels to the stock in 05, next to what SAP says. SAP is only read when
 * someone presses "Actualizar desde SAP", which also brings in orders
 * released since and drops the ones closed there.
 */
new #[Title('Tablero de OF')] class extends Component {
    use WithPagination;

    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::OrdersView->value), 403);
    }

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: false)]
    public bool $onlyAlerts = false;

    /** @var array<string, string> */
    public array $problems = [];

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stage', 'onlyAlerts');
        $this->resetPage();
    }

    public function refreshFromSap(): void
    {
        $ids = RefreshProductionOrders::openOrderIds();
        $this->problems = app(RefreshProductionOrders::class)->handle(
            Auth::user(), $ids, TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip()),
        );

        $done = count($ids) - count($this->problems);
        Flux::toast(variant: $this->problems === [] ? 'success' : 'warning', text: "{$done} de ".count($ids).' órdenes actualizadas desde SAP.');
        unset($this->rows, $this->indicators);
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return app(OrderBoardQuery::class)->paginate($this->search, $this->stage, $this->onlyAlerts, $this->getPage());
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function indicators(): array
    {
        return app(OrderBoardQuery::class)->indicators();
    }
}; ?>

@php
    use App\Support\Quantities;
    $units = fn (?int $micro) => $micro === null ? '—' : Quantities::format($micro);
@endphp

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">Tablero de OF</flux:heading>
            <flux:subheading>Órdenes de fabricación liberadas en SAP: avance contra lo planificado</flux:subheading>
        </div>
        <flux:button icon="arrow-path" wire:click="refreshFromSap" wire:loading.attr="disabled" wire:target="refreshFromSap">
            <span wire:loading.remove wire:target="refreshFromSap">Actualizar desde SAP</span>
            <span wire:loading wire:target="refreshFromSap">Consultando SAP…</span>
        </flux:button>
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

    <dl class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
        @foreach ([
            'released' => ['OF liberadas', null], 'ready' => ['Listas para cerrar', 'green'], 'over' => ['Sobreproducción', 'red'], 'alerts' => ['Con alertas', 'amber'],
            'boxes_today' => ['Cajas leídas hoy', null], 'received_today' => ['Estibas recibidas hoy', null], 'transferred_today' => ['Estibas en 05 hoy', null],
        ] as $key => [$label, $tone])
            @php($hot = $tone !== null && $this->indicators[$key] > 0)
            <div @class([
                'rounded-xl border p-4',
                'border-zinc-200 dark:border-zinc-700' => ! $hot,
                'border-green-400 bg-green-50 dark:border-green-700 dark:bg-green-950' => $hot && $tone === 'green',
                'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-950' => $hot && $tone === 'red',
                'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950' => $hot && $tone === 'amber',
            ])>
                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                <dd class="text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ number_format($this->indicators[$key], 0, ',', '.') }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:input wire:model.live.debounce.400ms="search" label="Buscar" placeholder="OF, código o descripción" clearable />
        <flux:select wire:model.live="stage" label="Avance">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach ([OrderStage::InProgress, OrderStage::ReadyToClose, OrderStage::OverProduced] as $s)<flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>@endforeach
        </flux:select>
        <flux:checkbox wire:model.live="onlyAlerts" label="Solo con alertas" />
        <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">Limpiar filtros</flux:button>
    </div>

    <div wire:loading.class="opacity-60" class="transition-opacity">
        @if ($this->rows->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay órdenes liberadas con esos filtros. Si acaban de liberar una en SAP, usa «Actualizar desde SAP».</div>
        @else
            <flux:table :paginator="$this->rows">
                <flux:table.columns>
                    <flux:table.column>OF</flux:table.column>
                    <flux:table.column>Producto</flux:table.column>
                    <flux:table.column>Avance</flux:table.column>
                    <flux:table.column align="end">Planificado</flux:table.column>
                    <flux:table.column align="end">Etiquetas</flux:table.column>
                    <flux:table.column align="end">Cajas leídas</flux:table.column>
                    <flux:table.column>Estibas</flux:table.column>
                    <flux:table.column align="end">Recibido en 04</flux:table.column>
                    <flux:table.column align="end">En 05</flux:table.column>
                    <flux:table.column>Alertas</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->rows as ['order' => $order, 'progress' => $p])
                        @php($stage = $p->stage())
                        <flux:table.row :key="$order->id" @class(['bg-green-50/60 dark:bg-green-950/40' => $stage === OrderStage::ReadyToClose, 'bg-red-50/60 dark:bg-red-950/40' => $stage === OrderStage::OverProduced])>
                            <flux:table.cell>
                                <a href="{{ route('orders.show', $order) }}" wire:navigate class="font-mono font-semibold hover:underline">{{ $order->ref }}</a>
                                @if ($order->due_date)<div class="text-xs text-zinc-500">Fin {{ $order->due_date->format('d/m/Y') }}</div>@endif
                            </flux:table.cell>
                            <flux:table.cell class="max-w-56 whitespace-normal"><div>{{ $order->item->code }}</div><div class="text-xs text-zinc-500">{{ $order->item->description }}</div></flux:table.cell>
                            <flux:table.cell class="min-w-40">
                                <flux:badge size="sm" :color="$stage->color()">{{ $stage->label() }}</flux:badge>
                                @if ($p->plannedMicro !== null)
                                    <div class="mt-1 flex items-center gap-2">
                                        <div class="h-2 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                            <div @class(['h-full rounded-full', 'bg-red-500' => $stage === OrderStage::OverProduced, 'bg-green-500' => $stage !== OrderStage::OverProduced]) style="width: {{ $p->percent() }}%"></div>
                                        </div>
                                        <span class="text-xs tabular-nums">{{ $p->percent() }}%</span>
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">
                                {{ $units($p->plannedMicro) }} @if ($p->plannedMicro !== null)<span class="text-xs text-zinc-500">{{ $order->uom_name }}</span>@endif
                                @if ($p->plannedBoxes() !== null)<div class="text-xs text-zinc-500">{{ $p->plannedBoxes() }} cajas</div>@endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $p->labels }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $p->boxesRead }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-xs">
                                <div>{{ $p->palletsStacking }} armando</div>
                                <div>{{ $p->palletsFinished }} terminadas</div>
                                <div>{{ $p->palletsLabeled }} etiquetadas</div>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">
                                {{ $units($p->receivedMicro) }}
                                <div class="text-xs text-zinc-500">{{ $p->receivedBoxes }} cajas</div>
                                @if ($p->sapCompletedMicro !== null)<div class="text-xs text-zinc-500">SAP: {{ $units($p->sapCompletedMicro) }}</div>@endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">
                                {{ $units($p->transferredMicro) }}
                                <div class="text-xs text-zinc-500">{{ $p->transferredBoxes }} cajas</div>
                            </flux:table.cell>
                            <flux:table.cell class="max-w-72 whitespace-normal text-xs">
                                @forelse ($p->alerts() as $alert)
                                    <div @class(['mb-1', 'text-red-700 dark:text-red-400' => $alert['level'] === 'danger', 'text-amber-700 dark:text-amber-400' => $alert['level'] === 'warning', 'text-green-700 dark:text-green-400' => $alert['level'] === 'success'])>{{ $alert['text'] }}</div>
                                @empty
                                    <span class="text-zinc-400">—</span>
                                @endforelse
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
