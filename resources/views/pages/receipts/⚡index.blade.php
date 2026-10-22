<?php

use App\Domain\Integration\ConversionFactors;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Actions\RequestProductionReceipts;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Receipts\Exceptions\ReceiptRuleViolation;
use App\Domain\Receipts\ProductionReceiptLifecycle;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Integrations\Sap\Dto\Quantity;
use App\Jobs\ReconcileProductionReceipt;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Production receipts into warehouse 04. Requests are stored and sent by
 * a job: this page never waits for SAP.
 */
new #[Title('Recibos de producción')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::ReceiptsRequest->value), 403);
    }

    #[Url(except: null)]
    public ?int $orderId = null;

    /** @var list<int> */
    public array $selected = [];

    /** "grouped": one receipt with every pallet; "per_pallet": one receipt each. */
    public string $documents = 'grouped';

    #[Locked]
    public ?int $failingId = null;

    public string $failReason = '';

    public function updatedOrderId(): void
    {
        $this->selected = [];
    }

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    /**
     * Open orders with labeled pallets that can be received.
     *
     * @return Collection<int, ProductionOrder>
     */
    #[Computed]
    public function orders(): Collection
    {
        return ProductionOrder::query()->with('item:id,code,description')
            ->where('is_open', true)
            ->whereHas('pallets', $eligible = fn ($q) => $q->where('physical_status', PhysicalStatus::Labeled)->whereIn('receipt_status', [ReceiptStatus::None, ReceiptStatus::Failed]))
            ->withCount(['pallets as eligible_count' => $eligible])
            ->orderBy('ref')
            ->get();
    }

    /**
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function eligible(): Collection
    {
        if ($this->orderId === null) {
            return new Collection;
        }

        return Pallet::query()
            ->where('production_order_id', $this->orderId)
            ->where('physical_status', PhysicalStatus::Labeled)
            ->whereIn('receipt_status', [ReceiptStatus::None, ReceiptStatus::Failed])
            ->orderBy('pallet_code')
            ->get();
    }

    /**
     * What the SAP lines would look like for the current selection.
     *
     * @return list<array{item_code: string, lot_code: string, pallets: int, boxes: int, factor: string|null, quantity: string|null}>
     */
    #[Computed]
    public function preview(): array
    {
        $factors = app(ConversionFactors::class);
        $lines = [];

        foreach ($this->eligible->whereIn('id', $this->selected) as $pallet) {
            $key = "{$pallet->item_code}|{$pallet->lot_code}";
            $factor = $factors->current($pallet->item_id, now());
            $lines[$key] ??= ['item_code' => $pallet->item_code, 'lot_code' => $pallet->lot_code, 'pallets' => 0, 'boxes' => 0, 'factor' => $factor === null ? null : (string) $factor->units_per_box, 'quantity' => null];
            $lines[$key]['pallets']++;
            $lines[$key]['boxes'] += $pallet->boxes_count;
            $lines[$key]['quantity'] = $lines[$key]['factor'] === null ? null : Quantity::ofBoxes($lines[$key]['boxes'], $lines[$key]['factor'])->value;
        }

        return array_values($lines);
    }

    /**
     * @return Collection<int, ProductionReceipt>
     */
    #[Computed]
    public function receipts(): Collection
    {
        return ProductionReceipt::query()->with('productionOrder:id,ref')->withCount('lines')->latest('requested_at')->latest('id')->limit(50)->get();
    }

    public function selectAll(): void
    {
        $this->selected = $this->eligible->pluck('id')->all();
    }

    public function request(RequestProductionReceipts $request): void
    {
        try {
            $created = $request->handle(Auth::user(), $this->selected, $this->documents === 'per_pallet', $this->context());
        } catch (ReceiptRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: count($created) === 1 ? 'Recibo solicitado. Se envía a SAP en segundo plano.' : count($created).' recibos solicitados. Se envían a SAP en segundo plano.');
        $this->selected = [];
        unset($this->eligible, $this->orders, $this->receipts);
    }

    public function reconcile(int $receiptId): void
    {
        abort_unless(Auth::user()?->can(Permission::ReceiptsReconcile->value), 403);

        ReconcileProductionReceipt::dispatch($receiptId);
        Flux::toast(text: 'Conciliación en cola.');
        unset($this->receipts);
    }

    public function askFail(int $receiptId): void
    {
        abort_unless(Auth::user()?->can(Permission::ReceiptsReconcile->value), 403);

        $this->failingId = $receiptId;
        $this->failReason = '';
        $this->resetValidation();
        Flux::modal('fail-receipt')->show();
    }

    public function fail(ProductionReceiptLifecycle $lifecycle): void
    {
        abort_unless(Auth::user()?->can(Permission::ReceiptsReconcile->value), 403);
        $this->validate(['failReason' => ['required', 'string', 'max:300']], attributes: ['failReason' => 'motivo']);

        try {
            $lifecycle->failAfterProof((int) $this->failingId, $this->context(), $this->failReason);
        } catch (ReceiptRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::modal('fail-receipt')->close();
        Flux::toast(variant: 'success', text: 'Recibo marcado como fallido. Las estibas quedaron libres.');
        unset($this->receipts, $this->eligible, $this->orders);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Recibos de producción</flux:heading>
        <flux:subheading>Estibas etiquetadas que entran al almacén {{ config('sap.warehouses.production_receipt') }} en SAP</flux:subheading>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-4 lg:col-span-2">
            {{-- A real empty option, not Flux's disabled placeholder: with that one the browser showed the first order as chosen while none was, and picking it changed nothing. --}}
            <flux:select wire:model.live="orderId" label="Orden de producción">
                <flux:select.option value="">Elige una orden</flux:select.option>
                @foreach ($this->orders as $order)
                    <flux:select.option :value="$order->id">{{ $order->ref }} · {{ $order->item->code }} {{ $order->item->description }} ({{ $order->eligible_count }})</flux:select.option>
                @endforeach
            </flux:select>

            @if ($orderId !== null)
                @if ($this->eligible->isEmpty())
                    <flux:text>Esta orden no tiene estibas etiquetadas por recibir.</flux:text>
                @else
                    <div class="flex items-center justify-between">
                        <flux:text>{{ count($selected) }} de {{ $this->eligible->count() }} seleccionadas</flux:text>
                        <flux:button size="sm" variant="ghost" wire:click="selectAll">Seleccionar todas</flux:button>
                    </div>
                    <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($this->eligible as $pallet)
                            <label wire:key="el-{{ $pallet->id }}" class="flex cursor-pointer items-center gap-3 p-3">
                                <flux:checkbox wire:model.live="selected" :value="$pallet->id" />
                                <span class="font-mono font-medium">{{ $pallet->pallet_code }}</span>
                                <span class="text-sm text-zinc-500">Lote {{ $pallet->lot_code }} · {{ $pallet->boxes_count }} cajas @if ($pallet->receipt_status === ReceiptStatus::Failed)· intento anterior fallido @endif</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg" level="2">Vista previa</flux:heading>
            @forelse ($this->preview as $line)
                <div class="text-sm">
                    <div class="font-medium">{{ $line['item_code'] }} · lote {{ $line['lot_code'] }}</div>
                    <div class="text-zinc-500">{{ $line['pallets'] }} estiba(s), {{ $line['boxes'] }} cajas ×
                        @if ($line['factor'] === null)<span class="text-red-600">sin factor vigente (DP-02)</span>@else{{ rtrim(rtrim($line['factor'], '0'), '.') }} = <span class="font-medium text-zinc-800 dark:text-white">{{ $line['quantity'] }}</span> unidades @endif
                    </div>
                </div>
            @empty
                <flux:text>Selecciona estibas para ver las líneas que irán a SAP.</flux:text>
            @endforelse

            <flux:radio.group wire:model="documents" label="Documentos">
                <flux:radio value="grouped" label="Un recibo con todas las estibas" />
                <flux:radio value="per_pallet" label="Un recibo por estiba" />
            </flux:radio.group>

            <flux:button variant="primary" icon="paper-airplane" wire:click="request" wire:loading.attr="disabled" wire:target="request"
                wire:confirm="¿Solicitar el recibo en SAP para las estibas seleccionadas?" :disabled="$selected === []">
                Solicitar recibo
            </flux:button>
        </div>
    </div>

    <div>
        <flux:heading size="lg" level="2" class="mb-3">Últimos recibos</flux:heading>
        @if ($this->receipts->isEmpty())
            <flux:text>Todavía no hay recibos.</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Solicitado</flux:table.column>
                    <flux:table.column>Orden</flux:table.column>
                    <flux:table.column align="end">Estibas</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                    <flux:table.column>DocNum</flux:table.column>
                    <flux:table.column>Detalle</flux:table.column>
                    <flux:table.column><span class="sr-only">Acciones</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->receipts as $receipt)
                        <flux:table.row :key="$receipt->id">
                            <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $receipt->requested_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $receipt->productionOrder->ref }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $receipt->lines_count }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="match ($receipt->status) { SapDocumentStatus::Confirmed => 'green', SapDocumentStatus::Failed => 'red', SapDocumentStatus::PendingReconciliation => 'amber', default => 'zinc' }">{{ $receipt->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="tabular-nums">{{ $receipt->sap_doc_num ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="max-w-72 whitespace-normal text-xs text-zinc-500">
                                {{ $receipt->last_error_message }}
                                @if (($receipt->reconciliation['mismatch'] ?? false) === true)<div class="font-medium text-red-600">El documento en SAP no coincide: revisar a mano.</div>@endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($receipt->status === SapDocumentStatus::PendingReconciliation)
                                    @can('receipts.reconcile')
                                        <div class="flex justify-end gap-1">
                                            <flux:button size="sm" icon="arrow-path" wire:click="reconcile({{ $receipt->id }})">Conciliar</flux:button>
                                            <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="askFail({{ $receipt->id }})">Marcar fallido</flux:button>
                                        </div>
                                    @endcan
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <flux:modal name="fail-receipt" class="w-full max-w-md">
        <form wire:submit="fail" class="flex flex-col gap-4">
            <flux:heading size="lg">Marcar recibo como fallido</flux:heading>
            <flux:text>Solo se acepta si SAP confirma que el documento no existe. Las estibas quedan libres para pedirse otra vez.</flux:text>
            <flux:input wire:model="failReason" label="Motivo" maxlength="300" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="fail">Marcar fallido</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
