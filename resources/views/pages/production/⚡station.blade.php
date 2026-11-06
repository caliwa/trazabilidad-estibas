<?php

use App\Domain\Pallets\Actions\CloseManually;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
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
use App\Domain\Operations\Heartbeat;
use App\Domain\Shared\TraceEventType;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletCloseReason;
use App\Models\PalletStandard;
use App\Models\PalletScanEvent;
use App\Models\TraceEvent;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Production station: manual reading of box labels (each read fills the
 * pallet of its order and lot), open pallets filling up, pallets waiting to
 * be palletized and labeled, confirming the label by reading it, and recent
 * rejected reads. Every action calls a use case that checks permission and
 * state again on the server.
 */
new #[Title('Producción')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::PalletsOperate->value), 403);
    }

    #[Locked]
    public ?int $closingId = null;

    /** Chosen reason from the configurable list (select value). */
    public string $closeReasonId = '';

    public string $closeNote = '';

    #[Locked]
    public ?int $confirmingId = null;

    #[Locked]
    public ?int $reprintingId = null;

    public string $reprintReason = '';

    /** Second read of the confirmation dialog: one box of the pallet where the label was stuck. */
    public string $scannedBoxQr = '';

    /** Why the last confirmation in the dialog was refused; the dialog stays open to read again. */
    #[Locked]
    public ?string $confirmError = null;

    #[Locked]
    public int $confirmTries = 0;

    /**
     * Last box read by hand: what the label said and where the box went.
     *
     * @var array{at: string, order: string, item: string, description: string, lot: string, box: string, expiration: string, pallet: ?string, boxes: ?int, standard: ?int, closed: bool, test_standard: bool}|null
     */
    #[Locked]
    public ?array $lastBox = null;

    #[Locked]
    public ?string $boxError = null;


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
        return Pallet::query()->with(['item:id,description', 'standard:id,sap_source_key'])->where('physical_status', PhysicalStatus::Stacking)->orderBy('started_at')->get();
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

    /**
     * What the fixed readers on the line did lately (boxes:reader): the last
     * read, counted or not, the boxes not counted in the last hour and each
     * reader's connection. Null when there is no fixed reader.
     *
     * @return array{last: ?array{ok: bool, at: \Carbon\CarbonInterface, reader: string, box: ?string, pallet: ?string, boxes: ?int, standard: ?int, order: ?string, lot: ?string, reason: ?string, read: ?string}, recent: list<array{ok: bool, at: \Carbon\CarbonInterface, reader: string, text: string}>, not_counted: int, counted: int, errors: list<array{at: \Carbon\CarbonInterface, reader: string, reason: string, read: ?string}>, readers: list<array{code: string, name: string, connected: ?bool}>}|null
     */
    #[Computed]
    public function lineReads(): ?array
    {
        $readers = Device::query()->where('kind', 'LECTOR')->where('active', true)->where('code', '!=', RegisterManualBoxScan::DEVICE_CODE)->orderBy('code')->get(['id', 'code', 'name']);
        if ($readers->isEmpty()) {
            return null;
        }
        $codes = $readers->pluck('code', 'id');
        $since = now()->subHour();

        $countedReads = PalletScanEvent::query()->with('pallet:id,pallet_code,boxes_count,standard_boxes_snapshot')
            ->where('processing_status', ScanEventStatus::Accepted)->whereNotNull('read_by_device_id')
            ->latest('received_at')->latest('id')->limit(10)->get();
        $counted = $countedReads->first();
        $errorQuery = fn () => TraceEvent::query()->where('event', TraceEventType::ScanRejected->value)->where('subject_type', 'device')
            ->whereIn('subject_id', $readers->pluck('id'))->where('occurred_at', '>=', $since);
        $errors = $errorQuery()->latest('occurred_at')->limit(5)->get(['subject_id', 'occurred_at', 'reason', 'context']);

        $error = $errors->first();
        $last = null;
        if ($counted !== null && ($error === null || $counted->received_at->greaterThanOrEqualTo($error->occurred_at))) {
            $last = [
                'ok' => true, 'at' => $counted->received_at, 'reader' => (string) ($codes[$counted->read_by_device_id] ?? ''),
                'box' => $counted->external_event_id, 'pallet' => $counted->pallet?->pallet_code,
                'boxes' => $counted->pallet?->boxes_count, 'standard' => $counted->pallet?->standard_boxes_snapshot,
                'order' => $counted->production_order_ref, 'lot' => $counted->lot_code, 'reason' => null, 'read' => null,
            ];
        } elseif ($error !== null) {
            $last = [
                'ok' => false, 'at' => $error->occurred_at, 'reader' => (string) ($codes[$error->subject_id] ?? ''),
                'box' => null, 'pallet' => null, 'boxes' => null, 'standard' => null, 'order' => null, 'lot' => null,
                'reason' => (string) $error->reason, 'read' => isset($error->context['read']) ? (string) $error->context['read'] : null,
            ];
        }

        // Every read of the line, newest first, so none goes by unseen on a fast line.
        $recent = collect()
            ->concat($countedReads->map(fn (PalletScanEvent $e) => ['ok' => true, 'at' => $e->received_at, 'reader' => (string) ($codes[$e->read_by_device_id] ?? ''), 'text' => $e->external_event_id.' → '.($e->pallet?->pallet_code ?? '')]))
            ->concat($errors->map(fn (TraceEvent $e) => ['ok' => false, 'at' => $e->occurred_at, 'reader' => (string) ($codes[$e->subject_id] ?? ''), 'text' => (string) $e->reason]))
            ->sortByDesc(fn (array $r) => $r['at']->getTimestamp() + $r['at']->micro / 1e6)
            ->take(10)->values()->all();

        return [
            'last' => $last,
            'recent' => $recent,
            'not_counted' => $errorQuery()->count(),
            'counted' => PalletScanEvent::query()->where('processing_status', ScanEventStatus::Accepted)->whereNotNull('read_by_device_id')->where('received_at', '>=', $since)->count(),
            'errors' => $errors->map(fn (TraceEvent $e) => [
                'at' => $e->occurred_at, 'reader' => (string) ($codes[$e->subject_id] ?? ''), 'reason' => (string) $e->reason,
                'read' => isset($e->context['read']) ? (string) $e->context['read'] : null,
            ])->values()->all(),
            // Null: never connected (maybe not installed yet).
            'readers' => $readers->map(fn (Device $d) => [
                'code' => $d->code, 'name' => $d->name,
                'connected' => Heartbeat::last(Heartbeat::reader($d->code)) === null ? null : Heartbeat::isFresh(Heartbeat::reader($d->code)),
            ])->values()->all(),
        ];
    }

    public function labelMode(): ?LabelConfirmation
    {
        return LabelConfirmation::configured();
    }

    public function rejectionLabel(?string $reason): string
    {
        return ScanRejection::tryFrom((string) $reason)?->label() ?? (string) $reason;
    }

    /**
     * A box label read with the hand reader (or the camera).
     */
    public function scanBox(string $scanned, ?string $scanId = null): void
    {
        $this->boxError = null;

        try {
            // The box code inside the QR is the read's id; the page's own id is not needed here.
            $scan = app(RegisterManualBoxScan::class)->handle(Auth::user(), $scanned, $this->context());
        } catch (PalletRuleViolation $e) {
            $this->boxError = $e->getMessage();
            $this->lastBox = null;

            return;
        } finally {
            unset($this->open, $this->pending);
        }

        $this->lastBox = [
            'at' => now()->format('H:i:s'),
            'order' => $scan->orderRef,
            'item' => $scan->itemCode,
            'description' => $scan->itemDescription,
            'lot' => $scan->lotCode,
            'box' => $scan->boxCode,
            'expiration' => $scan->expiration->format('d/m/Y'),
            'pallet' => $scan->result->palletCode,
            'boxes' => $scan->result->boxesCount,
            'standard' => $scan->result->standard,
            'closed' => $scan->result->palletClosed,
            'test_standard' => $scan->testStandard,
        ];
    }

    public function askClose(int $palletId): void
    {
        $this->closingId = $palletId;
        $this->closeReasonId = '';
        $this->closeNote = '';
        unset($this->closeReasons);
        $this->resetValidation();
        Flux::modal('close-pallet')->show();
    }

    public function close(CloseManually $close): void
    {
        $this->validate([
            'closeReasonId' => ['required', 'integer'],
            'closeNote' => ['nullable', 'string', 'max:300'],
        ], ['closeReasonId.required' => 'Escoge el motivo del cierre.'], ['closeReasonId' => 'motivo', 'closeNote' => 'observación']);

        $closed = $this->run(function () use ($close): string {
            $pallet = $close->handle(Auth::user(), (int) $this->closingId, (int) $this->closeReasonId, $this->closeNote, $this->context());

            return "Estiba {$pallet->pallet_code} cerrada con {$pallet->boxes_count} cajas.";
        });

        if ($closed) {
            Flux::modal('close-pallet')->close();
        } else {
            unset($this->closeReasons);
        }
    }

    /**
     * Active reasons for closing an incomplete pallet, in the configured order.
     *
     * @return Collection<int, PalletCloseReason>
     */
    #[Computed]
    public function closeReasons(): Collection
    {
        return PalletCloseReason::query()->active()->ordered()->get(['id', 'name']);
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
        $this->scannedBoxQr = '';
        $this->confirmError = null;
        $this->resetValidation();
        Flux::modal('confirm-label')->show();
    }

    /**
     * With "scan", one box read from the physical pallet where the label was
     * stuck: it must be a box of the pallet whose button was pressed. A
     * refusal stays in the dialog to read another box, as many times as needed.
     */
    public function confirmLabel(ConfirmLabeled $confirm): void
    {
        $this->validate(['scannedBoxQr' => ['nullable', 'string', 'max:200']]);

        try {
            $pallet = $confirm->handle(Auth::user(), (int) $this->confirmingId, $this->scannedBoxQr, $this->context());
        } catch (PalletRuleViolation $e) {
            $this->confirmError = $e->getMessage();
            $this->confirmTries++;
            // Read another box.
            $this->scannedBoxQr = '';

            return;
        } finally {
            unset($this->pending);
        }

        $this->confirmError = null;
        Flux::modal('confirm-label')->close();
        Flux::toast(variant: 'success', text: "Etiqueta de {$pallet->pallet_code} confirmada en la estiba correcta.");
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

{{-- Every 2 s: on a fast line the pallets' progress and the line reads must keep up. --}}
<section class="flex w-full flex-col gap-6" wire:poll.2s.visible>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Producción</flux:heading>
            <flux:subheading>Estibas en proceso y por despachar a paletizado</flux:subheading>
        </div>
        @if (! config('traceability.decisions.scan_ingestion_enabled'))
            <flux:badge color="amber" icon="exclamation-triangle" size="lg">Sensor automático apagado (DP-01): usa la lectura manual</flux:badge>
        @endif
    </div>

    @can('pallets.operate')
        <div class="grid gap-4 rounded-xl border border-zinc-200 p-5 lg:grid-cols-2 dark:border-zinc-700">
            <div class="flex flex-col gap-3">
                <flux:heading size="lg" level="2">Lectura manual de cajas</flux:heading>
                <flux:text>Lee el QR de cada caja. La aplicación busca esa caja, valida su OF y la suma a la estiba de esa OF y ese lote. Cada caja se cuenta una sola vez.</flux:text>
                <x-scanner action="scanBox" label="Etiqueta de la caja" placeholder="Lee el QR de la caja" camera keep-focus />
            </div>
            <div aria-live="polite">
                @if ($boxError)
                    <div role="alert" data-read-result="error" class="flex h-full items-center gap-4 rounded-xl border-4 border-red-600 bg-red-600 p-5 text-white shadow-lg">
                        <flux:icon.x-circle class="size-16 shrink-0" />
                        <div class="flex flex-col gap-1">
                            <div class="text-2xl font-bold uppercase tracking-wide">Caja no contada</div>
                            <div class="text-base">{{ $boxError }}</div>
                        </div>
                    </div>
                @elseif ($lastBox)
                    @php($percent = $lastBox['standard'] ? min(100, intdiv(($lastBox['boxes'] ?? 0) * 100, $lastBox['standard'])) : 0)
                    <div data-read-result="ok" wire:key="manual-{{ $lastBox['box'] }}" class="flex h-full flex-col gap-3 rounded-xl border-4 border-emerald-600 bg-emerald-50 p-5 shadow-lg dark:bg-emerald-950">
                        <div class="-mx-5 -mt-5 flex flex-wrap items-center gap-3 rounded-t-lg bg-emerald-600 px-5 py-3 text-white">
                            <flux:icon.check-circle class="size-10 shrink-0" />
                            <span class="text-2xl font-bold uppercase tracking-wide">Caja contada</span>
                            <span class="ms-auto font-mono text-sm tabular-nums">{{ $lastBox['at'] ?? '' }}</span>
                            @if ($lastBox['test_standard'])<flux:badge color="amber" size="sm">Estándar de prueba</flux:badge>@endif
                        </div>
                        <dl class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1 text-sm">
                            <dt class="text-zinc-500">OF</dt><dd class="font-mono font-semibold">{{ $lastBox['order'] }}</dd>
                            <dt class="text-zinc-500">Producto</dt><dd>{{ $lastBox['item'] }} · {{ $lastBox['description'] }}</dd>
                            <dt class="text-zinc-500">Lote</dt><dd class="font-mono">{{ $lastBox['lot'] }}</dd>
                            <dt class="text-zinc-500">Caja</dt><dd class="font-mono">{{ $lastBox['box'] }}</dd>
                            <dt class="text-zinc-500">Vence</dt><dd>{{ $lastBox['expiration'] }}</dd>
                        </dl>
                        @if ($lastBox['pallet'])
                            <div>
                                <div class="flex items-baseline justify-between">
                                    <span class="font-mono text-xl font-semibold">{{ $lastBox['pallet'] }}</span>
                                    <span class="text-3xl font-bold tabular-nums">{{ $lastBox['boxes'] }}<span class="text-lg font-normal text-zinc-500"> / {{ $lastBox['standard'] }}</span></span>
                                </div>
                                <div class="mt-2 h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full bg-emerald-600" style="width: {{ $percent }}%"></div></div>
                                @if ($lastBox['closed'])<div class="mt-2 font-semibold text-emerald-800 dark:text-emerald-200">Estiba completa y cerrada. La siguiente caja abre una estiba nueva.</div>@endif
                            </div>
                        @endif
                    </div>
                @else
                    <div class="flex h-full items-center justify-center rounded-xl border border-dashed border-zinc-300 p-5 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Aquí verás la OF, el lote y cómo va la estiba con cada caja leída.</div>
                @endif
            </div>
        </div>
    @endcan

    @if ($line = $this->lineReads())
        @php($last = $line['last'])
        @php($fresh = $last !== null && $last['at']->greaterThanOrEqualTo(now()->subSeconds(15)))
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-live="polite">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg" level="2">Lectura automática de la línea</flux:heading>
                <div class="flex flex-wrap gap-2">
                    @foreach ($line['readers'] as $reader)
                        <flux:badge :color="$reader['connected'] === null ? 'zinc' : ($reader['connected'] ? 'green' : 'red')" :icon="$reader['connected'] === false ? 'signal-slash' : 'signal'" size="lg" title="{{ $reader['name'] }}">
                            {{ $reader['code'] }}: {{ $reader['connected'] === null ? 'sin conectar aún' : ($reader['connected'] ? 'conectado' : 'DESCONECTADO') }}
                        </flux:badge>
                    @endforeach
                </div>
            </div>

            @if ($last === null)
                <div class="rounded-xl border border-dashed border-zinc-300 p-5 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Todavía no hay lecturas del lector de la línea.</div>
            @elseif ($last['ok'])
                <div data-line-result="ok" wire:key="line-{{ $last['box'] }}" @class(['flex flex-wrap items-center gap-5 rounded-xl border-4 border-emerald-600 bg-emerald-600 p-5 text-white shadow-lg', 'animate-pulse' => $fresh])>
                    <flux:icon.check-circle class="size-16 shrink-0" />
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="text-2xl font-bold uppercase tracking-wide">Caja contada</div>
                        <div class="font-mono text-lg">{{ $last['box'] }}</div>
                        <div class="text-sm opacity-90">OF {{ $last['order'] }} · Lote {{ $last['lot'] }} · {{ $last['reader'] }} · {{ $last['at']->timezone(config('app.timezone'))->format('H:i:s') }}</div>
                    </div>
                    @if ($last['pallet'])
                        <div class="text-end">
                            <div class="font-mono text-lg font-semibold">{{ $last['pallet'] }}</div>
                            <div class="text-4xl font-bold tabular-nums">{{ $last['boxes'] }}<span class="text-xl font-normal opacity-80"> / {{ $last['standard'] }}</span></div>
                        </div>
                    @endif
                </div>
            @else
                <div role="alert" data-line-result="error" wire:key="line-error-{{ $last['at']->getTimestamp() }}" @class(['flex items-center gap-5 rounded-xl border-4 border-red-700 bg-red-600 p-5 text-white shadow-lg', 'animate-pulse' => $fresh])>
                    <flux:icon.x-circle class="size-16 shrink-0" />
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="text-2xl font-bold uppercase tracking-wide">Caja no contada</div>
                        <div class="text-base">{{ $last['reason'] }}</div>
                        <div class="text-sm opacity-90">{{ $last['reader'] }} · {{ $last['at']->timezone(config('app.timezone'))->format('H:i:s') }}@if ($last['read']) · Leyó: <span class="font-mono">{{ $last['read'] }}</span>@endif</div>
                        <div class="text-sm font-semibold">Lee esa caja a mano.</div>
                    </div>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-lg bg-emerald-50 p-3 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                    <div class="text-sm">Contadas por el lector (última hora)</div>
                    <div class="text-3xl font-bold tabular-nums">{{ $line['counted'] }}</div>
                </div>
                <div @class(['rounded-lg p-3', 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100' => $line['not_counted'] > 0, 'bg-zinc-50 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' => $line['not_counted'] === 0])>
                    <div class="text-sm">No contadas (última hora)</div>
                    <div class="text-3xl font-bold tabular-nums">{{ $line['not_counted'] }}</div>
                </div>
            </div>

            @if ($line['recent'] !== [])
                <div>
                    <div class="mb-1 text-sm font-semibold text-zinc-700 dark:text-zinc-200">Últimas lecturas del lector</div>
                    <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-800 dark:border-zinc-700" data-line-recent>
                        @foreach ($line['recent'] as $read)
                            <li class="flex flex-wrap items-center gap-x-3 px-3 py-1.5">
                                @if ($read['ok'])<flux:icon.check-circle variant="mini" class="size-5 text-emerald-600" />@else<flux:icon.x-circle variant="mini" class="size-5 text-red-600" />@endif
                                <span class="font-mono tabular-nums">{{ $read['at']->timezone(config('app.timezone'))->format('H:i:s') }}</span>
                                <span class="font-medium">{{ $read['reader'] }}</span>
                                <span @class(['font-mono' => $read['ok'], 'text-red-800 dark:text-red-200' => ! $read['ok']])>{{ $read['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($line['errors'] !== [])
                <div>
                    <div class="mb-1 text-sm font-semibold text-red-700 dark:text-red-300">Últimas cajas no contadas por el lector</div>
                    <ul class="divide-y divide-red-100 rounded-lg border border-red-200 text-sm dark:divide-red-900 dark:border-red-900">
                        @foreach ($line['errors'] as $error)
                            <li class="flex flex-wrap gap-x-3 px-3 py-2">
                                <span class="font-mono tabular-nums">{{ $error['at']->timezone(config('app.timezone'))->format('H:i:s') }}</span>
                                <span class="font-medium">{{ $error['reader'] }}</span>
                                <span class="text-red-800 dark:text-red-200">{{ $error['reason'] }}</span>
                                @if ($error['read'])<span class="font-mono text-zinc-500">{{ $error['read'] }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

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
                                @if ($pallet->standard?->sap_source_key === PalletStandard::TEST_SOURCE)<flux:badge color="amber" size="sm" class="mt-1">Estándar de prueba</flux:badge>@endif
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
            @if ($this->closeReasons->isEmpty())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    No hay motivos de cierre configurados. Pide a un supervisor que los cree en Administración, Motivos de cierre.
                </flux:callout>
            @else
                <flux:select wire:model="closeReasonId" label="Motivo" placeholder="Escoge el motivo…">
                    @foreach ($this->closeReasons as $option)
                        <flux:select.option :value="$option->id" wire:key="cr-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="closeNote" label="Observación (opcional)" maxlength="300" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="close" :disabled="$this->closeReasons->isEmpty()">Cerrar estiba</flux:button>
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

    <flux:modal name="confirm-label" class="w-full max-w-lg">
        <form wire:submit="confirmLabel" class="flex flex-col gap-4">
            <flux:heading size="lg">Confirmar etiqueta aplicada{{ $confirmingId ? ' · '.$this->pending->firstWhere('id', $confirmingId)?->pallet_code : '' }}</flux:heading>
            @if ($this->labelMode() === LabelConfirmation::Scan)
                <flux:text>Lee el QR de <strong>una caja de la estiba donde pegaste la etiqueta</strong> (mejor del centro, no del borde). La aplicación comprueba que esa caja sea de esta estiba, así la etiqueta queda confirmada en la estiba correcta.</flux:text>
                {{-- Hand readers type like a keyboard: once the box's QR is complete (or Enter comes) it confirms. No click needed. --}}
                <div class="flex flex-col gap-3"
                    x-data="{
                        busy: false,
                        boxDone(v) { return /TRZB.[0-9a-f]{32}/i.test(v); },
                        // One confirmation at a time: a reader that also sends Enter must not confirm twice.
                        go() {
                            if (this.busy || $refs.box.value.trim() === '') return;
                            this.busy = true;
                            $wire.confirmLabel().finally(() => { this.busy = false; });
                        },
                    }">
                    @if ($confirmError)
                        {{-- Keyed by try: each refusal shows anew and puts the cursor back on the box. --}}
                        <div wire:key="confirm-error-{{ $confirmTries }}" x-init="$nextTick(() => $refs.box.focus())" role="alert" data-label-result="error" class="flex items-center gap-3 rounded-xl border-4 border-red-700 bg-red-600 p-4 text-white shadow-lg">
                            <flux:icon.x-circle class="size-10 shrink-0" />
                            <div>
                                <div class="font-bold uppercase tracking-wide">Etiqueta no confirmada</div>
                                <div class="text-sm">{{ $confirmError }}</div>
                            </div>
                        </div>
                    @endif
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">QR de una caja de la estiba</span>
                        {{-- autofocus: the dialog puts the cursor here when it opens, ready for the reader. --}}
                        <input x-ref="box" wire:model="scannedBoxQr" autofocus autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Lee el QR de una caja de la estiba"
                            x-on:input="if (boxDone($event.target.value)) $nextTick(() => go())"
                            x-on:keydown.enter.prevent="go()" x-on:keydown.tab.prevent="go()"
                            class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-base text-zinc-900 shadow-xs focus:border-zinc-500 focus:outline-none dark:border-white/10 dark:bg-white/10 dark:text-white" />
                    </label>
                    <x-qr-camera-button model="scannedBoxQr" submit="confirmLabel" />
                </div>
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
