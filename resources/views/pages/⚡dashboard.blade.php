<?php

use App\Domain\Operations\AlertLevel;
use App\Domain\Operations\OperationalChecks;
use App\Enums\Permission;
use App\Enums\Role;
use App\Queries\DashboardQuery;
use App\Queries\OperatorBoardQuery;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Landing page after login. Supervisors (dashboard.kpis) get the management
 * indicators: today's production against yesterday at the same time, where
 * the pallets pile up along the flow, the open orders, CEDI, SAP and the
 * operational alerts. Everyone else sees their role, as before.
 */
new #[Title('Inicio')] class extends Component {
    public function roleLabel(): string
    {
        $role = Role::tryFrom((string) Auth::user()?->getRoleNames()->first());

        return $role?->label() ?? 'Sin rol asignado';
    }

    public function showsKpis(): bool
    {
        return (bool) Auth::user()?->can(Permission::DashboardKpis->value);
    }

    /**
     * The production operator's view of the line: reads and pallets at a glance.
     */
    public function showsLineBoard(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can(Permission::PalletsOperate->value) && ! $user->can(Permission::DashboardKpis->value);
    }

    /**
     * @return array{today: array{counted: int, not_counted: int, no_read: int, last_hour: int}, reads: list<array{ok: bool, at: \Carbon\CarbonImmutable, box: string, detail: string, no_read: bool}>, stacking: \Illuminate\Support\Collection<int, \App\Models\Pallet>, waiting: array{to_palletize: int, to_label: int}}
     */
    #[Computed]
    public function line(): array
    {
        $query = app(OperatorBoardQuery::class);

        return [
            'today' => $query->today(),
            'reads' => $query->lastReads(),
            'stacking' => $query->stacking(),
            'waiting' => $query->waiting(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function kpis(): array
    {
        $query = app(DashboardQuery::class);

        return [
            'production' => $query->production(),
            'flow' => $query->flow(),
            'orders' => $query->openOrders(),
            'cedi' => $query->cedi(),
            'sap' => $query->sap(),
            'alerts' => app(OperationalChecks::class)->run(),
        ];
    }
}; ?>

@php
    $fmt = fn (int|float $n) => number_format($n, 0, ',', '.');
    // Change against the same time yesterday; null when yesterday had nothing to compare with.
    $delta = fn (int $now, int $before) => $before === 0 ? null : (int) round(($now - $before) * 100 / $before);
    $tz = config('app.timezone');
    // How long it has been waiting, short and unambiguous: 26 min, 3 h, 2 d.
    $age = function (?\Carbon\CarbonInterface $at): ?string {
        if ($at === null) {
            return null;
        }
        $minutes = (int) max(0, $at->diffInMinutes(now()));

        return match (true) {
            $minutes < 60 => $minutes.' min',
            $minutes < 48 * 60 => intdiv($minutes, 60).' h',
            default => intdiv($minutes, 1440).' d',
        };
    };
@endphp

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ $this->showsKpis() ? 'Tablero de gestión' : 'Trazabilidad de estibas' }}</flux:heading>
            <flux:subheading>
                @if ($this->showsKpis())
                    {{ ucfirst(now()->locale('es')->translatedFormat('l j \d\e F')) }} · actualizado {{ now()->format('H:i') }} · se refresca cada minuto
                @else
                    Producción, CEDI y documentos SAP
                @endif
            </flux:subheading>
        </div>
        <flux:text>Tu rol: <span class="font-medium text-zinc-800 dark:text-white">{{ $this->roleLabel() }}</span></flux:text>
    </div>

    @if ($this->showsKpis())
        @php
            $k = $this->kpis;
            $p = $k['production'];
            $boxesDelta = $delta($p['boxes'], $p['boxes_before']);
            $palletsDelta = $delta($p['pallets'], $p['pallets_before']);
            $paceDelta = $delta($p['last_hour'], $p['hourly_average']);
        @endphp

        {{-- Palette and chrome from the data-viz reference palette, light and dark. --}}
        <style>
            .viz-root { --series-1: #2a78d6; --deemph: #c3c2b7; --grid: #e1e0d9; --axis: #c3c2b7; --muted: #898781; --good: #0ca30c; --good-text: #006300; --warning: #fab219; --serious: #ec835a; --critical: #d03b3b; --surface: #ffffff; }
            .dark .viz-root { --series-1: #3987e5; --deemph: #52514e; --grid: #2c2c2a; --axis: #383835; --muted: #898781; --good-text: #0ca30c; --surface: #18181b; }
        </style>

        <div class="viz-root flex flex-col gap-6" wire:poll.60s.visible data-dashboard-kpis>
            {{-- Today at a glance --}}
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['label' => 'Cajas leídas hoy', 'value' => $fmt($p['boxes']), 'delta' => $boxesDelta, 'note' => 'Ayer a esta hora: '.$fmt($p['boxes_before'])],
                    ['label' => 'Ritmo de la última hora', 'value' => $fmt($p['last_hour']).' cajas/h', 'delta' => $paceDelta, 'note' => 'Promedio del día: '.$fmt($p['hourly_average']).' cajas/h', 'vs' => 'vs promedio'],
                    ['label' => 'Estibas completadas hoy', 'value' => $fmt($p['pallets']), 'delta' => $palletsDelta, 'note' => 'Ayer a esta hora: '.$fmt($p['pallets_before'])],
                ] as $tile)
                    <div class="flex flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-kpi="{{ $tile['label'] }}">
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $tile['label'] }}</div>
                        <div class="text-4xl font-semibold text-zinc-900 dark:text-white">{{ $tile['value'] }}</div>
                        <div class="flex flex-wrap items-center gap-x-2 text-sm">
                            @if ($tile['delta'] === null)
                                <span class="text-zinc-500 dark:text-zinc-400">Sin dato para comparar</span>
                            @else
                                <span @class(['inline-flex items-center gap-0.5 font-semibold', 'text-[color:var(--good-text)]' => $tile['delta'] >= 0, 'text-[color:var(--critical)]' => $tile['delta'] < 0])>
                                    @if ($tile['delta'] >= 0)<flux:icon.arrow-trending-up variant="micro" />@else<flux:icon.arrow-trending-down variant="micro" />@endif
                                    {{ $tile['delta'] > 0 ? '+' : '' }}{{ $tile['delta'] }} %
                                </span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $tile['vs'] ?? 'vs ayer' }}</span>
                            @endif
                        </div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $tile['note'] }}</div>
                    </div>
                @endforeach
                <div class="flex flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-kpi="Estibas en estibado">
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Estibas en estibado ahora</div>
                    <div class="text-4xl font-semibold text-zinc-900 dark:text-white">{{ $fmt($p['stacking']) }}</div>
                    <div class="text-sm text-zinc-600 dark:text-zinc-300">Avance promedio {{ $p['stacking_percent'] }} %</div>
                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-[color:var(--grid)]" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $p['stacking_percent'] }}">
                        <div class="h-full rounded-full bg-[color:var(--series-1)]" style="width: {{ $p['stacking_percent'] }}%"></div>
                    </div>
                </div>
            </div>

            {{-- Boxes per hour, today against yesterday; and the last 14 days --}}
            <div class="grid gap-4 xl:grid-cols-3">
                @php
                    $hours = $p['hours'];
                    $hMax = max(1, ...array_map(fn ($h) => max($h['today'], $h['yesterday']), $hours));
                    $hStep = max(1, (int) ceil($hMax / 4 / (10 ** max(0, strlen((string) (int) ceil($hMax / 4)) - 1)))) * (10 ** max(0, strlen((string) (int) ceil($hMax / 4)) - 1));
                    $hTop = $hStep * (int) ceil($hMax / $hStep);
                    $W = 640; $H = 220; $left = 40; $bottom = 24; $plotH = $H - $bottom - 10;
                    $slot = ($W - $left) / max(1, count($hours));
                    $bar = min(14, max(4, ($slot - 6) / 2));
                @endphp
                <div class="rounded-xl border border-zinc-200 bg-white p-5 xl:col-span-2 dark:border-zinc-700 dark:bg-zinc-900" x-data="{ tip: null }">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading size="lg" level="2">Cajas por hora</flux:heading>
                        <div class="flex items-center gap-4 text-xs text-zinc-600 dark:text-zinc-300" aria-label="Leyenda">
                            <span class="inline-flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm bg-[color:var(--series-1)]"></span>Hoy</span>
                            <span class="inline-flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm bg-[color:var(--deemph)]"></span>Ayer</span>
                        </div>
                    </div>
                    <div class="relative mt-3">
                        <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-56 w-full" role="img" aria-label="Cajas leídas por hora, hoy y ayer">
                            @foreach (range(0, $hTop, $hStep) as $tick)
                                @php $y = 10 + $plotH - $plotH * $tick / $hTop; @endphp
                                <line x1="{{ $left }}" x2="{{ $W }}" y1="{{ $y }}" y2="{{ $y }}" stroke="var(--grid)" stroke-width="1" />
                                <text x="{{ $left - 6 }}" y="{{ $y + 4 }}" text-anchor="end" font-size="11" fill="var(--muted)" style="font-variant-numeric: tabular-nums">{{ $fmt($tick) }}</text>
                            @endforeach
                            @foreach ($hours as $i => $h)
                                @php
                                    $x = $left + $i * $slot + ($slot - 2 * $bar - 2) / 2;
                                    $ty = $plotH * $h['today'] / $hTop; $yy = $plotH * $h['yesterday'] / $hTop;
                                @endphp
                                <g>
                                    @if ($yy > 0)<path d="M{{ $x }},{{ 10 + $plotH }} v-{{ max(0, $yy - 3) }} q0,-3 3,-3 h{{ $bar - 6 }} q3,0 3,3 v{{ max(0, $yy - 3) }} z" fill="var(--deemph)" />@endif
                                    @if ($ty > 0)<path d="M{{ $x + $bar + 2 }},{{ 10 + $plotH }} v-{{ max(0, $ty - 3) }} q0,-3 3,-3 h{{ $bar - 6 }} q3,0 3,3 v{{ max(0, $ty - 3) }} z" fill="var(--series-1)" />@endif
                                    <text x="{{ $left + $i * $slot + $slot / 2 }}" y="{{ $H - 6 }}" text-anchor="middle" font-size="11" fill="var(--muted)">{{ $h['hour'] }}h</text>
                                    <rect x="{{ $left + $i * $slot }}" y="0" width="{{ $slot }}" height="{{ $H - $bottom }}" fill="transparent"
                                        @mouseenter="tip = { x: {{ ($left + ($i + 0.5) * $slot) / $W * 100 }}, hour: '{{ $h['hour'] }}:00 – {{ $h['hour'] }}:59', today: '{{ $fmt($h['today']) }}', yesterday: '{{ $fmt($h['yesterday']) }}' }" @mouseleave="tip = null" />
                                </g>
                            @endforeach
                            <line x1="{{ $left }}" x2="{{ $W }}" y1="{{ 10 + $plotH }}" y2="{{ 10 + $plotH }}" stroke="var(--axis)" stroke-width="1" />
                        </svg>
                        <template x-if="tip">
                            <div class="pointer-events-none absolute top-0 -translate-x-1/2 whitespace-nowrap rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs shadow-lg dark:border-zinc-700 dark:bg-zinc-800" :style="`left: ${Math.min(88, Math.max(12, tip.x))}%`">
                                <div class="font-semibold text-zinc-900 dark:text-white" x-text="tip.hour"></div>
                                <div class="flex items-center gap-1.5 text-zinc-700 dark:text-zinc-200"><span class="inline-block size-2 rounded-sm bg-[color:var(--series-1)]"></span>Hoy: <span class="font-semibold" x-text="tip.today"></span></div>
                                <div class="flex items-center gap-1.5 text-zinc-700 dark:text-zinc-200"><span class="inline-block size-2 rounded-sm bg-[color:var(--deemph)]"></span>Ayer: <span class="font-semibold" x-text="tip.yesterday"></span></div>
                            </div>
                        </template>
                    </div>
                </div>

                @php
                    $days = $p['days'];
                    $dMax = max(1, ...array_map(fn ($d) => $d['boxes'], $days));
                    $dW = 320; $dH = 220; $dPlot = $dH - 34; $dSlot = $dW / count($days); $dBar = min(14, $dSlot - 6);
                    $best = collect($days)->sortByDesc('boxes')->first();
                @endphp
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" x-data="{ tip: null }">
                    <flux:heading size="lg" level="2">Cajas por día · últimos 14 días</flux:heading>
                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Mejor día: {{ $best['date']->locale('es')->translatedFormat('D j M') }} · {{ $fmt($best['boxes']) }} cajas</div>
                    <div class="relative mt-3">
                        <svg viewBox="0 0 {{ $dW }} {{ $dH }}" class="h-56 w-full" role="img" aria-label="Cajas leídas por día, últimos 14 días">
                            @foreach ($days as $i => $d)
                                @php $bh = $dPlot * $d['boxes'] / $dMax; @endphp
                                @php $x = $i * $dSlot + ($dSlot - $dBar) / 2; @endphp
                                @if ($bh > 0)<path d="M{{ $x }},{{ 10 + $dPlot }} v-{{ max(0, $bh - 3) }} q0,-3 3,-3 h{{ $dBar - 6 }} q3,0 3,3 v{{ max(0, $bh - 3) }} z" fill="var(--series-1)" />@endif
                                @if ($i % 2 === (count($days) - 1) % 2)<text x="{{ $i * $dSlot + $dSlot / 2 }}" y="{{ $dH - 6 }}" text-anchor="middle" font-size="10" fill="var(--muted)">{{ $d['date']->format('j/n') }}</text>@endif
                                <rect x="{{ $i * $dSlot }}" y="0" width="{{ $dSlot }}" height="{{ $dH - 20 }}" fill="transparent"
                                    @mouseenter="tip = { x: {{ ($i + 0.5) * $dSlot / $dW * 100 }}, day: '{{ $d['date']->locale('es')->translatedFormat('D j M') }}', boxes: '{{ $fmt($d['boxes']) }}' }" @mouseleave="tip = null" />
                            @endforeach
                            <line x1="0" x2="{{ $dW }}" y1="{{ 10 + $dPlot }}" y2="{{ 10 + $dPlot }}" stroke="var(--axis)" stroke-width="1" />
                        </svg>
                        <template x-if="tip">
                            <div class="pointer-events-none absolute top-0 -translate-x-1/2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs shadow-lg dark:border-zinc-700 dark:bg-zinc-800" :style="`left: ${Math.min(80, Math.max(20, tip.x))}%`">
                                <div class="font-semibold text-zinc-900 dark:text-white" x-text="tip.day"></div>
                                <div class="text-zinc-700 dark:text-zinc-200"><span class="font-semibold" x-text="tip.boxes"></span> cajas</div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Where the pallets are: the process stages in order, and the bottleneck --}}
            @php
                $flow = $k['flow'];
                $fMax = max(1, ...array_map(fn ($s) => $s['count'], $flow));
                // The bottleneck: the stage after stacking holding the most pallets.
                $neck = collect($flow)->reject(fn ($s) => $s['key'] === 'stacking')->sortByDesc('count')->first();
                $neckKey = ($neck['count'] ?? 0) > 0 ? $neck['key'] : null;
            @endphp
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <flux:heading size="lg" level="2">Flujo de estibas: dónde están ahora</flux:heading>
                        <flux:text class="text-sm">Estibas en cada etapa del proceso y cuánto lleva esperando la más antigua.</flux:text>
                    </div>
                    @if ($neckKey)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-[color:var(--warning)] px-3 py-1 text-sm font-medium text-zinc-800 dark:text-zinc-100" data-bottleneck="{{ $neckKey }}">
                            <flux:icon.exclamation-triangle variant="mini" class="text-[color:var(--warning)]" /> Cuello de botella: {{ $neck['label'] }}
                        </span>
                    @endif
                </div>
                <ol class="mt-4 flex flex-col gap-2">
                    @foreach ($flow as $stage)
                        <li class="grid grid-cols-[10rem_1fr_8rem] items-center gap-3 text-sm sm:grid-cols-[13rem_1fr_11rem]" data-stage="{{ $stage['key'] }}">
                            <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $loop->iteration }}. {{ $stage['label'] }}</span>
                            <span class="flex items-center gap-2">
                                <span class="h-5 rounded-r-[4px]" style="width: {{ $stage['count'] === 0 ? 0 : max(1, $stage['count'] * 100 / $fMax) }}%; background: {{ $stage['key'] === $neckKey ? 'var(--series-1)' : 'var(--deemph)' }}"></span>
                                <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($stage['count']) }}</span>
                            </span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $stage['oldest'] ? 'la más antigua: '.$age($stage['oldest']) : '—' }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Open orders, closest due date first --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2">Órdenes de fabricación en curso</flux:heading>
                    @can('orders.view')<flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('orders.index')" wire:navigate>Tablero de OF</flux:button>@endcan
                </div>
                @if ($k['orders'] === [])
                    <flux:text class="mt-3">No hay órdenes en curso.</flux:text>
                @else
                    <div class="mt-3 flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($k['orders'] as $o)
                            @php $late = $o['due'] !== null && $o['due']->lt(today()); @endphp
                            <div class="grid gap-2 py-3 md:grid-cols-[1fr_2fr]" data-order="{{ $o['ref'] }}">
                                <div class="min-w-0">
                                    <div class="font-mono font-semibold text-zinc-900 dark:text-white">OF {{ $o['ref'] }}</div>
                                    <div class="truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $o['item'] }}</div>
                                    <div class="text-xs {{ $late ? 'font-semibold text-[color:var(--critical)]' : 'text-zinc-500 dark:text-zinc-400' }}">
                                        @if ($late)<flux:icon.clock variant="micro" class="inline" /> Vencida el {{ $o['due']->format('d/m/Y') }}@else Fin {{ $o['due']?->format('d/m/Y') ?? 'sin fecha' }}@endif
                                    </div>
                                </div>
                                <div class="flex flex-col justify-center gap-1">
                                    <div class="flex items-baseline justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-300"><span class="font-semibold text-zinc-900 dark:text-white">{{ $fmt($o['read']) }}</span> de {{ $o['planned'] === null ? '—' : $fmt($o['planned']) }} cajas</span>
                                        <span class="inline-flex items-center gap-1 font-semibold tabular-nums text-zinc-900 dark:text-white">
                                            @if ($o['percent'] > 100)<flux:icon.exclamation-triangle variant="micro" style="color: var(--warning)" /><span class="text-xs font-normal text-zinc-600 dark:text-zinc-300">más de lo planificado</span>@endif
                                            {{ $o['percent'] }} %
                                        </span>
                                    </div>
                                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-[color:var(--grid)]" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $o['percent']) }}">
                                        <div class="h-full rounded-full bg-[color:var(--series-1)]" style="width: {{ min(100, $o['percent']) }}%"></div>
                                    </div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Recibidas en SAP {{ $fmt($o['received']) }} · trasladadas a 05 {{ $fmt($o['transferred']) }} · {{ $o['stage']->label() }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- CEDI and SAP --}}
            @php
                $c = $k['cedi'];
                $s = $k['sap'];
                $status = fn (string $level) => match ($level) {
                    'good' => ['icon' => 'check-circle', 'color' => 'var(--good)', 'label' => 'Al día'],
                    'warning' => ['icon' => 'exclamation-triangle', 'color' => 'var(--warning)', 'label' => 'Atención'],
                    default => ['icon' => 'x-circle', 'color' => 'var(--critical)', 'label' => 'Crítico'],
                };
            @endphp
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg" level="2">CEDI y calidad</flux:heading>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">Exactitud del conteo a la primera</div>
                            <div class="text-3xl font-semibold text-zinc-900 dark:text-white">{{ $c['first_try_rate'] === null ? '—' : $c['first_try_rate'].' %' }}</div>
                            <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-[color:var(--grid)]"><div class="h-full rounded-full bg-[color:var(--series-1)]" style="width: {{ $c['first_try_rate'] ?? 0 }}%"></div></div>
                            <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $c['first_try_total'] }} conteos en los últimos 7 días</div>
                        </div>
                        <div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">Conteos de hoy</div>
                            <div class="text-3xl font-semibold text-zinc-900 dark:text-white">{{ $fmt($c['approved'] + $c['discrepancies']) }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $fmt($c['approved']) }} aprobados · {{ $fmt($c['discrepancies']) }} con discrepancia</div>
                        </div>
                        @foreach ([
                            ['Esperando conteo', $c['waiting'], $c['waiting'] === 0 ? 'good' : ($c['waiting_oldest'] && $c['waiting_oldest']->lt(now()->subHours(24)) ? 'critical' : 'warning'), $c['waiting_oldest'] ? 'la más antigua: '.$age($c['waiting_oldest']) : null],
                            ['Discrepancias abiertas', $c['open_discrepancies'], $c['open_discrepancies'] === 0 ? 'good' : 'warning', null],
                            ['Retenidas por calidad', $c['held'], $c['held'] === 0 ? 'good' : 'warning', null],
                        ] as [$label, $value, $level, $note])
                            @php $st = $status($level); @endphp
                            <div class="flex items-start gap-2" data-status="{{ $level }}">
                                <flux:icon :icon="$st['icon']" variant="mini" class="mt-1 shrink-0" style="color: {{ $st['color'] }}" />
                                <div>
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }} <span class="sr-only">({{ $st['label'] }})</span></div>
                                    <div class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ $fmt($value) }}</div>
                                    @if ($note)<div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</div>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg" level="2">Documentos SAP</flux:heading>
                    <div class="mt-4 grid grid-cols-[1fr_auto_auto_auto] items-center gap-x-4 gap-y-3 text-sm">
                        <span></span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Confirmados hoy</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Esperando a SAP</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Fallidos hoy</span>
                        @foreach ([['Recibos de producción (04)', 'receipts'], ['Traslados a CEDI (05)', 'transfers']] as [$label, $key])
                            <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $label }}</span>
                            <span class="text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($s[$key.'_confirmed']) }}</span>
                            @php $pending = $s[$key.'_pending']; @endphp
                            <span class="inline-flex items-center gap-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white" data-status="{{ $pending > 0 ? 'warning' : 'good' }}">
                                @if ($pending > 0)<flux:icon.clock variant="mini" style="color: var(--warning)" /><span class="sr-only">Atención</span>@endif{{ $fmt($pending) }}
                            </span>
                            @php $failed = $s[$key.'_failed']; @endphp
                            <span class="inline-flex items-center gap-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white" data-status="{{ $failed > 0 ? 'critical' : 'good' }}">
                                @if ($failed > 0)<flux:icon.x-circle variant="mini" style="color: var(--critical)" /><span class="sr-only">Crítico</span>@endif{{ $fmt($failed) }}
                            </span>
                        @endforeach
                    </div>
                    <flux:text class="mt-3 text-xs">"Esperando a SAP" incluye los enviados sin respuesta y los pendientes de conciliación.</flux:text>
                </div>
            </div>

            {{-- Operational alerts --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2">Alertas operativas</flux:heading>
                    @can('ops.alerts')<flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('operations.index')" wire:navigate>Estado operativo</flux:button>@endcan
                </div>
                @if ($k['alerts'] === [])
                    <div class="mt-3 flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200" data-status="good">
                        <flux:icon.check-circle variant="mini" style="color: var(--good)" /> Todo en orden: no hay alertas activas.
                    </div>
                @else
                    <ul class="mt-3 flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($k['alerts'] as $alert)
                            @php $critical = $alert->level === AlertLevel::Critical; @endphp
                            <li class="flex items-start gap-3 py-2" data-status="{{ $critical ? 'critical' : 'warning' }}">
                                @if ($critical)<flux:icon.x-circle variant="mini" class="mt-0.5 shrink-0" style="color: var(--critical)" />@else<flux:icon.exclamation-triangle variant="mini" class="mt-0.5 shrink-0" style="color: var(--warning)" />@endif
                                <div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $alert->title }} <span class="text-xs font-normal text-zinc-500">· {{ $alert->level->label() }}</span></div>
                                    <div class="text-xs text-zinc-600 dark:text-zinc-300">{{ $alert->detail }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    @if ($this->showsLineBoard())
        @php
            $line = $this->line;
            $t = $line['today'];
        @endphp
        {{-- The operator's line at a glance: green = counted, red = not counted. Refreshes every 5 s. --}}
        <div class="flex flex-col gap-6" wire:poll.5s.visible data-operator-board>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border-4 border-emerald-600 bg-emerald-50 p-5 dark:bg-emerald-950" data-tile="counted">
                    <div class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-emerald-800 dark:text-emerald-200"><flux:icon.check-circle variant="mini" /> Cajas contadas hoy</div>
                    <div class="mt-1 text-5xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">{{ $fmt($t['counted']) }}</div>
                    <div class="text-sm text-emerald-800 dark:text-emerald-200">{{ $fmt($t['last_hour']) }} en la última hora</div>
                </div>
                <div @class(['rounded-xl border-4 p-5', 'border-red-600 bg-red-50 dark:bg-red-950' => $t['not_counted'] > 0, 'border-zinc-200 dark:border-zinc-700' => $t['not_counted'] === 0]) data-tile="not-counted">
                    <div @class(['flex items-center gap-2 text-sm font-semibold uppercase tracking-wide', 'text-red-800 dark:text-red-200' => $t['not_counted'] > 0, 'text-zinc-600 dark:text-zinc-300' => $t['not_counted'] === 0])><flux:icon.x-circle variant="mini" /> No contadas hoy</div>
                    <div @class(['mt-1 text-5xl font-bold tabular-nums', 'text-red-900 dark:text-red-100' => $t['not_counted'] > 0, 'text-zinc-900 dark:text-white' => $t['not_counted'] === 0])>{{ $fmt($t['not_counted']) }}</div>
                    <div class="text-sm text-zinc-600 dark:text-zinc-300">Lector de la línea · {{ $fmt($t['no_read']) }} sin lectura (NO_READ)</div>
                </div>
                <div class="rounded-xl border-4 border-zinc-200 p-5 dark:border-zinc-700" data-tile="stacking">
                    <div class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300"><flux:icon.squares-2x2 variant="mini" /> Estibas en estibado</div>
                    <div class="mt-1 text-5xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $line['stacking']->count() }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Llenándose en la línea</div>
                </div>
                <div @class(['rounded-xl border-4 p-5', 'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950' => $line['waiting']['to_palletize'] + $line['waiting']['to_label'] > 0, 'border-zinc-200 dark:border-zinc-700' => $line['waiting']['to_palletize'] + $line['waiting']['to_label'] === 0]) data-tile="waiting">
                    <div class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300"><flux:icon.cube variant="mini" /> Cerradas por despachar</div>
                    <div class="mt-1 text-5xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $line['waiting']['to_palletize'] + $line['waiting']['to_label'] }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $line['waiting']['to_palletize'] }} por paletizar · {{ $line['waiting']['to_label'] }} por etiquetar</div>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-5">
                <div class="lg:col-span-2">
                    <flux:heading size="lg" level="2" class="mb-3">Últimas lecturas</flux:heading>
                    @if ($line['reads'] === [])
                        <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Todavía no hay lecturas.</div>
                    @else
                        <ul class="flex flex-col gap-2">
                            @foreach ($line['reads'] as $read)
                                <li wire:key="op-read-{{ $loop->index }}-{{ $read['at']->getTimestamp() }}" @class(['flex items-center gap-3 rounded-lg border-2 px-3 py-2', 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950' => $read['ok'], 'border-red-500 bg-red-50 dark:bg-red-950' => ! $read['ok']]) data-read="{{ $read['ok'] ? 'ok' : 'error' }}">
                                    @if ($read['ok'])
                                        <flux:icon.check-circle class="size-8 shrink-0 text-emerald-600" />
                                    @else
                                        <flux:icon.x-circle class="size-8 shrink-0 text-red-600" />
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-baseline justify-between gap-x-2">
                                            <span @class(['font-semibold', 'text-emerald-900 dark:text-emerald-100' => $read['ok'], 'text-red-900 dark:text-red-100' => ! $read['ok']])>
                                                {{ $read['ok'] ? 'Contada' : ($read['no_read'] ? 'El lector no logró leer' : 'No contada') }}@if ($read['box'] !== '') · <span class="font-mono">{{ $read['box'] }}</span>@endif
                                            </span>
                                            <span class="font-mono text-xs tabular-nums text-zinc-500">{{ $read['at']->timezone($tz)->format('H:i:s') }}</span>
                                        </div>
                                        <div class="truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $read['ok'] ? '→ '.$read['detail'] : $read['detail'] }}</div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="lg:col-span-3">
                    <flux:heading size="lg" level="2" class="mb-3">Avance de las estibas</flux:heading>
                    @if ($line['stacking']->isEmpty())
                        <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay estibas abiertas.</div>
                    @else
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($line['stacking'] as $pallet)
                                @php
                                    $percent = $pallet->completionPercent();
                                    $missing = max(0, $pallet->standard_boxes_snapshot - $pallet->boxes_count);
                                @endphp
                                <article wire:key="op-pallet-{{ $pallet->id }}" class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-pallet="{{ $pallet->pallet_code }}">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="font-mono text-lg font-semibold text-zinc-900 dark:text-white">{{ $pallet->pallet_code }}</span>
                                        <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $pallet->boxes_count }}<span class="text-base font-normal text-zinc-500"> / {{ $pallet->standard_boxes_snapshot }}</span></span>
                                    </div>
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $pallet->item_code }} · Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }}</div>
                                    <div class="h-4 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                                        <div class="h-full bg-emerald-600" style="width: {{ min(100, $percent) }}%"></div>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ number_format($percent, 1, ',', '.') }} %</span>
                                        <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $missing === 1 ? 'Falta 1 caja' : "Faltan {$missing} cajas" }}</span>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                    @can('pallets.operate')
                        <div class="mt-4"><flux:button icon="cog-6-tooth" :href="route('production.station')" wire:navigate>Ir a la Estación de producción</flux:button></div>
                    @endcan
                </div>
            </div>
        </div>
    @endif

</section>
