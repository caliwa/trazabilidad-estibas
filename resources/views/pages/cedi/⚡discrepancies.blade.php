<?php

use App\Domain\Audits\Actions\AuthorizeReaudit;
use App\Domain\Audits\Actions\ResolveDiscrepancy;
use App\Domain\Audits\Enums\DiscrepancyStatus;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Models\DiscrepancyCase;
use App\Models\PalletAudit;
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
 * Discrepancies for supervisors and administrators: counted vs system
 * quantity, every count with the number of each try, and the actions
 * approved by DP-04 (enabling a new blind count).
 */
new #[Title('Discrepancias')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::DiscrepanciesResolve->value), 403);
    }

    #[Url(except: 'open')]
    public string $show = 'open';

    #[Locked]
    public ?int $caseId = null;

    public string $notes = '';

    public string $action = '';

    public string $reference = '';

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    /**
     * @return Collection<int, DiscrepancyCase>
     */
    #[Computed]
    public function cases(): Collection
    {
        return DiscrepancyCase::query()
            ->with(['pallet:id,pallet_code,production_order_ref,item_code,lot_code', 'audit.operator:id,name', 'resolver:id,name'])
            ->when($this->show === 'open', fn ($q) => $q->where('status', '<>', DiscrepancyStatus::Resolved))
            ->when($this->show === 'resolved', fn ($q) => $q->where('status', DiscrepancyStatus::Resolved))
            ->latest('opened_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, PalletAudit>
     */
    public function attempts(int $palletId): Collection
    {
        return PalletAudit::query()->with(['operator:id,name', 'tries'])->where('pallet_id', $palletId)->orderBy('attempt')->get();
    }

    /**
     * @return list<string>
     */
    public function closeActions(): array
    {
        return config()->array('traceability.decisions.discrepancy_close_actions');
    }

    public function open(int $caseId, string $modal): void
    {
        $this->caseId = $caseId;
        $this->reset('notes', 'action', 'reference');
        $this->resetValidation();
        Flux::modal($modal)->show();
    }

    public function authorizeReaudit(AuthorizeReaudit $authorize): void
    {
        $this->validate(['notes' => ['required', 'string', 'max:500']], attributes: ['notes' => 'motivo']);

        $this->run(fn () => $authorize->handle(Auth::user(), (int) $this->caseId, $this->notes, $this->context()), 'Reconteo habilitado: ya se puede volver a contar.', 'reaudit');
    }

    public function resolve(ResolveDiscrepancy $resolve): void
    {
        $this->validate([
            'action' => ['required', 'string'],
            'notes' => ['required', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:200'],
        ], attributes: ['action' => 'acción', 'notes' => 'notas', 'reference' => 'referencia']);

        $this->run(fn () => $resolve->handle(Auth::user(), (int) $this->caseId, $this->action, $this->notes, array_filter(['sap_reference' => trim($this->reference)]), $this->context()), 'Discrepancia cerrada. La estiba sigue retenida para traslado.', 'resolve');
    }

    private function run(callable $operation, string $success, string $modal): void
    {
        try {
            $operation();
        } catch (AuditRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $success);
        unset($this->cases);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Discrepancias</flux:heading>
            <flux:subheading>Conteos ciegos que no coincidieron con el sistema. Nada se ajusta solo.</flux:subheading>
        </div>
        <flux:select wire:model.live="show" class="max-w-48">
            <flux:select.option value="open">Abiertas</flux:select.option>
            <flux:select.option value="resolved">Resueltas</flux:select.option>
            <flux:select.option value="all">Todas</flux:select.option>
        </flux:select>
    </div>

    @if (! config('traceability.decisions.discrepancy_reaudit_enabled') && $this->closeActions() === [])
        <div role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <div class="font-medium">Resolución pendiente de aprobar (DP-04)</div>
            <div class="text-sm">Se registran y se ven las discrepancias, pero ninguna acción para resolverlas está habilitada. Las estibas quedan retenidas.</div>
        </div>
    @endif

    @forelse ($this->cases as $case)
        <article wire:key="case-{{ $case->id }}" class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <a href="{{ route('pallets.show', $case->pallet_id) }}" wire:navigate class="font-mono text-lg font-semibold hover:underline">{{ $case->pallet->pallet_code }}</a>
                    <div class="text-sm text-zinc-500">Orden {{ $case->pallet->production_order_ref }} · {{ $case->pallet->item_code }} · Lote {{ $case->pallet->lot_code }}</div>
                </div>
                <flux:badge :color="match ($case->status) { DiscrepancyStatus::Open => 'red', DiscrepancyStatus::InReview => 'amber', DiscrepancyStatus::Resolved => 'green' }">{{ $case->status->label() }}</flux:badge>
            </div>

            <table class="mt-3 w-full text-sm">
                <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Conteo</th><th>Operario</th><th>Fecha</th><th class="text-end">Contado</th><th class="text-end">Sistema</th><th class="ps-6">Resultado</th></tr></thead>
                <tbody>
                    @foreach ($this->attempts($case->pallet_id) as $a)
                        <tr wire:key="a-{{ $a->id }}" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="py-1">{{ $a->attempt }}@if ($a->reaudit_of_id) (reauditoría)@endif</td>
                            <td>{{ $a->operator->name }}</td>
                            <td class="tabular-nums">{{ $a->started_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                            <td class="text-end tabular-nums">
                                @if ($a->tries->count() > 1)<span title="Número de cada oportunidad">{{ $a->tries->pluck('counted_boxes')->implode(' · ') }}</span>@else{{ $a->counted_boxes ?? $a->tries->first()?->counted_boxes ?? '—' }}@endif
                            </td>
                            <td class="text-end tabular-nums">{{ $a->expected_boxes ?? '—' }}</td>
                            <td class="ps-6">{{ $a->status->value }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($case->status === DiscrepancyStatus::Resolved)
                <flux:text class="mt-3 text-sm">Resuelta por {{ $case->resolver?->name }} el {{ $case->resolved_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}: {{ $case->resolution_action }}. {{ $case->resolution_notes }}</flux:text>
            @else
                <div class="mt-4 flex flex-wrap gap-2">
                    @if (config('traceability.decisions.discrepancy_reaudit_enabled') && $case->status === DiscrepancyStatus::Open)
                        <flux:button size="sm" icon="arrow-path" wire:click="open({{ $case->id }}, 'reaudit')">Habilitar reconteo</flux:button>
                    @endif
                    @if ($this->closeActions() !== [])
                        <flux:button size="sm" variant="ghost" icon="check" wire:click="open({{ $case->id }}, 'resolve')">Cerrar con acción</flux:button>
                    @endif
                </div>
            @endif
        </article>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay discrepancias.</div>
    @endforelse

    <flux:modal name="reaudit" class="w-full max-w-md">
        <form wire:submit="authorizeReaudit" class="flex flex-col gap-4">
            <flux:heading size="lg">Habilitar reconteo</flux:heading>
            <flux:text>Se podrá hacer un nuevo conteo ciego de esta estiba, con {{ config('traceability.count_tries') }} oportunidades. Lo puede hacer el mismo operario u otro.</flux:text>
            <flux:textarea wire:model="notes" label="Motivo" rows="3" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="authorizeReaudit">Habilitar</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="resolve" class="w-full max-w-md">
        <form wire:submit="resolve" class="flex flex-col gap-4">
            <flux:heading size="lg">Cerrar discrepancia</flux:heading>
            <flux:select wire:model="action" label="Acción" placeholder="Elige">
                @foreach ($this->closeActions() as $option)<flux:select.option :value="$option">{{ $option }}</flux:select.option>@endforeach
            </flux:select>
            <flux:input wire:model="reference" label="Documento SAP u otra evidencia (opcional)" maxlength="200" />
            <flux:textarea wire:model="notes" label="Notas" rows="4" required />
            <flux:text class="text-xs">Esto no ajusta inventario. La estiba sigue retenida para traslado.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="resolve">Cerrar</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
