<?php

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\BoxLabelQr;
use App\Domain\BoxLabels\BoxLot;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\BoxLabels\Itf14;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\ProductionOrder;
use App\Support\Correlation\CorrelationId;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Box labels: look the production order up in SAP, check its header and
 * generate the QR labels (10 × 8 cm, ZPL for Zebra) for its boxes.
 */
new #[Title('Etiquetas de caja')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::BoxLabelsPrint->value), 403);
    }

    public string $docNum = '';

    #[Locked]
    public ?int $orderId = null;

    public int $labels = 1;

    public string $productionDate = '';

    public string $expirationDate = '';

    #[Locked]
    public ?int $batchId = null;

    public function mount(): void
    {
        $this->productionDate = today()->toDateString();
    }

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    #[Computed]
    public function order(): ?ProductionOrder
    {
        return $this->orderId === null ? null : ProductionOrder::query()->with(['item', 'boxLabelBatches.generator:id,name'])->find($this->orderId);
    }

    #[Computed]
    public function factor(): ?BoxConversionFactor
    {
        return $this->order === null ? null : BoxConversionFactor::query()->where('item_id', $this->order->item_id)->where('status', MasterStatus::Current)->first();
    }

    public function maxLabels(): ?int
    {
        if ($this->order?->planned_quantity === null || $this->factor === null) {
            return null;
        }

        return GenerateBoxLabels::maxLabels((string) $this->order->planned_quantity, (string) $this->factor->units_per_box);
    }

    public function printedLabels(): int
    {
        return (int) $this->order?->boxLabelBatches->sum('labels');
    }

    #[Computed]
    public function batch(): ?BoxLabelBatch
    {
        return $this->batchId === null ? null : BoxLabelBatch::query()->find($this->batchId);
    }

    private function date(string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay() : null;
    }

    /**
     * What the next label would carry, while the form is being filled in.
     */
    public function preview(): ?BoxLabelPayload
    {
        $production = $this->date($this->productionDate);
        $expiration = $this->date($this->expirationDate);

        if ($this->order === null || $this->factor === null || Itf14::forBox($this->order->item->barcode, Itf14::indicator()) === null || $production === null || $expiration === null) {
            return null;
        }

        return new BoxLabelPayload(
            orderRef: $this->order->ref,
            itemCode: $this->order->item->code,
            itemDescription: $this->order->item->description,
            unitsPerBox: (new Quantity((string) $this->factor->units_per_box))->display(),
            uomName: $this->order->uom_name ?? $this->order->item->inventory_uom,
            lotCode: BoxLot::code($this->order->ref, $production),
            barcode: (string) Itf14::forBox($this->order->item->barcode, Itf14::indicator()),
            expirationDate: $expiration,
        );
    }

    public function consult(ConsultProductionOrder $consult): void
    {
        $this->validate(['docNum' => ['required', 'string', 'max:20']], attributes: ['docNum' => 'número de OF']);
        $this->reset('orderId', 'batchId', 'labels');
        unset($this->order, $this->factor, $this->batch);

        try {
            $order = $consult->handle(Auth::user(), $this->docNum, $this->context());
        } catch (BoxLabelRuleViolation $e) {
            $this->addError('docNum', $e->getMessage());

            return;
        }

        $this->orderId = $order->id;
        $this->suggestExpiration();
    }

    public function updatedProductionDate(): void
    {
        $this->suggestExpiration();
    }

    private function suggestExpiration(): void
    {
        $production = $this->date($this->productionDate);
        $days = $this->order?->item->shelf_life_days;

        if ($production !== null && $days !== null) {
            $this->expirationDate = $production->addDays($days)->toDateString();
        }
    }

    public function generate(GenerateBoxLabels $generate): void
    {
        $this->validate([
            'labels' => ['required', 'integer', 'min:1'],
            'productionDate' => ['required', 'date_format:Y-m-d'],
            'expirationDate' => ['required', 'date_format:Y-m-d'],
        ], attributes: ['labels' => 'cantidad de etiquetas', 'productionDate' => 'fecha de producción', 'expirationDate' => 'fecha de vencimiento']);

        try {
            $batch = $generate->handle(Auth::user(), (int) $this->orderId, $this->labels, $this->date($this->productionDate) ?? today(), $this->date($this->expirationDate), $this->context());
        } catch (BoxLabelRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->batchId = $batch->id;
        unset($this->order, $this->batch);
        Flux::toast(variant: 'success', text: "{$batch->labels} etiquetas generadas. Descarga el archivo ZPL para la Zebra.");
    }
}; ?>

