<?php

use App\Domain\Integration\ConversionFactors;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Pallets\PalletQr;
use App\Domain\Quality\Actions\HoldPallet;
use App\Domain\Quality\Actions\ReleasePallet;
use App\Domain\Quality\Enums\QualityHoldStatus;
use App\Domain\Quality\Enums\QualityStatus;
use App\Domain\Quality\Exceptions\QualityRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Transfers\Enums\TransferPurpose;
use App\Enums\Permission;
use App\Integrations\Sap\Dto\Quantity;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\ProductionReceiptPallet;
use App\Models\QualityHold;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Quality, before the blind count. Read one or more pallets' QR, see what
 * each holds (boxes and units), and hold them (each one moves from 04 to
 * the non-conforming warehouse with its own SAP transfer) or release the
 * ones in 11C so they go on to the count. Every decision carries a reason
 * and stays in each pallet's trace.
 */
new #[Title('Calidad')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::QualityManage->value), 403);
    }

    /** @var list<int> pallets read or picked, in order */
    #[Locked]
    public array $selected = [];

    #[Locked]
    public ?string $error = null;

    /** @var list<array{code: string, ok: bool, message: string}> */
    #[Locked]
    public array $results = [];

    public string $reason = '';

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    /**
     * A pallet QR read with the scanner field (hand reader or camera): added to the selection.
     */
    public function scanFrom(string $scanned, string $scanId): void
    {
        $this->reset('error', 'results');

        $token = PalletQr::tokenFrom(mb_substr($scanned, 0, 200));
        $id = $token === null ? null : Pallet::query()->where('qr_token', $token)->value('id');

        if ($id === null) {
            $this->error = ($token === null ? QualityRuleViolation::unreadableQr() : QualityRuleViolation::unknownPallet())->getMessage();

            return;
        }

        $this->add((int) $id);
    }

    public function add(int $palletId): void
    {
        if (! in_array($palletId, $this->selected, true) && Pallet::query()->whereKey($palletId)->exists()) {
            $this->selected[] = $palletId;
        }

        unset($this->selection);
    }

    public function addAllHeld(): void
    {
        foreach ($this->held as $hold) {
            if ($hold->status === QualityHoldStatus::NonConforming) {
                $this->add($hold->pallet_id);
            }
        }
    }

    public function remove(int $palletId): void
    {
        $this->selected = array_values(array_filter($this->selected, fn (int $id) => $id !== $palletId));
        unset($this->selection);
    }

    public function clear(): void
    {
        $this->reset('selected', 'results', 'error', 'reason');
        unset($this->selection);
    }

    /**
     * Each selected pallet with what quality needs to decide: units (from
     * the confirmed receipt, or the current factor before it), the open hold
     * and the transfer to the non-conforming warehouse.
     *
     * @return list<array{pallet: Pallet, units: string|null, units_per_box: string|null, uom: string, from_receipt: bool, hold: QualityHold|null, transfer: InventoryTransfer|null}>
     */
    #[Computed]
    public function selection(): array
    {
        $pallets = Pallet::query()->with('item:id,description,inventory_uom')->whereKey($this->selected)->get()->keyBy('id');
        $rows = [];

        foreach ($this->selected as $id) {
            $pallet = $pallets->get($id);

            if ($pallet === null) {
                continue;
            }

            $line = ProductionReceiptPallet::query()->where('pallet_id', $pallet->id)->where('hold_status', PalletHold::Confirmed)->first();
            $perBox = $line?->units_per_box ?? app(ConversionFactors::class)->current($pallet->item_id, now())?->units_per_box;

            $rows[] = [
                'pallet' => $pallet,
                'units' => $perBox === null || $pallet->boxes_count === 0 ? null : Quantity::ofBoxes($pallet->boxes_count, (string) $perBox)->display(),
                'units_per_box' => $perBox === null ? null : (new Quantity((string) $perBox))->display(),
                'uom' => $pallet->item->inventory_uom ?? 'UNIDAD',
                'from_receipt' => $line !== null,
                'hold' => QualityHold::query()->with('holder:id,name')->where('pallet_id', $pallet->id)->latest('id')->first(),
                'transfer' => InventoryTransfer::query()->where('purpose', TransferPurpose::NonConforming)->whereHas('lines', fn ($q) => $q->where('pallet_id', $pallet->id))->latest('id')->first(),
            ];
        }

        return $rows;
    }

    /**
     * Open holds: on their way to the non-conforming warehouse, or in it.
     *
     * @return Collection<int, QualityHold>
     */
    #[Computed]
    public function held(): Collection
    {
        return QualityHold::query()->with(['pallet:id,pallet_code,production_order_ref,item_code,lot_code,quality_status', 'holder:id,name'])
            ->whereIn('status', [QualityHoldStatus::Requested, QualityHoldStatus::NonConforming])->latest('held_at')->limit(200)->get();
    }

    public function hold(HoldPallet $hold): void
    {
        $this->decide(fn (int $id) => $hold->handle(Auth::user(), $id, $this->reason, $this->context()), 'Retenida: se traslada a no conforme ('.config('sap.warehouses.non_conforming').').');
    }

    public function release(ReleasePallet $release): void
    {
        $this->decide(fn (int $id) => $release->handle(Auth::user(), $id, $this->reason, $this->context()), 'Liberada: sigue al conteo ciego.');
    }

    /**
     * Applies the decision to every selected pallet, each on its own: one
     * that cannot take it says why and the others go ahead.
     */
    private function decide(callable $action, string $done): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:500']], attributes: ['reason' => 'motivo']);
        $this->reset('results', 'error');

        if ($this->selected === []) {
            $this->error = 'Lee al menos una estiba.';

            return;
        }

        $codes = Pallet::query()->whereKey($this->selected)->pluck('pallet_code', 'id');
        $ok = 0;

        foreach ($this->selected as $id) {
            try {
                $action($id);
                $this->results[] = ['code' => (string) $codes[$id], 'ok' => true, 'message' => $done];
                $ok++;
            } catch (QualityRuleViolation $e) {
                $this->results[] = ['code' => (string) $codes[$id], 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        Flux::toast(variant: $ok === count($this->selected) ? 'success' : 'warning', text: "{$ok} de ".count($this->selected).' estibas listas.');
        $this->reset('reason');
        unset($this->selection, $this->held);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Calidad</flux:heading>
        <flux:subheading>Lee las estibas antes del conteo ciego. Retener las traslada en SAP de {{ config('sap.warehouses.transfer_from') }} a no conforme ({{ config('sap.warehouses.non_conforming') }}). Liberarlas las deja seguir al conteo, y de ahí van de {{ config('sap.warehouses.non_conforming') }} a {{ config('sap.warehouses.transfer_to') }}.</flux:subheading>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-4 lg:col-span-2">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <x-scanner action="scanFrom" label="Etiqueta de la estiba" placeholder="Lee el QR de cada estiba; se van sumando a la lista" camera />
            </div>

            @if ($error)
                <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ $error }}</div>
            @endif

            @if ($results !== [])
                <div class="flex flex-col gap-1 rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700" role="status">
                    @foreach ($results as $r)
                        <div @class(['text-emerald-700 dark:text-emerald-400' => $r['ok'], 'text-red-700 dark:text-red-400' => ! $r['ok']])><span class="font-mono font-medium">{{ $r['code'] }}</span>: {{ $r['message'] }}</div>
                    @endforeach
                </div>
            @endif

            @if ($this->selection !== [])
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">Estibas leídas ({{ count($this->selection) }})</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clear">Limpiar</flux:button>
                </div>

                @foreach ($this->selection as $row)
                    @php($pallet = $row['pallet'])
                    <div wire:key="sel-{{ $pallet->id }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="font-mono text-2xl font-semibold">{{ $pallet->pallet_code }}</div>
                                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $pallet->item_code }} · {{ $pallet->item->description }}</div>
                                <div class="text-sm text-zinc-500 dark:text-zinc-400">Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:badge :color="$pallet->quality_status->color()">{{ $pallet->quality_status->label() }}</flux:badge>
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $pallet->id }})" aria-label="Quitar de la lista" />
                            </div>
                        </div>
                        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div class="rounded-lg bg-zinc-50 p-2 dark:bg-white/5"><dt class="text-xs text-zinc-500">Cajas</dt><dd class="text-xl font-semibold tabular-nums">{{ $pallet->boxes_count }}</dd></div>
                            <div class="rounded-lg bg-zinc-50 p-2 dark:bg-white/5">
                                <dt class="text-xs text-zinc-500">Unidades</dt>
                                <dd class="text-xl font-semibold tabular-nums">{{ $row['units'] ?? '—' }} <span class="text-xs font-normal text-zinc-500">{{ $row['uom'] }}</span></dd>
                                @if ($row['units_per_box'])<dd class="text-xs text-zinc-500">{{ $row['units_per_box'] }} por caja{{ $row['from_receipt'] ? ', según el recibo' : '' }}</dd>@endif
                            </div>
                            <div class="rounded-lg bg-zinc-50 p-2 dark:bg-white/5"><dt class="text-xs text-zinc-500">Recepción en 04</dt><dd class="text-sm font-medium">{{ $pallet->receipt_status->label() }}</dd></div>
                            <div class="rounded-lg bg-zinc-50 p-2 dark:bg-white/5"><dt class="text-xs text-zinc-500">Conteo ciego</dt><dd class="text-sm font-medium">{{ $pallet->audit_status->label() }}</dd></div>
                        </dl>
                        @if ($row['hold'] && $row['hold']->status->isOpen())
                            <div class="text-sm text-amber-800 dark:text-amber-300">Retenida por {{ $row['hold']->holder->name }} el {{ $row['hold']->held_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}: {{ $row['hold']->reason }}</div>
                        @endif
                        @if ($row['transfer'])
                            <div class="text-sm text-zinc-600 dark:text-zinc-300">
                                Traslado {{ $row['transfer']->from_warehouse }} → {{ $row['transfer']->to_warehouse }}: <strong>{{ $row['transfer']->status->label() }}</strong>@if ($row['transfer']->sap_doc_num) · DocNum {{ $row['transfer']->sap_doc_num }}@endif
                                @if ($row['transfer']->last_error_message)<div class="text-xs text-red-600">{{ $row['transfer']->last_error_message }}</div>@endif
                            </div>
                        @endif
                    </div>
                @endforeach

                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:textarea wire:model="reason" label="Motivo" rows="2" placeholder="Qué encontraste y por qué decides esto (aplica a todas las estibas leídas)" />
                    <div class="flex flex-wrap gap-2">
                        <flux:button variant="danger" icon="hand-raised" wire:click="hold" wire:loading.attr="disabled"
                            wire:confirm="¿Retener las estibas leídas? Cada una se traslada en SAP de {{ config('sap.warehouses.transfer_from') }} a {{ config('sap.warehouses.non_conforming') }}.">Retener (trasladar a {{ config('sap.warehouses.non_conforming') }})</flux:button>
                        <flux:button variant="primary" icon="check" wire:click="release" wire:loading.attr="disabled"
                            wire:confirm="¿Liberar las estibas leídas? Siguen al conteo ciego.">Liberar (sigue al conteo)</flux:button>
                    </div>
                    <flux:text class="text-xs">Cada estiba se procesa por separado: si una no puede, se dice por qué y las demás siguen.</flux:text>
                </div>
            @endif
        </div>

        <div>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg" level="2">Retenidas</flux:heading>
                @if ($this->held->isNotEmpty())<flux:button size="sm" variant="ghost" icon="plus" wire:click="addAllHeld">Agregar las que están en {{ config('sap.warehouses.non_conforming') }}</flux:button>@endif
            </div>
            @if ($this->held->isEmpty())
                <flux:text>No hay estibas retenidas.</flux:text>
            @else
                <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->held as $h)
                        <button type="button" wire:key="held-{{ $h->id }}" wire:click="add({{ $h->pallet_id }})" class="flex items-center justify-between gap-3 p-3 text-start hover:bg-zinc-50 dark:hover:bg-white/5">
                            <span>
                                <span class="font-mono font-medium whitespace-nowrap">{{ $h->pallet->pallet_code }}</span>
                                <span class="block text-xs text-zinc-500">Orden {{ $h->pallet->production_order_ref }} · Lote {{ $h->pallet->lot_code }} · {{ $h->holder->name }}, {{ $h->held_at->timezone(config('app.timezone'))->format('d/m H:i') }}</span>
                            </span>
                            <flux:badge size="sm" :color="$h->pallet->quality_status->color()">{{ $h->status->label() }}</flux:badge>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
