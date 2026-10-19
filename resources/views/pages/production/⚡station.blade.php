<?php

use App\Domain\Pallets\Actions\CloseManually;
use App\Domain\Pallets\Actions\ConfirmLabeled;
use App\Domain\Pallets\Actions\ConfirmPalletized;
use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Pallets\Enums\LabelConfirmation;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Production station: open pallets filling up, pallets waiting to be
 * palletized and labeled, and recent rejected reads. Every button calls a
 * use case that checks permission and state again on the server.
 */
new #[Title('Producción')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::PalletsOperate->value), 403);
    }

    #[Locked]
    public ?int $closingId = null;

    public string $closeReason = '';

    #[Locked]
    public ?int $confirmingId = null;

    #[Locked]
    public ?int $reprintingId = null;

    public string $reprintReason = '';

    public string $scannedQr = '';

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    /**
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function open(): Collection
    {
        return Pallet::query()->with('item:id,description')->where('physical_status', PhysicalStatus::Stacking)->orderBy('started_at')->get();
    }

    /**
     * Closed pallets still on the floor: waiting to be palletized or labeled.
     *
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function pending(): Collection
    {
        return Pallet::query()->with('item:id,description')
            ->whereIn('physical_status', [PhysicalStatus::Finished, PhysicalStatus::Palletized])
            ->orderBy('finished_at')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, PalletScanEvent>
     */
    #[Computed]
    public function rejected(): Collection
    {
        return PalletScanEvent::query()->with('device:id,code')
            ->where('processing_status', ScanEventStatus::Rejected)
            ->where('received_at', '>=', now()->subHours(8))
            ->latest('received_at')
            ->limit(10)
            ->get();
    }

    public function labelMode(): ?LabelConfirmation
    {
        return LabelConfirmation::configured();
    }

    public function rejectionLabel(?string $reason): string
    {
        return ScanRejection::tryFrom((string) $reason)?->label() ?? (string) $reason;
    }

    public function askClose(int $palletId): void
    {
        $this->closingId = $palletId;
        $this->closeReason = '';
        $this->resetValidation();
        Flux::modal('close-pallet')->show();
    }

    public function close(CloseManually $close): void
    {
        $this->validate(['closeReason' => ['nullable', 'string', 'max:300']], attributes: ['closeReason' => 'motivo']);

        $this->run(function () use ($close): string {
            $pallet = $close->handle(Auth::user(), (int) $this->closingId, $this->closeReason, $this->context());

            return "Estiba {$pallet->pallet_code} cerrada con {$pallet->boxes_count} cajas.";
        });

        Flux::modal('close-pallet')->close();
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
        Flux::modal('reprint-label')->show();
    }

    public function reprint(PrintLabel $print): void
    {
        $this->validate(['reprintReason' => ['required', 'string', 'max:300']], attributes: ['reprintReason' => 'motivo']);

        $this->print($print, (int) $this->reprintingId, $this->reprintReason);
    }

    /**
     * Records the print, then opens the printable label.
     */
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
        $this->scannedQr = '';
        $this->resetValidation();
        Flux::modal('confirm-label')->show();
    }

    public function confirmLabel(ConfirmLabeled $confirm): void
    {
        $this->validate(['scannedQr' => ['nullable', 'string', 'max:100']]);

        $ok = $this->run(fn () => 'Etiqueta de '.$confirm->handle(Auth::user(), (int) $this->confirmingId, $this->scannedQr, $this->context())->pallet_code.' confirmada.');

        if ($ok) {
            Flux::modal('confirm-label')->close();
        }
    }

    /**
     * Runs a use case and turns a rule violation into a message for the operator.
     */
    private function run(callable $operation): bool
    {
        try {
            Flux::toast(variant: 'success', text: $operation());
        } catch (PalletRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        } finally {
            unset($this->open, $this->pending);
        }

        return true;
    }
}; ?>

