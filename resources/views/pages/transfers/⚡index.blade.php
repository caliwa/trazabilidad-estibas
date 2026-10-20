<?php

use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Transfers\Actions\RequestInventoryTransfers;
use App\Domain\Transfers\Enums\TransferMode;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Domain\Transfers\InventoryTransferLifecycle;
use App\Enums\Permission;
use App\Jobs\ReconcileInventoryTransfer;
use App\Models\InventoryTransfer;
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
 * Transfers from warehouse 04 to 05 for audited pallets. Requests are
 * stored and sent by a job: this page never waits for SAP.
 */
new #[Title('Traslados')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::TransfersRequest->value), 403);
    }

    /** @var list<int> */
    public array $selected = [];

    public bool $grouped = false;

    public bool $groupingConfirmed = false;

    #[Locked]
    public ?int $failingId = null;

    public string $failReason = '';

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    public function groupingEnabled(): bool
    {
        return config()->boolean('traceability.decisions.grouped_transfers_enabled');
    }

    /**
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function eligible(): Collection
    {
        return Pallet::query()->with('item:id,description')
            ->whereIn('transfer_status', [TransferStatus::Ready, TransferStatus::Failed])
            ->orderBy('item_code')->orderBy('lot_code')->orderBy('pallet_code')
            ->limit(200)
            ->get();
    }

    /**
     * @return Collection<int, InventoryTransfer>
     */
    #[Computed]
    public function transfers(): Collection
    {
        return InventoryTransfer::query()->withCount('lines')->latest('requested_at')->latest('id')->limit(50)->get();
    }

    public function request(RequestInventoryTransfers $request): void
    {
        $mode = $this->grouped ? TransferMode::Grouped : TransferMode::Individual;

        try {
            $created = $request->handle(Auth::user(), $this->selected, $mode, $this->groupingConfirmed, $this->context());
        } catch (TransferRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: count($created) === 1 ? 'Traslado solicitado. Se envía a SAP en segundo plano.' : count($created).' traslados solicitados. Se envían a SAP en segundo plano.');
        $this->reset('selected', 'grouped', 'groupingConfirmed');
        unset($this->eligible, $this->transfers);
    }

    public function reconcile(int $transferId): void
    {
        abort_unless(Auth::user()?->can(Permission::TransfersReconcile->value), 403);

        ReconcileInventoryTransfer::dispatch($transferId);
        Flux::toast(text: 'Conciliación en cola.');
        unset($this->transfers);
    }

    public function askFail(int $transferId): void
    {
        abort_unless(Auth::user()?->can(Permission::TransfersReconcile->value), 403);

        $this->failingId = $transferId;
        $this->failReason = '';
        $this->resetValidation();
        Flux::modal('fail-transfer')->show();
    }

    public function fail(InventoryTransferLifecycle $lifecycle): void
    {
        abort_unless(Auth::user()?->can(Permission::TransfersReconcile->value), 403);
        $this->validate(['failReason' => ['required', 'string', 'max:300']], attributes: ['failReason' => 'motivo']);

        try {
            $lifecycle->failAfterProof((int) $this->failingId, $this->context(), $this->failReason);
        } catch (TransferRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::modal('fail-transfer')->close();
        Flux::toast(variant: 'success', text: 'Traslado marcado como fallido. Las estibas quedaron libres.');
        unset($this->transfers, $this->eligible);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Traslados</flux:heading>
        <flux:subheading>Estibas auditadas del almacén {{ config('sap.warehouses.transfer_from') }} al {{ config('sap.warehouses.transfer_to') }}</flux:subheading>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @if ($this->eligible->isEmpty())
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay estibas auditadas por trasladar.</div>
            @else
                <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->eligible as $pallet)
                        <label wire:key="tr-{{ $pallet->id }}" class="flex cursor-pointer items-center gap-3 p-3">
                            <flux:checkbox wire:model.live="selected" :value="$pallet->id" />
                            <span class="font-mono font-medium">{{ $pallet->pallet_code }}</span>
                            <span class="text-sm text-zinc-500">{{ $pallet->item_code }} · Lote {{ $pallet->lot_code }} · {{ $pallet->boxes_count }} cajas @if ($pallet->transfer_status === TransferStatus::Failed)· intento anterior fallido @endif</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg" level="2">Solicitar</flux:heading>
            <flux:text>{{ count($selected) }} estiba(s) seleccionada(s).</flux:text>

            @if ($this->groupingEnabled())
                <flux:switch wire:model.live="grouped" label="Agrupar en un solo documento" />
                @if ($grouped)
                    <flux:checkbox wire:model="groupingConfirmed" label="Confirmo que estas estibas van en un solo traslado (mismo producto y lote)" />
                @endif
            @else
                <flux:text class="text-xs">Un documento por estiba. El traslado agrupado espera DP-05.</flux:text>
            @endif

            <flux:button variant="primary" icon="truck" wire:click="request" wire:loading.attr="disabled" wire:target="request"
                wire:confirm="¿Solicitar el traslado en SAP para las estibas seleccionadas?" :disabled="$selected === []">
                Solicitar traslado
            </flux:button>
        </div>
    </div>

    <div>
        <flux:heading size="lg" level="2" class="mb-3">Últimos traslados</flux:heading>
        @if ($this->transfers->isEmpty())
            <flux:text>Todavía no hay traslados.</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Solicitado</flux:table.column>
                    <flux:table.column>Modo</flux:table.column>
                    <flux:table.column align="end">Estibas</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                    <flux:table.column>DocNum</flux:table.column>
                    <flux:table.column>Detalle</flux:table.column>
                    <flux:table.column><span class="sr-only">Acciones</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->transfers as $transfer)
                        <flux:table.row :key="$transfer->id">
                            <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $transfer->requested_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $transfer->mode === TransferMode::Grouped ? 'Agrupado' : 'Individual' }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $transfer->lines_count }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="match ($transfer->status) { SapDocumentStatus::Confirmed => 'green', SapDocumentStatus::Failed => 'red', SapDocumentStatus::PendingReconciliation => 'amber', default => 'zinc' }">{{ $transfer->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="tabular-nums">{{ $transfer->sap_doc_num ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="max-w-72 whitespace-normal text-xs text-zinc-500">
                                {{ $transfer->last_error_message }}
                                @if (($transfer->reconciliation['mismatch'] ?? false) === true)<div class="font-medium text-red-600">El documento en SAP no coincide: revisar a mano.</div>@endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($transfer->status === SapDocumentStatus::PendingReconciliation)
                                    @can('transfers.reconcile')
                                        <div class="flex justify-end gap-1">
                                            <flux:button size="sm" icon="arrow-path" wire:click="reconcile({{ $transfer->id }})">Conciliar</flux:button>
                                            <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="askFail({{ $transfer->id }})">Marcar fallido</flux:button>
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

    <flux:modal name="fail-transfer" class="w-full max-w-md">
        <form wire:submit="fail" class="flex flex-col gap-4">
            <flux:heading size="lg">Marcar traslado como fallido</flux:heading>
            <flux:text>Solo se acepta si SAP confirma que el documento no existe. Las estibas quedan libres para pedirse otra vez.</flux:text>
            <flux:input wire:model="failReason" label="Motivo" maxlength="300" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="fail">Marcar fallido</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
