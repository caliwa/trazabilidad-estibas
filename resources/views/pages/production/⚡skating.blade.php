<?php

use App\Domain\Pallets\Actions\ConfirmLabeled;
use App\Domain\Pallets\Actions\ConfirmPalletized;
use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Pallets\Enums\LabelConfirmation;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Production skater: takes the closed pallets from the line. Sees the
 * pallets still in stacking (only to know what is coming), palletizes the
 * closed ones, prints and confirms their label, and sends the labeled ones
 * to the production receipt. Reads no boxes and closes no pallets: that is
 * the production station. Every action calls the same use cases as the
 * station, which check the permission on the server.
 */
new #[Title('Patinaje')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::PalletsSkate->value), 403);
    }

    #[Locked]
    public ?int $reprintingId = null;

    public string $reprintReason = '';

    #[Locked]
    public ?int $confirmingId = null;

    #[Locked]
    public ?string $confirmError = null;

    #[Locked]
    public int $confirmTries = 0;

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    /**
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function stacking(): Collection
    {
        return Pallet::query()->with('item:id,description')->where('physical_status', PhysicalStatus::Stacking)->orderBy('started_at')->get();
    }

    /**
     * Closed, waiting to be palletized; palletized, waiting for their label.
     *
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function closed(): Collection
    {
        return Pallet::query()->with('item:id,description')
            ->whereIn('physical_status', [PhysicalStatus::Finished, PhysicalStatus::Palletized])
            ->orderBy('finished_at')
            ->limit(100)
            ->get();
    }

    /**
     * Labeled and not received yet (or the receipt failed): ready for the production receipt.
     *
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function forReceipt(): Collection
    {
        return Pallet::query()->with('item:id,description')
            ->where('physical_status', PhysicalStatus::Labeled)
            ->whereIn('receipt_status', [ReceiptStatus::None, ReceiptStatus::Failed])
            ->orderBy('labeled_at')
            ->limit(100)
            ->get();
    }

    public function labelMode(): ?LabelConfirmation
    {
        return LabelConfirmation::configured();
    }

    public function palletize(int $palletId, ConfirmPalletized $palletize): void
    {
        $this->run(fn () => 'Estiba '.$palletize->handle(Auth::user(), $palletId, $this->context())->pallet_code.' paletizada.');
    }

    public function printLabel(int $palletId, PrintLabel $print): void
    {
        $this->print($print, $palletId, null);
    }

    public function askReprint(int $palletId): void
    {
        $this->reprintingId = $palletId;
        $this->reprintReason = '';
        $this->resetValidation();
        Flux::modal('skate-reprint')->show();
    }

    public function reprint(PrintLabel $print): void
    {
        $this->validate(['reprintReason' => ['required', 'string', 'max:300']], attributes: ['reprintReason' => 'motivo']);

        $this->print($print, (int) $this->reprintingId, $this->reprintReason);
    }

    private function print(PrintLabel $print, int $palletId, ?string $reason): void
    {
        $printed = null;
        $this->run(function () use ($palletId, $print, $reason, &$printed): string {
            $printed = $print->handle(Auth::user(), $palletId, $reason, $this->context());

            return 'Etiqueta generada.';
        });

        if ($printed !== null) {
            $this->redirectRoute('pallets.label', ['pallet' => $palletId, 'print' => $printed->print_number]);
        }
    }

    public function askConfirmLabel(int $palletId): void
    {
        $this->confirmingId = $palletId;
        $this->confirmError = null;
        $this->resetValidation();
        Flux::modal('skate-confirm-label')->show();
    }

    /**
     * With "scan", one box read (PDA, hand reader or camera) from the pallet
     * where the label was stuck; it must be a box of that pallet. With
     * "button", the confirmation alone.
     */
    public function confirmLabel(ConfirmLabeled $confirm, ?string $scanned = null, ?string $scanId = null): void
    {
        try {
            $pallet = $confirm->handle(Auth::user(), (int) $this->confirmingId, $scanned, $this->context());
        } catch (PalletRuleViolation $e) {
            $this->confirmError = $e->getMessage();
            $this->confirmTries++;

            return;
        } finally {
            unset($this->closed, $this->forReceipt);
        }

        $this->confirmError = null;
        Flux::modal('skate-confirm-label')->close();
        Flux::toast(variant: 'success', text: "Etiqueta de {$pallet->pallet_code} confirmada en la estiba correcta.");
    }

    /** The box read in the confirmation dialog (x-scanner calls it with the text read). */
    public function confirmLabelRead(string $scanned, ?string $scanId = null): void
    {
        $this->confirmLabel(app(ConfirmLabeled::class), $scanned, $scanId);
    }

    private function run(callable $operation): bool
    {
        try {
            Flux::toast(variant: 'success', text: $operation());
        } catch (PalletRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        } finally {
            unset($this->stacking, $this->closed, $this->forReceipt);
        }

        return true;
    }
}; ?>

