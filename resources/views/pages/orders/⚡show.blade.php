<?php

use App\Domain\Orders\Actions\RefreshProductionOrders;
use App\Domain\Orders\OrderProgress;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Queries\OrderProgressQuery;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One production order: SAP header, the way from planned to stock in 05,
 * its lots, pallets, SAP documents and how long each step takes.
 */
new #[Title('Orden de fabricación')] class extends Component {
    #[Locked]
    public int $orderId;

    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::OrdersView->value), 403);
    }

    public function mount(ProductionOrder $order): void
    {
        $this->orderId = $order->id;
    }

    #[Computed]
    public function order(): ProductionOrder
    {
        return ProductionOrder::query()->with('item:id,code,description')->findOrFail($this->orderId);
    }

    #[Computed]
    public function progress(): OrderProgress
    {
        return app(OrderProgressQuery::class)->forOrder($this->orderId) ?? abort(404);
    }

    /**
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function pallets(): Collection
    {
        return Pallet::query()->where('production_order_id', $this->orderId)->orderBy('id')->get();
    }

    public function refreshFromSap(): void
    {
        $problems = app(RefreshProductionOrders::class)->handle(
            Auth::user(), [$this->orderId], TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip()),
        );

        Flux::toast(variant: $problems === [] ? 'success' : 'warning', text: $problems === [] ? 'Orden actualizada desde SAP.' : (string) reset($problems));
        unset($this->order, $this->progress);
    }
}; ?>

@php
    use App\Domain\Integration\Enums\SapDocumentStatus;
    use App\Queries\OrderDetailQuery;
    use App\Support\Quantities;
    use Carbon\CarbonImmutable;
    $order = $this->order;
    $p = $this->progress;
    $stage = $p->stage();
    $uom = $order->uom_name ?? 'unidades';
    $units = fn (?int $micro) => $micro === null ? '—' : Quantities::format($micro);
    $detail = app(OrderDetailQuery::class);
    $lots = $detail->lots($order->id);
    $documents = $detail->documents($order->id);
    $times = $detail->times($order->id);
    $duration = fn (?int $m) => $m === null ? '—' : ($m < 60 ? "{$m} min" : intdiv($m, 60).' h '.($m % 60).' min');
    $canSeePallets = auth()->user()->can('pallets.view');
@endphp

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:link :href="route('orders.index')" wire:navigate class="text-sm">← Tablero de OF</flux:link>
            <flux:heading size="xl" level="1" class="mt-1">OF {{ $order->ref }}</flux:heading>
            <flux:subheading>{{ $order->item->code }} · {{ $order->item->description }}</flux:subheading>
            <div class="mt-2 flex flex-wrap gap-2">
                <flux:badge :color="$stage->color()">{{ $stage->label() }}</flux:badge>
                <flux:badge color="zinc">SAP: {{ $order->sap_status ? ucfirst(mb_strtolower($order->sap_status)) : 'sin consultar' }}</flux:badge>
            </div>
        </div>
        <div class="text-end">
            <flux:button icon="arrow-path" wire:click="refreshFromSap" wire:loading.attr="disabled" wire:target="refreshFromSap">Actualizar desde SAP</flux:button>
            <div class="mt-1 text-xs text-zinc-500">Última consulta: {{ $order->synced_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? 'nunca' }}</div>
        </div>
    </div>

    @foreach ($p->alerts() as $alert)
        <flux:callout :variant="$alert['level']" :icon="$alert['level'] === 'success' ? 'check-circle' : 'exclamation-triangle'">{{ $alert['text'] }}</flux:callout>
    @endforeach

    <div class="grid gap-3 rounded-xl border border-zinc-200 p-4 text-sm sm:grid-cols-3 lg:grid-cols-6 dark:border-zinc-700">
        @foreach ([
            'Planificado' => $units($p->plannedMicro).' '.$uom,
            'Completado en SAP' => $p->sapCompletedMicro === null ? '—' : $units($p->sapCompletedMicro).' '.$uom,
            'Almacén' => $order->warehouse_code ?? '—',
            'Inicio' => $order->start_date?->format('d/m/Y') ?? '—',
            'Fin' => $order->due_date?->format('d/m/Y') ?? '—',
            'Unidades por caja' => $p->unitsPerBoxMicro === null ? 'Sin factor' : $units($p->unitsPerBoxMicro),
        ] as $label => $value)
            <div><div class="text-xs text-zinc-500">{{ $label }}</div><div class="font-medium tabular-nums">{{ $value }}</div></div>
        @endforeach
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">Recorrido</flux:heading>
        @php($top = max(1, $p->plannedBoxes() ?? 0, $p->labels, $p->boxesRead))
        <div class="flex flex-col gap-2">
            @foreach ([
                ['Planificado', $p->plannedBoxes(), $p->plannedMicro],
                ['Etiquetas de caja', $p->labels, $p->unitsOf($p->labels)],
                ['Cajas leídas', $p->boxesRead, $p->unitsOf($p->boxesRead)],
                ['Recibido en 04', $p->receivedBoxes, $p->receivedMicro],
                ['Contado y aprobado', $p->approvedBoxes, $p->unitsOf($p->approvedBoxes)],
                ['En 05', $p->transferredBoxes, $p->transferredMicro],
            ] as [$label, $boxes, $micro])
                <div class="grid grid-cols-[10rem_1fr_9rem] items-center gap-3 text-sm">
                    <div class="text-zinc-600 dark:text-zinc-300">{{ $label }}</div>
                    <div class="h-5 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded bg-blue-500" style="width: {{ $boxes === null ? 0 : min(100, intdiv($boxes * 100, $top)) }}%"></div>
                    </div>
                    <div class="text-end tabular-nums">
                        {{ $boxes ?? '—' }} cajas
                        <div class="text-xs text-zinc-500">{{ $units($micro) }} {{ $uom }}</div>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-zinc-500">Recibido y En 05 en unidades son las cantidades exactas enviadas a SAP. Las demás se calculan con el factor vigente.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        @foreach (['Estiba cerrada → recibo confirmado' => $times['to_receipt'], 'Recibo → conteo aprobado' => $times['to_count'], 'Conteo → en 05' => $times['to_transfer']] as $label => $minutes)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-xs text-zinc-500">{{ $label }} (promedio)</div>
                <div class="text-xl font-semibold tabular-nums">{{ $duration($minutes) }}</div>
            </div>
        @endforeach
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">Lotes</flux:heading>
        @if ($lots === [])
            <p class="text-sm text-zinc-500">Todavía no hay etiquetas ni lecturas.</p>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Lote</flux:table.column>
                    <flux:table.column>Vence</flux:table.column>
                    <flux:table.column align="end">Etiquetas</flux:table.column>
                    <flux:table.column align="end">Cajas leídas</flux:table.column>
                    <flux:table.column align="end">Estibas</flux:table.column>
                    <flux:table.column align="end">Recibidas (cajas)</flux:table.column>
                    <flux:table.column align="end">En 05 (cajas)</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($lots as $lot)
                        <flux:table.row :key="$lot->lot_code">
                            <flux:table.cell class="font-mono">{{ $lot->lot_code }}</flux:table.cell>
                            <flux:table.cell>{{ $lot->expiration_date ? CarbonImmutable::parse($lot->expiration_date)->format('d/m/Y') : '—' }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $lot->labels }}</flux:table.cell>
                            <flux:table.cell align="end" @class(['tabular-nums', 'text-amber-700 dark:text-amber-400' => $lot->boxes_read > $lot->labels])>{{ $lot->boxes_read }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $lot->pallets }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $lot->received_boxes }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $lot->transferred_boxes }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">Documentos en SAP</flux:heading>
        @if ($documents === [])
            <p class="text-sm text-zinc-500">Todavía no hay recibos ni transferencias.</p>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Documento</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                    <flux:table.column align="end">DocNum</flux:table.column>
                    <flux:table.column align="end">Estibas</flux:table.column>
                    <flux:table.column align="end">Cantidad</flux:table.column>
                    <flux:table.column>Pedido</flux:table.column>
                    <flux:table.column>Confirmado</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($documents as $doc)
                        @php($status = SapDocumentStatus::from($doc->status))
                        <flux:table.row :key="$doc->kind.$doc->id">
                            <flux:table.cell>{{ $doc->kind === 'recibo' ? 'Recibo de producción' : 'Transferencia 04 → 05' }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="match ($status) { SapDocumentStatus::Confirmed => 'green', SapDocumentStatus::Failed => 'red', SapDocumentStatus::PendingReconciliation => 'amber', default => 'zinc' }">{{ $status->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $doc->sap_doc_num ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $doc->pallets }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $doc->quantity === null ? '—' : $units(Quantities::toMicro($doc->quantity)).' '.$uom }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ CarbonImmutable::parse($doc->requested_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $doc->confirmed_at ? CarbonImmutable::parse($doc->confirmed_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">Estibas ({{ $this->pallets->count() }})</flux:heading>
        @if ($this->pallets->isEmpty())
            <p class="text-sm text-zinc-500">Todavía no hay estibas.</p>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Consecutivo</flux:table.column>
                    <flux:table.column>Lote</flux:table.column>
                    <flux:table.column align="end">Cajas</flux:table.column>
                    <flux:table.column>Físico</flux:table.column>
                    <flux:table.column>Recepción</flux:table.column>
                    <flux:table.column>Auditoría</flux:table.column>
                    <flux:table.column>Traslado</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->pallets as $pallet)
                        <flux:table.row :key="$pallet->id">
                            <flux:table.cell class="font-mono">
                                @if ($canSeePallets)<a href="{{ route('pallets.show', $pallet) }}" wire:navigate class="hover:underline">{{ $pallet->pallet_code }}</a>@else{{ $pallet->pallet_code }}@endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $pallet->lot_code }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $pallet->boxes_count }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->physical_status->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->receipt_status->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->audit_status->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->transfer_status->label() }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
