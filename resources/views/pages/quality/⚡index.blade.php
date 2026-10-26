<?php

use App\Domain\Integration\ConversionFactors;
use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Pallets\PalletQr;
use App\Domain\Quality\Actions\HoldPallet;
use App\Domain\Quality\Actions\ReleasePallet;
use App\Domain\Quality\Actions\SendToNonConforming;
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
 * Quality, before the blind count: read a labeled pallet's QR, see what it
 * holds (boxes and units), and hold it, release it, or send it from 04 to
 * the non-conforming warehouse. Every decision carries a reason and stays
 * in the pallet's trace.
 */
new #[Title('Calidad')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::QualityManage->value), 403);
    }

    #[Locked]
    public ?int $palletId = null;

    #[Locked]
    public ?string $error = null;

    public string $reason = '';

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    /**
     * The pallet QR read with the scanner field (hand reader or camera).
     */
    public function scanFrom(string $scanned, string $scanId): void
    {
        $this->reset('error', 'reason');
        $this->resetValidation();

        $token = PalletQr::tokenFrom(mb_substr($scanned, 0, 200));
        $pallet = $token === null ? null : Pallet::query()->where('qr_token', $token)->first();

        if ($pallet === null) {
            $this->error = ($token === null ? QualityRuleViolation::unreadableQr() : QualityRuleViolation::unknownPallet())->getMessage();
            $this->palletId = null;

            return;
        }

        $this->palletId = $pallet->id;
        unset($this->pallet, $this->detail);
    }

    public function select(int $palletId): void
    {
        $this->reset('error', 'reason');
        $this->resetValidation();
        $this->palletId = $palletId;
        unset($this->pallet, $this->detail);
    }

    #[Computed]
    public function pallet(): ?Pallet
    {
        return $this->palletId === null ? null : Pallet::query()->with('item:id,description,inventory_uom')->find($this->palletId);
    }

    /**
     * What quality needs to decide: units (from the confirmed receipt, or the
     * current factor before it), the open hold and the transfer to 11C.
     *
     * @return array{units: string|null, units_per_box: string|null, uom: string, from_receipt: bool, hold: QualityHold|null, transfer: InventoryTransfer|null}
     */
    #[Computed]
    public function detail(): array
    {
        $pallet = $this->pallet;
        $empty = ['units' => null, 'units_per_box' => null, 'uom' => '', 'from_receipt' => false, 'hold' => null, 'transfer' => null];

        if ($pallet === null) {
            return $empty;
        }

        $line = ProductionReceiptPallet::query()->where('pallet_id', $pallet->id)->where('hold_status', PalletHold::Confirmed)->first();
        $perBox = $line?->units_per_box ?? app(ConversionFactors::class)->current($pallet->item_id, now())?->units_per_box;

        return [
            'units' => $perBox === null || $pallet->boxes_count === 0 ? null : Quantity::ofBoxes($pallet->boxes_count, (string) $perBox)->display(),
            'units_per_box' => $perBox === null ? null : (new Quantity((string) $perBox))->display(),
            'uom' => $pallet->item->inventory_uom ?? 'UNIDAD',
            'from_receipt' => $line !== null,
            'hold' => QualityHold::query()->with(['holder:id,name'])->where('pallet_id', $pallet->id)->latest('id')->first(),
            'transfer' => InventoryTransfer::query()->where('purpose', TransferPurpose::NonConforming)->whereHas('lines', fn ($q) => $q->where('pallet_id', $pallet->id))->latest('id')->first(),
        ];
    }

    /**
     * @return Collection<int, QualityHold>
     */
    #[Computed]
    public function held(): Collection
    {
        return QualityHold::query()->with(['pallet:id,pallet_code,production_order_ref,item_code,lot_code,quality_status', 'holder:id,name'])
            ->where('status', QualityHoldStatus::Open)->latest('held_at')->limit(100)->get();
    }

    public function hold(HoldPallet $hold): void
    {
        $this->decide(fn () => $hold->handle(Auth::user(), (int) $this->palletId, $this->reason, $this->context()), 'Estiba retenida. No se cuenta ni se traslada a 05 hasta que la liberes.');
    }

    public function release(ReleasePallet $release): void
    {
        $this->decide(fn () => $release->handle(Auth::user(), (int) $this->palletId, $this->reason, $this->context()), 'Estiba liberada. Sigue al conteo ciego.');
    }

    public function sendToNonConforming(SendToNonConforming $send): void
    {
        $this->decide(fn () => $send->handle(Auth::user(), (int) $this->palletId, $this->reason, $this->context()), 'Traslado a no conforme solicitado. Se envía a SAP en segundo plano.');
    }

    private function decide(callable $action, string $success): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:500']], attributes: ['reason' => 'motivo']);

        try {
            $action();
        } catch (QualityRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $success);
        $this->reset('reason');
        unset($this->pallet, $this->detail, $this->held);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Calidad</flux:heading>
        <flux:subheading>Lee la estiba antes del conteo ciego. Puedes retenerla, liberarla o enviarla a la bodega de no conforme ({{ config('sap.warehouses.non_conforming') }}).</flux:subheading>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-4">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <x-scanner action="scanFrom" label="Etiqueta de la estiba" placeholder="Lee el QR de la etiqueta de la estiba" />
            </div>

            @if ($error)
                <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ $error }}</div>
            @endif

            @if ($this->pallet)
                @php($pallet = $this->pallet)
                @php($d = $this->detail)
                <div wire:key="pallet-{{ $pallet->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="font-mono text-3xl font-semibold">{{ $pallet->pallet_code }}</div>
                            <div class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $pallet->item_code }} · {{ $pallet->item->description }}</div>
                            <div class="text-zinc-500 dark:text-zinc-400">Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }}</div>
                        </div>
                        <flux:badge :color="$pallet->quality_status->color()">{{ $pallet->quality_status->label() }}</flux:badge>
                    </div>

                    <dl class="grid grid-cols-2 gap-3">
                        <div class="rounded-lg bg-zinc-50 p-3 dark:bg-white/5"><dt class="text-xs text-zinc-500">Cajas</dt><dd class="text-2xl font-semibold tabular-nums">{{ $pallet->boxes_count }}</dd></div>
                        <div class="rounded-lg bg-zinc-50 p-3 dark:bg-white/5">
                            <dt class="text-xs text-zinc-500">Unidades</dt>
                            <dd class="text-2xl font-semibold tabular-nums">{{ $d['units'] ?? '—' }} <span class="text-sm font-normal text-zinc-500">{{ $d['uom'] }}</span></dd>
                            @if ($d['units_per_box'])<dd class="text-xs text-zinc-500">{{ $d['units_per_box'] }} por caja{{ $d['from_receipt'] ? ', según el recibo en SAP' : '' }}</dd>@endif
                        </div>
                        <div class="rounded-lg bg-zinc-50 p-3 dark:bg-white/5"><dt class="text-xs text-zinc-500">Recepción en 04</dt><dd class="font-medium">{{ $pallet->receipt_status->label() }}</dd></div>
                        <div class="rounded-lg bg-zinc-50 p-3 dark:bg-white/5"><dt class="text-xs text-zinc-500">Conteo ciego</dt><dd class="font-medium">{{ $pallet->audit_status->label() }}</dd></div>
                    </dl>

                    @if ($d['hold'] && $d['hold']->status === QualityHoldStatus::Open)
                        <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                            Retenida por {{ $d['hold']->holder->name }} el {{ $d['hold']->held_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}: {{ $d['hold']->reason }}
                        </div>
                    @endif

                    @if ($d['transfer'])
                        <div class="text-sm text-zinc-600 dark:text-zinc-300">
                            Traslado {{ $d['transfer']->from_warehouse }} → {{ $d['transfer']->to_warehouse }}: <strong>{{ $d['transfer']->status->label() }}</strong>
                            @if ($d['transfer']->sap_doc_num) · DocNum {{ $d['transfer']->sap_doc_num }}@endif
                            @if ($d['transfer']->last_error_message)<div class="text-xs text-red-600">{{ $d['transfer']->last_error_message }}</div>@endif
                        </div>
                    @endif

                    @if (in_array($pallet->quality_status, [QualityStatus::None, QualityStatus::Released, QualityStatus::Held], true))
                        <flux:textarea wire:model="reason" label="Motivo" rows="2" placeholder="Qué encontraste y por qué decides esto" />
                        <div class="flex flex-wrap gap-2">
                            @if ($pallet->quality_status === QualityStatus::Held)
                                <flux:button variant="primary" icon="check" wire:click="release" wire:loading.attr="disabled">Liberar</flux:button>
                                <flux:button variant="danger" icon="arrow-right-circle" wire:click="sendToNonConforming" wire:loading.attr="disabled"
                                    wire:confirm="¿Trasladar en SAP esta estiba de {{ config('sap.warehouses.transfer_from') }} a {{ config('sap.warehouses.non_conforming') }} (no conforme)?">Enviar a no conforme ({{ config('sap.warehouses.non_conforming') }})</flux:button>
                            @else
                                <flux:button variant="primary" icon="hand-raised" wire:click="hold" wire:loading.attr="disabled">Retener</flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <flux:heading size="lg" level="2" class="mb-3">Estibas retenidas</flux:heading>
            @if ($this->held->isEmpty())
                <flux:text>No hay estibas retenidas.</flux:text>
            @else
                <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->held as $h)
                        <button type="button" wire:key="held-{{ $h->id }}" wire:click="select({{ $h->pallet_id }})" class="flex items-center justify-between gap-3 p-3 text-start hover:bg-zinc-50 dark:hover:bg-white/5">
                            <span>
                                <span class="font-mono font-medium">{{ $h->pallet->pallet_code }}</span>
                                <span class="block text-xs text-zinc-500">Orden {{ $h->pallet->production_order_ref }} · Lote {{ $h->pallet->lot_code }} · {{ $h->holder->name }}, {{ $h->held_at->timezone(config('app.timezone'))->format('d/m H:i') }}</span>
                            </span>
                            <flux:badge size="sm" :color="$h->pallet->quality_status->color()">{{ $h->pallet->quality_status->label() }}</flux:badge>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