@php
    $toPalletize = $this->closed->where('physical_status', PhysicalStatus::Finished);
    $toLabel = $this->closed->where('physical_status', PhysicalStatus::Palletized);
@endphp

{{-- Every 5 s: pallets close on the line while the skater works. --}}
<section class="flex w-full flex-col gap-6" wire:poll.5s.visible>
    <div>
        <flux:heading size="xl" level="1">Patinaje</flux:heading>
        <flux:subheading>Estibas en proceso y cerradas: paletizar, etiquetar y enviar a recibo de producción</flux:subheading>
    </div>

    {{-- What is waiting, at a glance --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['En estibado', $this->stacking->count(), 'Se llenan en la línea', 'border-zinc-200 dark:border-zinc-700', 'squares-2x2'],
            ['Por paletizar', $toPalletize->count(), 'Cerradas, listas para envolver', $toPalletize->isNotEmpty() ? 'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950' : 'border-zinc-200 dark:border-zinc-700', 'cube'],
            ['Por etiquetar', $toLabel->count(), 'Paletizadas, falta la etiqueta', $toLabel->isNotEmpty() ? 'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950' : 'border-zinc-200 dark:border-zinc-700', 'printer'],
            ['Para recibo', $this->forReceipt->count(), 'Etiquetadas, sin recibo en SAP', $this->forReceipt->isNotEmpty() ? 'border-blue-400 bg-blue-50 dark:border-blue-600 dark:bg-blue-950' : 'border-zinc-200 dark:border-zinc-700', 'inbox-arrow-down'],
        ] as [$title, $count, $hint, $classes, $icon])
            <div class="rounded-xl border-2 p-5 {{ $classes }}" data-skate-tile="{{ $title }}">
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-300"><flux:icon :name="$icon" variant="mini" /> {{ $title }}</div>
                <div class="mt-1 text-4xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $count }}</div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $hint }}</div>
            </div>
        @endforeach
    </div>

    <div>
        <flux:heading size="lg" level="2" class="mb-3">Por paletizar y etiquetar</flux:heading>
        @if ($this->closed->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Nada pendiente. Las estibas aparecen aquí cuando se cierran en la línea.</div>
        @else
            <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->closed as $pallet)
                    <div wire:key="skate-closed-{{ $pallet->id }}" class="flex flex-wrap items-center justify-between gap-4 p-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-lg font-semibold text-zinc-900 dark:text-white">{{ $pallet->pallet_code }}</span>
                                @if ($pallet->physical_status === PhysicalStatus::Finished)
                                    <flux:badge size="sm" color="amber">Por paletizar</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">{{ $pallet->label_print_count === 0 ? 'Por imprimir etiqueta' : 'Etiqueta impresa, por confirmar' }}</flux:badge>
                                @endif
                            </div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $pallet->item_code }} · {{ $pallet->item->description }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }} · {{ $pallet->boxes_count }} cajas ({{ $pallet->close_type?->label() }})</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($pallet->physical_status === PhysicalStatus::Finished)
                                @can('pallets.palletize')
                                    <flux:button variant="primary" icon="cube" wire:click="palletize({{ $pallet->id }})" wire:loading.attr="disabled" wire:target="palletize({{ $pallet->id }})">Confirmar paletizado</flux:button>
                                @endcan
                            @else
                                @can('pallets.label')
                                    @if ($pallet->label_print_count === 0)
                                        <flux:button variant="primary" icon="printer" wire:click="printLabel({{ $pallet->id }})" wire:loading.attr="disabled" wire:target="printLabel({{ $pallet->id }})">Imprimir etiqueta</flux:button>
                                    @else
                                        @can('pallets.reprint')
                                            <flux:button icon="printer" wire:click="askReprint({{ $pallet->id }})">Reimprimir</flux:button>
                                        @endcan
                                        @if ($this->labelMode() === LabelConfirmation::Button || $this->labelMode() === LabelConfirmation::Scan)
                                            <flux:button variant="primary" icon="check" wire:click="askConfirmLabel({{ $pallet->id }})">Confirmar etiqueta aplicada</flux:button>
                                        @elseif ($this->labelMode() === null)
                                            <flux:badge color="zinc">Confirmación de etiqueta pendiente (DP-03)</flux:badge>
                                        @endif
                                    @endif
                                @endcan
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" level="2">Listas para recibo de producción</flux:heading>
            @can('receipts.request')
                <flux:button size="sm" icon="inbox-arrow-down" :href="route('receipts.index')" wire:navigate>Ir a Recibos de producción</flux:button>
            @endcan
        </div>
        @if ($this->forReceipt->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay estibas etiquetadas esperando recibo.</div>
        @else
            <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->forReceipt->groupBy('production_order_id') as $pallets)
                    @php
                        $first = $pallets->first();
                    @endphp
                    <div wire:key="skate-receipt-{{ $first->production_order_id }}" class="flex flex-wrap items-center justify-between gap-4 p-4">
                        <div>
                            <div class="font-medium text-zinc-900 dark:text-white">Orden {{ $first->production_order_ref }} · {{ $first->item_code }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $pallets->count() }} {{ $pallets->count() === 1 ? 'estiba' : 'estibas' }}: {{ $pallets->pluck('pallet_code')->implode(', ') }}@if ($pallets->contains(fn ($p) => $p->receipt_status === ReceiptStatus::Failed)) · algún recibo anterior falló @endif</div>
                        </div>
                        @can('receipts.request')
                            <flux:button size="sm" variant="primary" icon="paper-airplane" :href="route('receipts.index', ['orderId' => $first->production_order_id])" wire:navigate>Generar recibo</flux:button>
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <flux:heading size="lg" level="2" class="mb-3">En estibado (solo consulta)</flux:heading>
        @if ($this->stacking->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay estibas abiertas en la línea.</div>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->stacking as $pallet)
                    @php
                        $percent = $pallet->completionPercent();
                    @endphp
                    <article wire:key="skate-open-{{ $pallet->id }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-label="Estiba {{ $pallet->pallet_code }}">
                        <div>
                            <div class="font-mono text-xl font-semibold text-zinc-900 dark:text-white">{{ $pallet->pallet_code }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $pallet->item_code }} · Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }}</div>
                        </div>
                        <div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $pallet->boxes_count }}<span class="text-base font-normal text-zinc-500"> / {{ $pallet->standard_boxes_snapshot }}</span></span>
                                <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ number_format($percent, 1, ',', '.') }} %</span>
                            </div>
                            <div class="mt-1 h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                                <div class="h-full bg-emerald-600" style="width: {{ min(100, $percent) }}%"></div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    <flux:modal name="skate-reprint" class="w-full max-w-md">
        <form wire:submit="reprint" class="flex flex-col gap-4">
            <flux:heading size="lg">Reimprimir etiqueta de estiba</flux:heading>
            <flux:text>La reimpresión no crea otra estiba ni cambia la cantidad. Queda registrada con tu usuario y el motivo.</flux:text>
            <flux:input wire:model="reprintReason" label="Motivo" maxlength="300" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="reprint">Reimprimir</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="skate-confirm-label" class="w-full max-w-lg">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">Confirmar etiqueta aplicada{{ $confirmingId ? ' · '.$this->closed->firstWhere('id', $confirmingId)?->pallet_code : '' }}</flux:heading>
            @if ($this->labelMode() === LabelConfirmation::Scan)
                <flux:text>Lee el QR de <strong>una caja de la estiba donde pegaste la etiqueta</strong> (mejor del centro, no del borde). La aplicación comprueba que esa caja sea de esta estiba.</flux:text>
                @if ($confirmError)
                    <div wire:key="skate-confirm-error-{{ $confirmTries }}" role="alert" data-label-result="error" class="flex items-center gap-3 rounded-xl border-4 border-red-700 bg-red-600 p-4 text-white shadow-lg">
                        <flux:icon.x-circle class="size-10 shrink-0" />
                        <div>
                            <div class="font-bold uppercase tracking-wide">Etiqueta no confirmada</div>
                            <div class="text-sm">{{ $confirmError }}</div>
                        </div>
                    </div>
                @endif
                <x-scanner action="confirmLabelRead" label="QR de una caja de la estiba" placeholder="Lee el QR con la PDA o el lector" camera claim-focus autofocus wire:key="skate-confirm-scanner-{{ $confirmTries }}" />
                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                </div>
            @else
                <flux:text>Confirma que la etiqueta quedó pegada y legible en la estiba.</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="confirmLabel" wire:loading.attr="disabled" wire:target="confirmLabel">Confirmar</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</section>
