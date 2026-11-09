<?php

use App\Actions\Pallets\ManageReasons;
use App\Domain\Pallets\Enums\ReasonList;
use App\Enums\Permission;
use App\Models\BoxVoidReason;
use App\Models\PalletCloseReason;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Motivos')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::CloseReasonsManage->value), 403);
    }

    /** Which list is shown: closing incomplete pallets or voiding boxes. */
    #[Url(as: 'lista', except: 'cierre')]
    public string $list = 'cierre';

    #[Locked]
    public ?int $editingId = null;

    /** @var array{name: string, sort_order: int|string} */
    public array $form = ['name' => '', 'sort_order' => 0];

    public function updatedList(): void
    {
        $this->editingId = null;
        unset($this->reasons);
    }

    public function reasonList(): ReasonList
    {
        return ReasonList::tryFrom($this->list) ?? ReasonList::PalletClose;
    }

    public function create(): void
    {
        $this->reset('editingId');
        $model = $this->reasonList()->model();
        $this->form = ['name' => '', 'sort_order' => ((int) $model::query()->max('sort_order')) + 10];
        $this->resetValidation();
        Flux::modal('reason-form')->show();
    }

    public function edit(int $reasonId): void
    {
        $reason = $this->find($reasonId);
        $this->editingId = $reason->id;
        $this->form = ['name' => $reason->name, 'sort_order' => $reason->sort_order];
        $this->resetValidation();
        Flux::modal('reason-form')->show();
    }

    public function save(ManageReasons $reasons): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ], attributes: ['form.name' => 'nombre', 'form.sort_order' => 'orden'])['form'];
        $data = ['name' => (string) $data['name'], 'sort_order' => (int) $data['sort_order']];

        if ($this->editingId === null) {
            $reasons->create(Auth::user(), $this->reasonList(), $data);
            Flux::toast(variant: 'success', text: 'Motivo creado.');
        } else {
            $reasons->update(Auth::user(), $this->reasonList(), $this->find($this->editingId), $data);
            Flux::toast(variant: 'success', text: 'Motivo actualizado.');
        }

        Flux::modal('reason-form')->close();
        unset($this->reasons);
    }

    public function toggleActive(int $reasonId, ManageReasons $reasons): void
    {
        $reason = $this->find($reasonId);
        $reasons->setActive(Auth::user(), $this->reasonList(), $reason, ! $reason->active);

        Flux::toast(variant: 'success', text: $reason->active ? "«{$reason->name}» reactivado." : "«{$reason->name}» desactivado.");
        unset($this->reasons);
    }

    /**
     * @return Collection<int, PalletCloseReason|BoxVoidReason>
     */
    #[Computed]
    public function reasons(): Collection
    {
        $list = $this->reasonList();
        $model = $list->model();
        [$table, $column] = $list->usedBy();
        $own = (new $model)->getTable();

        return $model::query()
            ->ordered()
            ->addSelect(['uses' => DB::table($table)->selectRaw('count(*)')->whereColumn("{$table}.{$column}", "{$own}.id")])
            ->get()
            ->toBase();
    }

    private function find(int $reasonId): PalletCloseReason|BoxVoidReason
    {
        $model = $this->reasonList()->model();

        return $model::query()->findOrFail($reasonId);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Motivos</flux:heading>
            <flux:subheading>Listas que se muestran en la estación de producción</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">Nuevo motivo</flux:button>
    </div>

    <flux:radio.group wire:model.live="list" variant="segmented" aria-label="Lista de motivos">
        @foreach (ReasonList::cases() as $option)
            <flux:radio :value="$option->value" :label="'Motivos de '.mb_strtolower($option->label())" />
        @endforeach
    </flux:radio.group>

    @if ($this->reasons->where('active', true)->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle">{{ $this->reasonList()->emptyWarning() }}</flux:callout>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Orden</flux:table.column>
            <flux:table.column>Motivo</flux:table.column>
            <flux:table.column>{{ $this->reasonList()->usesLabel() }}</flux:table.column>
            <flux:table.column>Estado</flux:table.column>
            <flux:table.column><span class="sr-only">Acciones</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->reasons as $reason)
                <flux:table.row :key="$list.'-'.$reason->id" data-reason="{{ $reason->name }}">
                    <flux:table.cell class="tabular-nums">{{ $reason->sort_order }}</flux:table.cell>
                    <flux:table.cell class="font-medium text-zinc-800 dark:text-white">{{ $reason->name }}</flux:table.cell>
                    <flux:table.cell class="tabular-nums">{{ number_format((int) $reason->getAttribute('uses'), 0, ',', '.') }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($reason->active)
                            <flux:badge size="sm" color="green" icon="check-circle">Activo</flux:badge>
                        @else
                            <flux:badge size="sm" icon="no-symbol">Inactivo</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $reason->id }})" aria-label="Editar {{ $reason->name }}" />
                            @if ($reason->active)
                                <flux:button size="sm" variant="ghost" icon="no-symbol" wire:click="toggleActive({{ $reason->id }})"
                                    wire:confirm="¿Desactivar «{{ $reason->name }}»? Ya no se podrá escoger en la estación. Lo que ya se registró con este motivo lo conserva."
                                    aria-label="Desactivar {{ $reason->name }}" />
                            @else
                                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="toggleActive({{ $reason->id }})" aria-label="Reactivar {{ $reason->name }}" />
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-zinc-500">Todavía no hay motivos. Crea el primero con «Nuevo motivo».</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:text class="text-sm">Los motivos no se borran: se desactivan, así lo que ya se registró conserva el suyo. El orden define cómo aparecen en la lista de la estación.</flux:text>

    <flux:modal name="reason-form" class="w-full max-w-md">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Editar motivo' : 'Nuevo motivo' }}</flux:heading>
            <flux:input wire:model="form.name" label="Nombre" maxlength="100" required />
            <flux:input wire:model="form.sort_order" type="number" min="0" max="9999" label="Orden" description="Los números menores aparecen primero." required />
            @if ($editingId)
                <flux:text class="text-sm">Si cambias el nombre, lo que ya se registró con este motivo mostrará el nombre nuevo. El historial de trazabilidad guarda el nombre que tenía en ese momento.</flux:text>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Guardar</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
