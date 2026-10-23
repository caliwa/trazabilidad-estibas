<?php

use App\Domain\Audits\Actions\CancelBlindCount;
use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Audits\Data\BlindCountOutcome;
use App\Domain\Audits\Exceptions\AuditRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Support\Correlation\CorrelationId;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Blind count. Everything in this component's state travels to the
 * browser, so it only ever holds the pallet's identity, the number the
 * operator typed and the outcome. The system quantity stays on the server.
 */
new #[Title('Conteo ciego')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::AuditsCount->value), 403);
    }

    public string $scanned = '';

    /** @var int|string|null */
    public $counted = null;

    #[Locked]
    public ?int $auditId = null;

    /** @var array{code: string, order: string, item: string, description: string, lot: string, reaudit: bool}|null */
    #[Locked]
    public ?array $pallet = null;

    #[Locked]
    public ?string $outcome = null;

    #[Locked]
    public ?string $error = null;

    /** Tries left for the count in progress; shown only after one did not match. */
    #[Locked]
    public ?int $triesLeft = null;

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    public function scan(StartBlindCount $start): void
    {
        $this->reset('error', 'outcome', 'counted', 'triesLeft');
        $this->resetValidation();

        try {
            $view = $start->handle(Auth::user(), $this->scanned, $this->context());
        } catch (AuditRuleViolation $e) {
            $this->error = $e->getMessage();
            $this->scanned = '';

            return;
        }

        $this->auditId = $view->auditId;
        $left = app(SubmitBlindCount::class)->triesLeft($view->auditId);
        $this->triesLeft = $left < config()->integer('traceability.count_tries') ? $left : null;
        $this->pallet = ['code' => $view->palletCode, 'order' => $view->productionOrderRef, 'item' => $view->itemCode, 'description' => $view->itemDescription, 'lot' => $view->lotCode, 'reaudit' => $view->isReaudit];
        $this->scanned = '';
    }

    /**
     * The pallet QR read with the scanner field (hand reader or camera).
     */
    public function scanFrom(string $scanned, string $scanId): void
    {
        $this->scanned = mb_substr($scanned, 0, 200);
        $this->scan(app(StartBlindCount::class));
    }

    public function submit(SubmitBlindCount $submit): void
    {
        $this->validate(['counted' => ['required', 'integer', 'min:0', 'max:'.SubmitBlindCount::MAX_BOXES]], attributes: ['counted' => 'cajas contadas']);

        try {
            $result = $submit->handle(Auth::user(), (int) $this->auditId, (int) $this->counted, $this->context());
        } catch (AuditRuleViolation $e) {
            $this->error = $e->getMessage();
            $this->reset('auditId', 'pallet', 'counted');

            return;
        }

        if ($result === BlindCountOutcome::Retry) {
            // Same count, another try: the pallet stays on screen, the number is cleared.
            $this->triesLeft = $submit->triesLeft((int) $this->auditId);
            $this->reset('counted');

            return;
        }

        $this->outcome = $result->value;
        $this->reset('auditId', 'counted', 'triesLeft');
    }

    public function cancel(CancelBlindCount $cancel): void
    {
        try {
            $cancel->handle(Auth::user(), (int) $this->auditId, 'Cancelado por el operario.', $this->context());
        } catch (AuditRuleViolation) {
            // Already finished or expired: nothing to cancel.
        }

        $this->reset('auditId', 'pallet', 'counted', 'outcome', 'error', 'triesLeft');
    }

    public function next(): void
    {
        $this->reset('auditId', 'pallet', 'counted', 'outcome', 'error', 'scanned', 'triesLeft');
    }
}; ?>

<section class="mx-auto flex w-full max-w-xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Conteo ciego</flux:heading>
        <flux:subheading>Lee la etiqueta, cuenta las cajas y registra lo que contaste</flux:subheading>
    </div>

    @if ($error)
        <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ $error }}</div>
    @endif

    @if ($outcome !== null)
        @if ($outcome === BlindCountOutcome::Approved->value)
            <div role="status" class="flex flex-col items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 p-8 text-center dark:border-emerald-800 dark:bg-emerald-950">
                <flux:icon.check-circle class="size-12 text-emerald-600" />
                <div class="text-2xl font-semibold text-emerald-800 dark:text-emerald-200">Conteo aprobado</div>
                <div class="text-emerald-700 dark:text-emerald-300">La estiba {{ $pallet['code'] ?? '' }} se transfiere en SAP de la bodega {{ config('sap.warehouses.transfer_from') }} a la {{ config('sap.warehouses.transfer_to') }}.</div>
            </div>
        @else
            <div role="status" class="flex flex-col items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 p-8 text-center dark:border-amber-800 dark:bg-amber-950">
                <flux:icon.exclamation-triangle class="size-12 text-amber-600" />
                <div class="text-2xl font-semibold text-amber-800 dark:text-amber-200">Diferencia registrada</div>
                <div class="text-amber-700 dark:text-amber-300">La estiba {{ $pallet['code'] ?? '' }} queda retenida. Ya avisamos al supervisor para que habilite el reconteo.</div>
            </div>
        @endif
        <flux:button variant="primary" size="base" icon="qr-code" wire:click="next" class="w-full">Siguiente estiba</flux:button>
    @elseif ($pallet === null)
        {{-- Keys on each step: the scanner is wire:ignore, and without them Livewire reused it for the pallet card. --}}
        <div wire:key="step-scan" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <x-scanner action="scanFrom" label="Etiqueta de la estiba" placeholder="Lee el QR de la etiqueta de la estiba" />
            <div wire:loading wire:target="scanFrom" class="text-sm text-zinc-500">Buscando la estiba…</div>
        </div>
    @else
        <div wire:key="step-count-{{ $auditId }}" class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="font-mono text-3xl font-semibold text-zinc-900 dark:text-white">{{ $pallet['code'] }}</div>
            <div class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $pallet['item'] }} · {{ $pallet['description'] }}</div>
            <div class="text-zinc-500 dark:text-zinc-400">Orden {{ $pallet['order'] }} · Lote {{ $pallet['lot'] }}</div>
            @if ($pallet['reaudit'])<flux:badge color="amber" class="mt-2">Reauditoría</flux:badge>@endif
        </div>

        @if ($triesLeft !== null)
            <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                <div class="font-medium">El conteo no coincide</div>
                <div class="text-sm">Vuelve a contar las cajas. {{ $triesLeft === 1 ? 'Te queda 1 oportunidad' : "Te quedan {$triesLeft} oportunidades" }}: si no coincide, se registra la diferencia.</div>
            </div>
        @endif

        <form wire:submit="submit" class="flex flex-col gap-4">
            <flux:input wire:model="counted" type="number" inputmode="numeric" min="0" max="{{ SubmitBlindCount::MAX_BOXES }}" label="Cajas contadas" autofocus required class="text-2xl" />
            <flux:button type="submit" variant="primary" size="base" wire:loading.attr="disabled" wire:target="submit" class="w-full"
                wire:confirm="¿Registrar este conteo? No se puede cambiar después.">Registrar conteo</flux:button>
            <flux:button variant="ghost" wire:click="cancel" class="w-full">Cancelar</flux:button>
        </form>
    @endif
</section>
