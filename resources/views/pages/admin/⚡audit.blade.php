<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Administration audit: user and role changes, with who, when and IP.
 * Operational traceability (pallets, documents, counts) has its own log.
 */
new #[Title('Auditoría')] class extends Component {
    use WithPagination;

    /**
     * Checked on every request, not only by the route: the component stays
     * protected wherever it is rendered.
     */
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::AuditView->value), 403);
    }

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $user = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    #[Computed]
    public function adminEntries(): LengthAwarePaginator
    {
        $like = '%'.addcslashes(trim($this->search), '\\%_').'%';

        return AuditLog::query()
            ->with('user:id,name')
            ->when($this->kind !== '', fn ($q) => $q->where('action', 'like', $this->kind.'.%'))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('description', 'ilike', $like))
            ->when($this->user !== '', fn ($q) => $q->where('user_id', (int) $this->user))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'))
            ->latest('created_at')->latest('id')
            ->paginate(25);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed(persist: true)]
    public function people(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }

    public function adminValue(string $field, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            $field === 'role' => Role::tryFrom((string) $value)?->label() ?? (string) $value,
            is_bool($value) => $value ? 'Sí' : 'No',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Auditoría</flux:heading>
        <flux:subheading>Quién cambió qué, cuándo y desde dónde</flux:subheading>
    </div>

    <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" label="Buscar" placeholder="Usuario o elemento" clearable />
        <flux:select wire:model.live="kind" label="Tipo">
            <flux:select.option value="">Todos</flux:select.option>
            <flux:select.option value="user">Usuarios y roles</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="user" label="Persona">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach ($this->people as $person)
                <flux:select.option :value="$person->id" wire:key="p-{{ $person->id }}">{{ $person->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="from" label="Desde" />
        <flux:input type="date" wire:model.live="to" label="Hasta" />
    </div>

    @php
        $entries = $this->adminEntries;
    @endphp

    <div wire:loading.class="opacity-60" wire:target="search, kind, user, from, to, gotoPage, nextPage, previousPage" class="transition-opacity">
        @if ($entries->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                No hay registros con esos filtros.
            </div>
        @else
            <flux:table :paginator="$entries">
                <flux:table.columns>
                    <flux:table.column>Fecha</flux:table.column>
                    <flux:table.column>Persona</flux:table.column>
                    <flux:table.column>Acción</flux:table.column>
                    <flux:table.column>Cambios</flux:table.column>
                    <flux:table.column>IP</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($entries as $entry)
                        @php
                            $rows = collect(array_unique([...array_keys($entry->old_values ?? []), ...array_keys($entry->new_values ?? [])]))
                                ->map(fn ($field) => ['field' => $field, 'old' => $this->adminValue($field, $entry->old_values[$field] ?? null), 'new' => $this->adminValue($field, $entry->new_values[$field] ?? null)])
                                ->all();
                        @endphp
                        <flux:table.row :key="$entry->id">
                            <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $entry->created_at->translatedFormat('d M Y, h:i a') }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $entry->user?->name ?? 'Sistema' }}</flux:table.cell>
                            <flux:table.cell class="max-w-80 whitespace-normal">
                                <div class="font-medium text-zinc-800 dark:text-white">
                                    {{ $entry->actionLabel() }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ \Illuminate\Support\Str::limit((string) $entry->description, 120) }}
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="min-w-64 whitespace-normal text-xs">
                                @forelse ($rows as $row)
                                    <div><span class="font-medium">{{ $row['field'] }}:</span> <span class="text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($row['old'], 60) }}</span> → {{ \Illuminate\Support\Str::limit($row['new'], 60) }}</div>
                                @empty
                                    <span class="text-zinc-400">—</span>
                                @endforelse
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $entry->ip_address ?? '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
