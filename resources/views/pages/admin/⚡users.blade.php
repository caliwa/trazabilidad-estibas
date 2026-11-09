<?php

use App\Enums\Permission;
use App\Actions\Users\ManageUsers;
use App\Enums\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Usuarios')] class extends Component {
    use WithPagination;

    /**
     * Checked on every request, not only by the route: the component stays
     * protected wherever it is rendered.
     */
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::UsersManage->value), 403);
    }

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    #[Locked]
    public ?int $editingId = null;

    /** @var array{name: string, email: string, role: string} */
    public array $form = ['name' => '', 'email' => '', 'role' => 'viewer'];

    /** Password typed by the administrator, for a new user or to set a new one. Never kept after saving. */
    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public ?int $passwordUserId = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'role', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->reset('editingId', 'form', 'password', 'password_confirmation');
        $this->resetValidation();
        Flux::modal('user-form')->show();
    }

    public function edit(int $userId): void
    {
        $user = User::with('roles')->findOrFail($userId);
        $this->editingId = $user->id;
        $this->form = ['name' => $user->name, 'email' => $user->email, 'role' => $user->roles->first()->name ?? Role::Viewer->value];
        $this->resetValidation();
        Flux::modal('user-form')->show();
    }

    public function save(ManageUsers $users): void
    {
        $rules = [
            'form.name' => ['required', 'string', 'max:255'],
            'form.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'form.role' => ['required', Rule::enum(Role::class)],
        ];
        if ($this->editingId === null) {
            $rules['password'] = ['required', 'string', 'confirmed', Password::defaults()];
        }

        $valid = $this->validate($rules, attributes: ['form.name' => 'nombre', 'form.email' => 'correo', 'form.role' => 'rol', 'password' => 'contraseña']);
        $data = $valid['form'];

        if ($this->editingId === null) {
            $user = $users->create(Auth::user(), [...$data, 'password' => $this->password]);
            $this->reset('password', 'password_confirmation');
            Flux::toast(variant: 'success', text: "Usuario creado. {$user->name} ya puede entrar con su correo y la contraseña que le asignaste.");
        } else {
            $users->update(Auth::user(), User::findOrFail($this->editingId), $data);
            Flux::toast(variant: 'success', text: 'Usuario actualizado.');
        }

        Flux::modal('user-form')->close();
        unset($this->users);
    }

    public function askPassword(int $userId): void
    {
        $this->passwordUserId = User::query()->findOrFail($userId)->id;
        $this->reset('password', 'password_confirmation');
        $this->resetValidation();
        Flux::modal('user-password')->show();
    }

    public function savePassword(ManageUsers $users): void
    {
        $this->validate(['password' => ['required', 'string', 'confirmed', Password::defaults()]], attributes: ['password' => 'contraseña']);

        $user = User::query()->findOrFail($this->passwordUserId);
        $users->setPassword(Auth::user(), $user, $this->password);
        $this->reset('password', 'password_confirmation', 'passwordUserId');

        Flux::modal('user-password')->close();
        Flux::toast(variant: 'success', text: "Contraseña de {$user->name} actualizada. Sus otras sesiones se cerraron.");
    }

    public function toggleActive(int $userId, ManageUsers $users): void
    {
        $user = User::findOrFail($userId);
        $users->setActive(Auth::user(), $user, ! $user->isActive());

        Flux::toast(variant: 'success', text: $user->isActive() ? "{$user->name} reactivado." : "{$user->name} desactivado.");
        unset($this->users);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $like = '%'.addcslashes(trim($this->search), '\\%_').'%';

        return User::query()
            ->with('roles:id,name')
            ->when(trim($this->search) !== '', fn ($q) => $q->whereAny(['name', 'email'], 'ilike', $like))
            ->when($this->role !== '', fn ($q) => $q->role($this->role))
            ->when($this->status === 'active', fn ($q) => $q->active())
            ->when($this->status === 'inactive', fn ($q) => $q->whereNotNull('deactivated_at'))
            ->orderBy('name')
            ->paginate(20);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Usuarios</flux:heading>
            <flux:subheading>Cuentas, roles y acceso al sistema</flux:subheading>
        </div>
        <flux:button variant="primary" icon="user-plus" wire:click="create">Nuevo usuario</flux:button>
    </div>

    <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="sm:col-span-2">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Buscar por nombre o correo" aria-label="Buscar usuario" clearable />
        </div>
        <flux:select wire:model.live="role" aria-label="Rol">
            <flux:select.option value="">Todos los roles</flux:select.option>
            @foreach (Role::cases() as $option)
                <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" aria-label="Estado">
            <flux:select.option value="active">Activos</flux:select.option>
            <flux:select.option value="inactive">Desactivados</flux:select.option>
            <flux:select.option value="all">Todos</flux:select.option>
        </flux:select>
    </div>

    @error('user')
        <flux:callout variant="danger" icon="exclamation-triangle">{{ $message }}</flux:callout>
    @enderror

    <flux:table :paginator="$this->users">
        <flux:table.columns>
            <flux:table.column>Usuario</flux:table.column>
            <flux:table.column>Rol</flux:table.column>
            <flux:table.column>Estado</flux:table.column>
            <flux:table.column><span class="sr-only">Acciones</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->users as $user)
                @php
                    $userRole = Role::tryFrom($user->roles->first()->name ?? '');
                @endphp
                <flux:table.row :key="$user->id">
                    <flux:table.cell>
                        <div class="flex items-center gap-3">
                            <flux:avatar size="sm" :name="$user->name" :initials="$user->initials()" />
                            <div>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $user->name }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</div>
                            </div>
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($userRole)
                            <flux:badge size="sm" :color="$userRole === Role::Admin ? 'red' : ($userRole === Role::TraceabilityAdmin ? 'blue' : 'zinc')">{{ $userRole->label() }}</flux:badge>
                        @else
                            <span class="text-xs text-zinc-500">Sin rol</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($user->isActive())
                            <flux:badge size="sm" color="green" icon="check-circle">Activo</flux:badge>
                        @else
                            <flux:badge size="sm" icon="no-symbol">Desactivado</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $user->id }})" aria-label="Editar a {{ $user->name }}" />
                            <flux:button size="sm" variant="ghost" icon="key" wire:click="askPassword({{ $user->id }})" aria-label="Asignar contraseña a {{ $user->name }}" />
                            @if (! $user->is(auth()->user()))
                                @if ($user->isActive())
                                    <flux:button size="sm" variant="ghost" icon="no-symbol" wire:click="toggleActive({{ $user->id }})"
                                        wire:confirm="¿Desactivar a {{ $user->name }}? No podrá entrar al sistema."
                                        aria-label="Desactivar a {{ $user->name }}" />
                                @else
                                    <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="toggleActive({{ $user->id }})" aria-label="Reactivar a {{ $user->name }}" />
                                @endif
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="user-form" class="w-full max-w-md">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Editar usuario' : 'Nuevo usuario' }}</flux:heading>
            <flux:input wire:model="form.name" label="Nombre" required />
            <flux:input wire:model="form.email" type="email" label="Correo" required />
            <flux:select wire:model="form.role" label="Rol">
                @foreach (Role::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @unless ($editingId)
                <flux:input wire:model="password" type="password" label="Contraseña" autocomplete="new-password" viewable required />
                <flux:input wire:model="password_confirmation" type="password" label="Confirmar contraseña" autocomplete="new-password" viewable required />
                <flux:text class="text-sm">Entrégale la contraseña a la persona por un medio seguro. Podrá cambiarla después en su perfil.</flux:text>
            @endunless
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Guardar</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="user-password" class="w-full max-w-md">
        <form wire:submit="savePassword" class="flex flex-col gap-4">
            @php
                $passwordUser = $passwordUserId ? \App\Models\User::query()->find($passwordUserId, ['id', 'name', 'email']) : null;
            @endphp
            <flux:heading size="lg">Asignar contraseña</flux:heading>
            @if ($passwordUser)
                <flux:text>{{ $passwordUser->name }} · {{ $passwordUser->email }}</flux:text>
            @endif
            <flux:input wire:model="password" type="password" label="Nueva contraseña" autocomplete="new-password" viewable required />
            <flux:input wire:model="password_confirmation" type="password" label="Confirmar contraseña" autocomplete="new-password" viewable required />
            <flux:text class="text-sm">La contraseña anterior deja de servir y se cierran las sesiones abiertas de esa persona. Queda en Auditoría quién la cambió, nunca la contraseña.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Guardar contraseña</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
