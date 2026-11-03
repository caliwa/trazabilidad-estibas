@props([
    // SapWait::status() of the page's wait, or null when nothing is waiting.
    'status',
    'title' => '',
])

{{--
    Over the whole screen while documents wait for SAP's answer: a spinner
    while waiting, then green (all confirmed), red (refused) or amber
    (pending reconciliation, or no answer in time). Nothing else on the page
    can be used until SAP answered and the person closes it.
--}}
@if ($status !== null)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/60 p-4 backdrop-blur-sm" role="alertdialog" aria-modal="true" aria-live="assertive"
        data-sap-wait="{{ $status['done'] ? ($status['ok'] ? 'ok' : 'answered') : ($status['timed_out'] ? 'timeout' : 'waiting') }}"
        @if (! $status['done'] && ! $status['timed_out']) wire:poll.2s="checkSapWait" @endif>
        <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-900">
            @if (! $status['done'] && ! $status['timed_out'])
                <div class="flex items-center gap-4 bg-sky-700 px-6 py-5 text-white">
                    <svg class="size-12 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <div>
                        <div class="text-2xl font-bold uppercase tracking-wide">Esperando respuesta de SAP</div>
                        <div class="text-sm opacity-90">{{ $title }} · {{ $status['seconds'] }} s. No cierres ni continúes hasta que SAP responda.</div>
                    </div>
                </div>
            @elseif ($status['done'] && $status['ok'])
                <div class="flex items-center gap-4 bg-emerald-600 px-6 py-5 text-white">
                    <flux:icon.check-circle class="size-12 shrink-0" />
                    <div>
                        <div class="text-2xl font-bold uppercase tracking-wide">SAP confirmó</div>
                        <div class="text-sm opacity-90">{{ $title }}</div>
                    </div>
                </div>
            @elseif ($status['done'])
                @php($refused = collect($status['rows'])->contains('state', 'failed'))
                <div @class(['flex items-center gap-4 px-6 py-5 text-white', 'bg-red-600' => $refused, 'bg-amber-600' => ! $refused])>
                    <flux:icon.exclamation-triangle class="size-12 shrink-0" />
                    <div>
                        <div class="text-2xl font-bold uppercase tracking-wide">{{ $refused ? 'SAP no lo aceptó' : 'SAP no confirmó' }}</div>
                        <div class="text-sm opacity-90">{{ $title }}. Revisa el detalle.</div>
                    </div>
                </div>
            @else
                <div class="flex items-center gap-4 bg-amber-600 px-6 py-5 text-white">
                    <flux:icon.clock class="size-12 shrink-0" />
                    <div>
                        <div class="text-2xl font-bold uppercase tracking-wide">Sin respuesta de SAP</div>
                        <div class="text-sm opacity-90">Pasaron {{ \App\Domain\Integration\SapWait::TIMEOUT_SECONDS }} s. Sigue en proceso: revisa su estado antes de repetir nada.</div>
                    </div>
                </div>
            @endif

            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($status['rows'] as $row)
                    <li class="flex items-start gap-3 px-6 py-3">
                        @switch($row['state'])
                            @case('ok') <flux:icon.check-circle variant="solid" class="mt-0.5 size-6 shrink-0 text-emerald-600" /> @break
                            @case('failed') <flux:icon.x-circle variant="solid" class="mt-0.5 size-6 shrink-0 text-red-600" /> @break
                            @case('pending') <flux:icon.exclamation-triangle variant="solid" class="mt-0.5 size-6 shrink-0 text-amber-600" /> @break
                            @default <svg class="mt-0.5 size-6 shrink-0 animate-spin text-sky-700" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        @endswitch
                        <div class="min-w-0">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $row['label'] }}</div>
                            <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $row['message'] }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($status['done'] || $status['timed_out'])
                <div class="flex justify-end border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">
                    <flux:button variant="primary" wire:click="closeSapWait" data-sap-wait-close>Entendido, continuar</flux:button>
                </div>
            @endif
        </div>
    </div>
@endif
