<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notificaciones')] class extends Component {
    public bool $emailNotifications = true;

    public function mount(): void
    {
        $this->emailNotifications = Auth::user()->email_notifications;
    }

    public function save(): void
    {
        Auth::user()->forceFill(['email_notifications' => $this->emailNotifications])->save();

        Flux::toast(variant: 'success', text: 'Preferencias guardadas.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout heading="Notificaciones" subheading="Cómo quieres enterarte de las novedades">
        <form wire:submit="save" class="my-6 flex w-full flex-col gap-6">
            <flux:switch wire:model="emailNotifications" label="Recibir notificaciones por correo"
                description="Discrepancias, operaciones pendientes de conciliación y errores con SAP. Las notificaciones dentro de la aplicación siempre están activas." />

            <div>
                <flux:button variant="primary" type="submit">Guardar</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