@php
    $order = $this->order;
    $preview = $this->preview();
    $max = $this->maxLabels();
    $printed = $this->printedLabels();
    $tz = config('app.timezone');
    // The first label: its printed text (with its box code) and its QR, which carries only the box token.
    $boxes = $this->batch === null ? collect() : BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->orderBy('sequence')->get(['code', 'qr_token']);
    $nextBox = $preview === null ? null : BoxLot::boxCode($preview->lotCode, (int) BoxLabel::query()->where('production_order_id', $order->id)->where('lot_code', $preview->lotCode)->max('sequence') + 1);
    $shownPayload = $this->batch !== null
        ? $this->batch->qr_payload.($boxes->isEmpty() ? '' : "\nCaja: ".$boxes->first()->code)
        : $preview?->withBox((string) $nextBox)->encode();
    // Before generating there is no token yet: the preview QR shows the format with a sample.
    $shownQr = BoxQr::payload($boxes->first()?->qr_token ?? str_repeat('0', 32));
@endphp

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Etiquetas de caja</flux:heading>
        <flux:subheading>QR para las cajas de producto. Etiqueta de 10 × 8 cm para impresora Zebra (ZPL)</flux:subheading>
    </div>

    <form wire:submit="consult" class="flex max-w-xl flex-col gap-2">
        <flux:label for="doc-num">Número de orden de fabricación</flux:label>
        <div class="flex items-start gap-2">
            <flux:input id="doc-num" wire:model="docNum" placeholder="Ej. 33128" inputmode="numeric" autocomplete="off" autofocus class="flex-1" />
            <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled" wire:target="consult">Consultar en SAP</flux:button>
        </div>
        <flux:error name="docNum" />
    </form>

    @if ($order !== null)
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">Orden de fabricación {{ $order->ref }}</flux:heading>
                <flux:badge color="green">{{ ProductionOrderStatus::tryFrom((string) $order->sap_status)?->label() ?? $order->sap_status }}</flux:badge>
            </div>
            <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    'Tipo' => $order->sap_order_type,
                    'Nº producto' => $order->item->code,
                    'Descripción producto' => $order->item->description,
                    'Cantidad planificada' => $order->planned_quantity === null ? null : (new Quantity((string) $order->planned_quantity))->display().' '.$order->uom_name,
                    'Almacén' => $order->warehouse_code,
                    'Prioridad' => $order->priority,
                    'Fecha orden de fabricación' => $order->order_date?->format('d/m/Y'),
                    'Fecha de inicio' => $order->start_date?->format('d/m/Y'),
                    'Fecha de finalización' => $order->due_date?->format('d/m/Y'),
                    'Usuario' => $order->sap_user,
                    'Origen' => $order->sap_origin,
                    'Vinculado a' => $order->sap_linked_to,
                ] as $label => $value)
                    <div class="flex justify-between gap-3 border-b border-zinc-100 py-1 dark:border-zinc-800">
                        <dt class="text-zinc-500">{{ $label }}</dt>
                        <dd class="text-end font-medium">{{ $value ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
            <flux:text class="mt-3 text-xs">Consultada en SAP el {{ $order->consulted_at?->timezone($tz)->format('d/m/Y H:i') }}. La información quedó guardada.</flux:text>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg" level="2">Generar etiquetas</flux:heading>

                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">Unidades por caja</dt>
                    <dd class="font-medium">{{ $this->factor ? (new Quantity((string) $this->factor->units_per_box))->display().' '.($order->uom_name ?? $order->item->inventory_uom) : 'Sin definir (DP-02)' }}</dd>
                    <dt class="text-zinc-500">Código EAN-14 de la caja</dt>
                    <dd class="font-mono font-medium">
                        {{ Itf14::forBox($order->item->barcode, Itf14::indicator()) ?? ($order->item->barcode ? 'Código no válido' : 'Sin código en SAP') }}
                        @if ($order->item->barcode && strlen(trim($order->item->barcode)) === 13)<div class="text-xs font-normal text-zinc-500">Desde el EAN-13 de SAP {{ $order->item->barcode }}</div>@endif
                    </dd>
                    <dt class="text-zinc-500">Etiquetas generadas</dt>
                    <dd class="font-medium tabular-nums">{{ $printed }}{{ $max === null ? '' : ' de '.$max }}</dd>
                </dl>

                <form wire:submit="generate" class="flex flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="labels" type="number" min="1" :max="$max === null ? null : max($max - $printed, 1)" label="Cantidad de etiquetas" required />
                        <flux:input wire:model.live="productionDate" type="date" label="Fecha de producción" required />
                        <flux:input wire:model.live="expirationDate" type="date" label="Fecha de vencimiento" required />
                    </div>
                    <flux:text class="text-sm">Lote: <span class="font-mono font-medium">{{ $preview?->lotCode ?? ($this->date($productionDate) ? BoxLot::code($order->ref, $this->date($productionDate)) : '—') }}</span> (OF + día juliano de la fecha de producción)</flux:text>
                    <flux:button type="submit" variant="primary" icon="qr-code" wire:loading.attr="disabled" wire:target="generate"
                        wire:confirm="¿Generar {{ $labels }} etiquetas de caja para la OF {{ $order->ref }}?">Generar etiquetas</flux:button>
                </form>
            </div>

            <div class="flex flex-col gap-3">
                <flux:heading size="lg" level="2">{{ $this->batch ? 'Etiquetas generadas' : 'Vista previa' }}</flux:heading>
                @if ($shownPayload !== null)
                    @php($data = BoxLabelPayload::decode($shownPayload))
                    @php($ean = $this->batch?->barcode ?? (string) Itf14::forBox($order->item->barcode, Itf14::indicator()))
                    {{-- 10 × 8 cm at screen scale, same layout as the ZPL: text and QR on top, ITF-14 below. --}}
                    <div class="flex aspect-[10/8] w-full max-w-md flex-col rounded-md border-2 border-zinc-800 bg-white p-4 text-black">
                        <div class="flex flex-1 justify-between gap-3">
                            <div class="flex min-w-0 flex-col gap-0.5 text-xs">
                                <div class="text-xl font-bold leading-tight">OF {{ $data['order'] ?? '' }}</div>
                                <div class="text-sm font-semibold">{{ $data['item'] ?? '' }}</div>
                                <div class="leading-snug">{{ $data['description'] ?? '' }}</div>
                                <div class="mt-1 text-sm">Lote: <b>{{ $data['lot'] ?? '' }}</b></div>
                                <div class="text-sm">Vence: <b>{{ $data['expiration'] ?? '' }}</b></div>
                                <div>Contenido: <b>{{ $data['content'] ?? '' }}</b></div>
                            </div>
                            <div class="w-[38%] shrink-0 text-center [&_svg]:h-auto [&_svg]:w-full">
                                {!! BoxLabelQr::svg($shownQr) !!}
                                @if ($data['box'] ?? null)<div class="text-xs font-semibold">Caja {{ $data['box'] }}</div>@endif
                            </div>
                        </div>
                        <div class="mt-2 border-t-2 border-zinc-800 pt-2">
                            @if (Itf14::isValid($ean))
                                <div class="mx-auto w-4/5 [&_svg]:h-auto [&_svg]:w-full">{!! Itf14::svg($ean) !!}</div>
                            @else
                                <div class="text-center text-xs text-red-700">Código EAN-14 no válido: {{ $ean ?: 'sin código' }}</div>
                            @endif
                        </div>
                    </div>
                    <flux:text class="text-xs">
                        El QR lleva solo el código único de la caja; la OF, el PT, el lote y el vencimiento se toman de la aplicación al leerlo.
                        @if ($boxes->count() > 1) Cajas de {{ $boxes->first()->code }} a {{ $boxes->last()->code }}.@endif
                        Lo que escribe el lector{{ $this->batch ? ' con la primera etiqueta' : ' (ejemplo)' }}:
                    </flux:text>
                    <pre class="rounded bg-zinc-100 p-2 font-mono text-xs dark:bg-zinc-800">{{ $shownQr }}</pre>
                    @if ($this->batch)
                        <div>
                            <flux:button variant="primary" icon="arrow-down-tray" :href="route('box-labels.zpl', $this->batch)">Descargar ZPL ({{ $this->batch->labels }} etiquetas)</flux:button>
                        </div>
                    @endif
                @else
                    <flux:text>Completa unidades por caja, código EAN y fechas para ver la etiqueta.</flux:text>
                @endif
            </div>
        </div>

        @if ($order->boxLabelBatches->isNotEmpty())
            <div>
                <flux:heading size="lg" level="2" class="mb-3">Etiquetas generadas para esta orden</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Generadas</flux:table.column>
                        <flux:table.column>Lote</flux:table.column>
                        <flux:table.column>Vence</flux:table.column>
                        <flux:table.column align="end">Etiquetas</flux:table.column>
                        <flux:table.column>Por</flux:table.column>
                        <flux:table.column><span class="sr-only">Descargar</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($order->boxLabelBatches->sortByDesc('id') as $b)
                            <flux:table.row :key="$b->id">
                                <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $b->generated_at->timezone($tz)->format('d/m/Y H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $b->lot_code }}</flux:table.cell>
                                <flux:table.cell>{{ $b->expiration_date->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $b->labels }}</flux:table.cell>
                                <flux:table.cell>{{ $b->generator->name }}</flux:table.cell>
                                <flux:table.cell align="end"><flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('box-labels.zpl', $b)">ZPL</flux:button></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    @endif
</section>
