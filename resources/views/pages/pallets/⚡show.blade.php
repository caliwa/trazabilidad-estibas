<?php

use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use App\Queries\PalletTimeline;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One pallet: identity, the four status dimensions, its box reads, the
 * label prints, the SAP documents that hold it and its full history.
 */
new #[Title('Detalle de estiba')] class extends Component {
    #[Locked]
    public int $palletId;

    public string $reprintReason = '';

    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::PalletsView->value), 403);
    }

    public function mount(Pallet $pallet): void
    {
        $this->palletId = $pallet->id;
    }

    #[Computed]
    public function pallet(): Pallet
    {
        return Pallet::query()->with(['item:id,code,description', 'standard:id,version,sap_source_key'])->findOrFail($this->palletId);
    }

    /**
     * @return Collection<int, PalletLabelPrint>
     */
    #[Computed]
    public function prints(): Collection
    {
        return PalletLabelPrint::query()->with('printer:id,name')->where('pallet_id', $this->palletId)->orderBy('print_number')->get();
    }

    public function reprint(PrintLabel $print): void
    {
        $this->validate(['reprintReason' => ['required', 'string', 'max:300']], attributes: ['reprintReason' => 'motivo']);

        try {
            $printed = $print->handle(Auth::user(), $this->palletId, $this->reprintReason, TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip()));
        } catch (PalletRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->redirectRoute('pallets.label', ['pallet' => $this->palletId, 'print' => $printed->print_number]);
    }
}; ?>

@php
    $pallet = $this->pallet;
    $timeline = app(PalletTimeline::class);
    $scans = $timeline->scanSummary($pallet);
    $tz = config('app.timezone');
@endphp

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="font-mono">{{ $pallet->pallet_code }}</flux:heading>
            <flux:subheading>{{ $pallet->item_code }} · {{ $pallet->item->description }} · Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }}</flux:subheading>
        </div>
        <flux:button icon="arrow-left" :href="route('pallets.index')" wire:navigate variant="ghost">Volver al tablero</flux:button>
    </div>

    <dl class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Cajas</dt><dd class="text-2xl font-semibold tabular-nums">{{ $pallet->boxes_count }} / {{ $pallet->standard_boxes_snapshot }}</dd><dd class="text-xs text-zinc-500">{{ number_format($pallet->completionPercent(), 1, ',', '.') }} % · estándar v{{ $pallet->standard->version }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Físico</dt><dd class="font-medium">{{ $pallet->physical_status->label() }}</dd><dd class="text-xs text-zinc-500">{{ $pallet->close_type?->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Recepción</dt><dd class="font-medium">{{ $pallet->receipt_status->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Auditoría</dt><dd class="font-medium">{{ $pallet->audit_status->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Traslado</dt><dd class="font-medium">{{ $pallet->transfer_status->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Lecturas</dt><dd class="font-medium tabular-nums">{{ $scans['accepted'] }} aceptadas</dd><dd class="text-xs text-zinc-500">{{ $scans['late'] }} tardías</dd></div>
    </dl>

    <div class="grid gap-6 lg:grid-cols-2">
        <div>
            <flux:heading size="lg" level="2" class="mb-3">Historia</flux:heading>
            <ol class="flex flex-col gap-3 border-s border-zinc-200 ps-4 dark:border-zinc-700">
                @foreach ($timeline->entries($pallet) as $entry)
                    <li>
                        <div class="text-xs tabular-nums text-zinc-500">{{ $entry['at']->timezone($tz)->format('d/m/Y H:i:s') }} · {{ $entry['who'] }}</div>
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $entry['title'] }}</div>
                        @if ($entry['detail'])<div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $entry['detail'] }}</div>@endif
                    </li>
                @endforeach
            </ol>
            @if ($scans['first'])
                <flux:text class="mt-3 text-xs">Primera caja {{ $scans['first']->timezone($tz)->format('d/m/Y H:i:s') }} · última {{ $scans['last']?->timezone($tz)->format('d/m/Y H:i:s') }}</flux:text>
            @endif
        </div>

        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg" level="2" class="mb-3">Etiquetas</flux:heading>
                @forelse ($this->prints as $p)
                    <div class="flex justify-between gap-2 border-b border-zinc-100 py-2 text-sm dark:border-zinc-800" wire:key="print-{{ $p->id }}">
                        <span>#{{ $p->print_number }} {{ $p->kind->value === 'ORIGINAL' ? 'Original' : 'Reimpresión' }} · {{ $p->printer->name }} · {{ $p->printed_at->timezone($tz)->format('d/m/Y H:i') }}</span>
                        <span class="text-zinc-500">{{ $p->reason }}</span>
                    </div>
                @empty
                    <flux:text>Sin impresiones.</flux:text>
                @endforelse

                @if ($pallet->label_print_count > 0)
                    @can('pallets.reprint')
                        <form wire:submit="reprint" class="mt-3 flex items-end gap-2">
                            <flux:input wire:model="reprintReason" label="Motivo de reimpresión" maxlength="300" class="flex-1" />
                            <flux:button type="submit" icon="printer" wire:loading.attr="disabled" wire:target="reprint">Reimprimir</flux:button>
                        </form>
                    @endcan
                @endif
            </div>

            <div>
                <flux:heading size="lg" level="2" class="mb-3">Documentos SAP</flux:heading>
                @forelse ($timeline->documents($pallet) as $doc)
                    <div class="border-b border-zinc-100 py-2 text-sm dark:border-zinc-800">
                        <div class="font-medium">{{ $doc['kind'] }} · {{ $doc['status'] }}</div>
                        <div class="text-xs text-zinc-500">DocNum {{ $doc['doc_num'] ?? '—' }} · DocEntry {{ $doc['doc_entry'] ?? '—' }} · línea {{ $doc['sap_line_num'] ?? '—' }} · cantidad {{ $doc['sap_quantity'] }} · {{ $doc['request_uuid'] }}</div>
                    </div>
                @empty
                    <flux:text>Ningún documento todavía.</flux:text>
                @endforelse
            </div>
        </div>
    </div>
</section>
