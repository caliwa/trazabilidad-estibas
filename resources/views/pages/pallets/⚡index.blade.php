<?php

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Queries\PalletBoardQuery;
use App\Queries\PalletFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Administrative pallet board. Box counts are visible here, which is why
 * logistics operators (blind count) do not have pallets.view.
 */
new #[Title('Estibas')] class extends Component {
    use WithPagination;

    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::PalletsView->value), 403);
    }

    #[Url(except: '')]
    public string $code = '';

    #[Url(except: '')]
    public string $order = '';

    #[Url(except: '')]
    public string $item = '';

    #[Url(except: '')]
    public string $lot = '';

    #[Url(except: '')]
    public string $physical = '';

    #[Url(except: '')]
    public string $receipt = '';

    #[Url(except: '')]
    public string $audit = '';

    #[Url(except: '')]
    public string $transfer = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $sapDoc = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('code', 'order', 'item', 'lot', 'physical', 'receipt', 'audit', 'transfer', 'from', 'to', 'sapDoc');
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Pallet>
     */
    #[Computed]
    public function pallets(): LengthAwarePaginator
    {
        return app(PalletBoardQuery::class)->paginate(new PalletFilters(
            $this->code, $this->order, $this->item, $this->lot, $this->physical, $this->receipt, $this->audit, $this->transfer, $this->from, $this->to, $this->sapDoc,
        ));
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function indicators(): array
    {
        return app(PalletBoardQuery::class)->indicators();
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Estibas</flux:heading>
        <flux:subheading>Trazabilidad desde el estibado hasta el traslado</flux:subheading>
    </div>

    <dl class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
        @foreach ([
            'in_process' => 'En proceso', 'finished' => 'Finalizadas', 'labeled' => 'Etiquetadas', 'received' => 'Recibidas en 04',
            'transferred' => 'Trasladadas a 05', 'receipts_pending' => 'Recibos por conciliar', 'transfers_pending' => 'Traslados por conciliar', 'discrepancies_open' => 'Discrepancias abiertas',
        ] as $key => $label)
            @php($warn = in_array($key, ['receipts_pending', 'transfers_pending', 'discrepancies_open'], true) && $this->indicators[$key] > 0)
            <div @class(['rounded-xl border p-4', 'border-zinc-200 dark:border-zinc-700' => ! $warn, 'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950' => $warn])>
                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                <dd class="text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ number_format($this->indicators[$key], 0, ',', '.') }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
        <flux:input wire:model.live.debounce.400ms="code" label="Consecutivo" clearable />
        <flux:input wire:model.live.debounce.400ms="order" label="Orden" clearable />
        <flux:input wire:model.live.debounce.400ms="item" label="Producto" placeholder="Código o descripción" clearable />
        <flux:input wire:model.live.debounce.400ms="lot" label="Lote" clearable />
        <flux:input wire:model.live.debounce.400ms="sapDoc" label="Documento SAP" placeholder="DocNum" inputmode="numeric" clearable />
        <flux:select wire:model.live="physical" label="Estado físico">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach (PhysicalStatus::cases() as $s)<flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>@endforeach
        </flux:select>
        <flux:select wire:model.live="receipt" label="Recepción">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach (ReceiptStatus::cases() as $s)<flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>@endforeach
        </flux:select>
        <flux:select wire:model.live="audit" label="Auditoría">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach (AuditStatus::cases() as $s)<flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>@endforeach
        </flux:select>
        <flux:select wire:model.live="transfer" label="Traslado">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach (TransferStatus::cases() as $s)<flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>@endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="from" label="Inicio desde" />
        <flux:input type="date" wire:model.live="to" label="Inicio hasta" />
        <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">Limpiar filtros</flux:button>
    </div>

    <div wire:loading.class="opacity-60" class="transition-opacity">
        @if ($this->pallets->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay estibas con esos filtros.</div>
        @else
            <flux:table :paginator="$this->pallets">
                <flux:table.columns>
                    <flux:table.column>Consecutivo</flux:table.column>
                    <flux:table.column>Orden</flux:table.column>
                    <flux:table.column>Producto</flux:table.column>
                    <flux:table.column>Lote</flux:table.column>
                    <flux:table.column align="end">Cajas</flux:table.column>
                    <flux:table.column align="end">%</flux:table.column>
                    <flux:table.column>Físico</flux:table.column>
                    <flux:table.column>Inicio</flux:table.column>
                    <flux:table.column>Cierre</flux:table.column>
                    <flux:table.column>Paletizado</flux:table.column>
                    <flux:table.column>Etiquetado</flux:table.column>
                    <flux:table.column>Recepción</flux:table.column>
                    <flux:table.column>Auditoría</flux:table.column>
                    <flux:table.column>Traslado</flux:table.column>
                    <flux:table.column>Doc. SAP</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->pallets as $pallet)
                        <flux:table.row :key="$pallet->id">
                            <flux:table.cell><a href="{{ route('pallets.show', $pallet) }}" wire:navigate class="font-mono font-medium hover:underline">{{ $pallet->pallet_code }}</a></flux:table.cell>
                            <flux:table.cell>{{ $pallet->production_order_ref }}</flux:table.cell>
                            <flux:table.cell class="max-w-56 whitespace-normal"><div>{{ $pallet->item_code }}</div><div class="text-xs text-zinc-500">{{ $pallet->getAttribute('item_description') }}</div></flux:table.cell>
                            <flux:table.cell>{{ $pallet->lot_code }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $pallet->boxes_count }} / {{ $pallet->standard_boxes_snapshot }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ number_format($pallet->completionPercent(), 1, ',', '.') }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->physical_status->label() }}@if ($pallet->close_type)<div class="text-xs text-zinc-500">{{ $pallet->close_type->label() }}</div>@endif</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $pallet->started_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $pallet->finished_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->palletized_at ? 'Sí' : 'No' }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->labeled_at ? 'Sí' : 'No' }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->receipt_status->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->audit_status->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $pallet->transfer_status->label() }}</flux:table.cell>
                            <flux:table.cell class="text-xs tabular-nums">
                                @if ($pallet->getAttribute('receipt_doc_num'))<div>Recibo {{ $pallet->getAttribute('receipt_doc_num') }}</div>@endif
                                @if ($pallet->getAttribute('transfer_doc_num'))<div>Traslado {{ $pallet->getAttribute('transfer_doc_num') }}</div>@endif
                                @if (! $pallet->getAttribute('receipt_doc_num') && ! $pallet->getAttribute('transfer_doc_num'))—@endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
