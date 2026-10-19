<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Landing page after login. The pallet board and the operating screens
 * arrive in phases 3 to 6; until then it only tells people their role.
 */
new #[Title('Inicio')] class extends Component {
    public function roleLabel(): string
    {
        $role = Role::tryFrom((string) Auth::user()?->getRoleNames()->first());

        return $role?->label() ?? 'Sin rol asignado';
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Trazabilidad de estibas</flux:heading>
        <flux:subheading>Producción, CEDI y documentos SAP</flux:subheading>
    </div>

    <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <flux:text>Tu rol: <span class="font-medium text-zinc-800 dark:text-white">{{ $this->roleLabel() }}</span></flux:text>
    </div>
</section>