<section class="flex w-full flex-col gap-6" wire:poll.5s.visible>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Producción</flux:heading>
            <flux:subheading>Estibas en proceso y por despachar a paletizado</flux:subheading>
        </div>
        @if (! config('traceability.decisions.scan_ingestion_enabled'))
            <flux:badge color="amber" icon="exclamation-triangle" size="lg">Recepción de lecturas apagada (DP-01)</flux:badge>
        @endif
    </div>

    <div>
        <flux:heading size="lg" level="2" class="mb-3">En estibado</flux:heading>
        @if ($this->open->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay estibas abiertas.</div>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->open as $pallet)
                    @php($percent = $pallet->completionPercent())
                    <article wire:key="open-{{ $pallet->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-label="Estiba {{ $pallet->pallet_code }}">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="font-mono text-2xl font-semibold text-zinc-900 dark:text-white">{{ $pallet->pallet_code }}</div>
                                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $pallet->item_code }} · {{ $pallet->item->description }}</div>
                                <div class="text-sm text-zinc-500 dark:text-zinc-400">Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }}</div>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-4xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $pallet->boxes_count }}<span class="text-xl font-normal text-zinc-500"> / {{ $pallet->standard_boxes_snapshot }}</span></span>
                                <span class="text-lg tabular-nums text-zinc-600 dark:text-zinc-300">{{ number_format($percent, 1, ',', '.') }} %</span>
                            </div>
                            <div class="mt-2 h-4 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                                <div class="h-full bg-emerald-600" style="width: {{ min(100, $percent) }}%"></div>
                            </div>
                        </div>
                        <flux:button size="base" variant="filled" icon="lock-closed" wire:click="askClose({{ $pallet->id }})" :disabled="$pallet->boxes_count < 1">
                            Cerrar estiba incompleta
                        </flux:button>
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <flux:heading size="lg" level="2" class="mb-3">Por paletizar y etiquetar</flux:heading>
        @if ($this->pending->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Nada pendiente.</div>
        @else
            <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->pending as $pallet)
                    <div wire:key="pending-{{ $pallet->id }}" class="flex flex-wrap items-center justify-between gap-4 p-4">
                        <div>
                            <div class="font-mono text-lg font-semibold text-zinc-900 dark:text-white">{{ $pallet->pallet_code }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $pallet->item_code }} · Lote {{ $pallet->lot_code }} · {{ $pallet->boxes_count }} cajas ({{ $pallet->close_type?->label() }})</div>
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

    @if ($this->rejected->isNotEmpty())
        <div>
            <flux:heading size="lg" level="2" class="mb-3">Lecturas rechazadas (últimas 8 horas)</flux:heading>
            <div class="flex flex-col divide-y divide-red-200 rounded-xl border border-red-200 dark:divide-red-900 dark:border-red-900" role="alert">
                @foreach ($this->rejected as $event)
                    <div wire:key="rej-{{ $event->id }}" class="flex flex-wrap justify-between gap-2 p-3 text-sm">
                        <span class="font-medium text-red-700 dark:text-red-400">{{ $this->rejectionLabel($event->rejection_reason) }}</span>
                        <span class="text-zinc-600 dark:text-zinc-300">Orden {{ $event->production_order_ref }} · {{ $event->item_code }} · Lote {{ $event->lot_code }} · {{ $event->device->code }} · {{ $event->received_at->timezone(config('app.timezone'))->format('H:i:s') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <flux:modal name="close-pallet" class="w-full max-w-md">
        <form wire:submit="close" class="flex flex-col gap-4">
            <flux:heading size="lg">Cerrar estiba incompleta</flux:heading>
            <flux:text>La estiba se cierra con las cajas que tiene ahora. No recibe más lecturas y la siguiente caja abre una estiba nueva.</flux:text>
            <flux:input wire:model="closeReason" label="Motivo (opcional)" maxlength="300" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="close">Cerrar estiba</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reprint-label" class="w-full max-w-md">
        <form wire:submit="reprint" class="flex flex-col gap-4">
            <flux:heading size="lg">Reimprimir etiqueta</flux:heading>
            <flux:text>La reimpresión no crea otra estiba ni cambia la cantidad. Queda registrada con tu usuario y el motivo.</flux:text>
            <flux:input wire:model="reprintReason" label="Motivo" maxlength="300" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="reprint">Reimprimir</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-label" class="w-full max-w-md">
        <form wire:submit="confirmLabel" class="flex flex-col gap-4">
            <flux:heading size="lg">Confirmar etiqueta aplicada</flux:heading>
            @if ($this->labelMode() === LabelConfirmation::Scan)
                <flux:text>Lee con el escáner el QR de la etiqueta ya pegada en la estiba.</flux:text>
                <flux:input wire:model="scannedQr" label="Código leído" autofocus autocomplete="off" />
            @else
                <flux:text>Confirma que la etiqueta quedó pegada y legible en la estiba.</flux:text>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="confirmLabel">Confirmar</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
