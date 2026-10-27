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
use App\Services\ZebraPrintService;
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
 * Box labels: look the production order up in this application's copy of
 * SAP (kept by the order board's refresh), check its header and
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

    public string $printerIp = '192.168.1.154';

    public int $printerPort = 9100;

    public ?array $printerStatus = null;

    public function mount(): void
    {
        $this->productionDate = today()->toDateString();
        $this->printerIp = (string) config('services.zebra.ip', env('ZEBRA_PRINTER_IP', '192.168.1.154'));
        $this->printerPort = (int) config('services.zebra.port', env('ZEBRA_PRINTER_PORT', 9100));
        $this->checkPrinter();
    }

    public function checkPrinter(): void
    {
        $zebra = app(ZebraPrintService::class);
        $this->printerStatus = $zebra->getPrinterStatus($this->printerIp, $this->printerPort);
    }

    public function printBatchDirect(?int $batchId = null): void
    {
        $targetBatch = $batchId !== null
            ? BoxLabelBatch::query()->find($batchId)
            : $this->batch;

        if ($targetBatch === null || empty($targetBatch->zpl)) {
            Flux::toast(variant: 'danger', text: 'No hay etiquetas ZPL disponibles para imprimir.');
            return;
        }

        $zebra = app(ZebraPrintService::class);
        $result = $zebra->printToSocket($this->printerIp, $this->printerPort, $targetBatch->zpl);

        if ($result['success']) {
            Flux::toast(variant: 'success', text: "{$targetBatch->labels} etiquetas transmitidas a la Zebra ({$this->printerIp}).");
        } else {
            Flux::toast(variant: 'danger', text: $result['message']);
        }

        $this->checkPrinter();
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
            $order = $consult->handle(Auth::user(), $this->docNum);
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
        Flux::toast(variant: 'success', text: "{$batch->labels} etiquetas generadas. Lista para imprimir en Zebra o descargar ZPL.");
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
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Etiquetas de caja</flux:heading>
            <flux:subheading>QR para las cajas de producto. Formato 10 × 8 cm con margen exterior para impresora Zebra (ZPL)</flux:subheading>
        </div>

        {{-- Estado en tiempo real de la Zebra --}}
        <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            @if ($printerStatus && ($printerStatus['online'] ?? false))
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-200">
                    Zebra ZT411 ({{ $printerIp }}): <strong class="text-emerald-600 dark:text-emerald-400">{{ $printerStatus['status_label'] }}</strong>
                </span>
            @else
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-rose-500"></span>
                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-200">
                    Zebra ({{ $printerIp }}): <strong class="text-rose-600 dark:text-rose-400">{{ $printerStatus['status_label'] ?? 'Sin conexión' }}</strong>
                </span>
            @endif
            <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="checkPrinter" wire:loading.attr="disabled" wire:target="checkPrinter">
                Verificar
            </flux:button>
        </div>
    </div>

    <form wire:submit="consult" class="flex max-w-xl flex-col gap-2">
        <flux:label for="doc-num">Número de orden de fabricación</flux:label>
        <div class="flex items-start gap-2">
            <flux:input id="doc-num" wire:model="docNum" placeholder="Ej. 33128" inputmode="numeric" autocomplete="off" autofocus class="flex-1" />
            <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled" wire:target="consult">Buscar OF</flux:button>
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
            <flux:text class="mt-3 text-xs">Datos de SAP actualizados el {{ $order->synced_at?->timezone($tz)->format('d/m/Y H:i') }} desde el Tablero de OF. Si algo cambió en SAP, actualiza el tablero.</flux:text>
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
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">{{ $this->batch ? 'Etiquetas generadas' : 'Vista previa' }}</flux:heading>
                    @if ($shownPayload !== null)
                        <span class="text-xs font-medium text-zinc-500">Formato 100 × 80 mm (ZPL 203 DPI)</span>
                    @endif
                </div>

                @if ($shownPayload !== null)
                    @php($data = BoxLabelPayload::decode($shownPayload))
                    @php($ean = $this->batch?->barcode ?? (string) Itf14::forBox($order->item->barcode, Itf14::indicator()))

                    {{-- Contenedor del sticker con la misma proporción y márgenes de GELATINA YIA LIMON X 12 G SIN IMP.lbl --}}
                    <div class="flex aspect-[10/8] w-full max-w-md flex-col justify-between rounded-md border-2 border-zinc-900 bg-white p-3 text-black shadow-md select-none">
                        {{-- Encabezado Centrado: Código PT y Descripción --}}
                        <div class="text-center pb-1">
                            <div class="text-base font-black tracking-wide leading-tight">
                                {{ $data['item'] ?? ($preview?->itemCode ?? 'PT1350023') }}
                            </div>
                            <div class="text-xs font-bold leading-tight mt-0.5 uppercase line-clamp-2">
                                {{ $data['description'] ?? ($preview?->itemDescription ?? 'DESCRIPCIÓN DEL PRODUCTO') }}
                            </div>
                        </div>

                        {{-- Bloque Medio: Datos a la izquierda, Logo Tecnoal a la derecha --}}
                        <div class="flex items-center justify-between gap-3 px-2 my-auto">
                            <div class="space-y-1 text-xs font-bold leading-snug">
                                <div>LOTE: <span class="font-mono font-black">{{ $data['lot'] ?? ($preview?->lotCode ?? '—') }}</span></div>
                                <div>VENCE: <span class="font-mono font-black">{{ $data['expiration'] ?? ($preview?->expirationDate->format('d/m/Y') ?? '—') }}</span></div>
                                <div>CAJA : <span class="font-mono font-black">{{ $preview?->unitsPerBox ?? ($this->factor ? (new Quantity((string) $this->factor->units_per_box))->display() : '—') }} UNIDADES</span></div>
                            </div>
                            <div class="flex shrink-0 items-center justify-center p-1">
                                <img src="/images/logo.png" alt="Tecnoal" class="h-10 max-h-12 w-auto object-contain" />
                            </div>
                        </div>

                        {{-- Bloque Inferior: EAN-14 a la izquierda, QR a la derecha --}}
                        <div class="grid grid-cols-12 gap-2 items-end pt-1">
                            <div class="col-span-8 flex flex-col items-center">
                                @if (Itf14::isValid($ean))
                                    <div class="w-full [&_svg]:h-14 [&_svg]:w-full">{!! Itf14::svg($ean) !!}</div>
                                    <div class="mt-0.5 font-mono text-[11px] font-bold tracking-widest text-center">{{ Itf14::readable($ean) }}</div>
                                @else
                                    <div class="text-center text-xs text-red-600 font-medium">Código EAN-14 no válido: {{ $ean ?: 'sin código' }}</div>
                                @endif
                            </div>
                            <div class="col-span-4 flex flex-col items-center justify-end">
                                <div class="h-16 w-16 [&_svg]:h-full [&_svg]:w-full">
                                    {!! BoxLabelQr::svg($shownQr) !!}
                                </div>
                                <div class="mt-0.5 text-center font-mono text-[10px] font-bold">
                                    Caja {{ $data['box'] ?? ($boxes->first()?->code ?? ($nextBox ?? '0001')) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <flux:text class="text-xs">
                        El QR lleva solo el código único de la caja; la OF, el PT, el lote y el vencimiento se toman de la aplicación al leerlo.
                        @if ($boxes->count() > 1) Cajas de {{ $boxes->first()->code }} a {{ $boxes->last()->code }}.@endif
                        Lo que escribe el lector{{ $this->batch ? ' con la primera etiqueta' : ' (ejemplo)' }}:
                    </flux:text>
                    <pre class="rounded bg-zinc-100 p-2 font-mono text-xs dark:bg-zinc-800">{{ $shownQr }}</pre>

                    @if ($this->batch)
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <flux:button variant="primary" icon="printer" wire:click="printBatchDirect" wire:loading.attr="disabled" wire:target="printBatchDirect">
                                Imprimir en Zebra ({{ $this->batch->labels }} etiquetas)
                            </flux:button>
                            <flux:button variant="subtle" icon="arrow-down-tray" :href="route('box-labels.zpl', $this->batch)">
                                Descargar ZPL ({{ $this->batch->labels }} etiquetas)
                            </flux:button>
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
                        <flux:table.column align="end"><span class="sr-only">Acciones</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($order->boxLabelBatches->sortByDesc('id') as $b)
                            <flux:table.row :key="$b->id">
                                <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $b->generated_at->timezone($tz)->format('d/m/Y H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $b->lot_code }}</flux:table.cell>
                                <flux:table.cell>{{ $b->expiration_date->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $b->labels }}</flux:table.cell>
                                <flux:table.cell>{{ $b->generator->name }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="sm" variant="ghost" icon="printer" wire:click="printBatchDirect({{ $b->id }})" title="Imprimir directo en Zebra">Imprimir</flux:button>
                                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('box-labels.zpl', $b)">ZPL</flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    @endif
</section>
