<?php

use App\Domain\Settings\AppSettings;
use App\Domain\Settings\Setting;
use App\Enums\Permission;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Parámetros')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::SettingsManage->value), 403);
    }

    /** @var array<string, int|string> */
    public array $values = [];

    public function mount(AppSettings $settings): void
    {
        foreach (Setting::cases() as $setting) {
            $this->values[$setting->name] = $settings->int($setting);
        }
    }

    public function save(AppSettings $settings): void
    {
        $rules = [];
        $attributes = [];
        foreach (Setting::cases() as $setting) {
            $rules['values.'.$setting->name] = $setting->rules();
            $attributes['values.'.$setting->name] = mb_strtolower($setting->label());
        }
        $this->validate($rules, attributes: $attributes);

        foreach (Setting::cases() as $setting) {
            $settings->set(Auth::user(), $setting, (int) $this->values[$setting->name]);
        }

        Flux::toast(variant: 'success', text: 'Parámetros guardados.');
    }
}; ?>

<section class="flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Parámetros</flux:heading>
        <flux:subheading>Valores que cambian cómo opera la aplicación. Cada cambio queda en Auditoría.</flux:subheading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        @foreach (Setting::cases() as $setting)
            <flux:field>
                <flux:label>{{ $setting->label() }}</flux:label>
                <flux:description>{{ $setting->help() }}</flux:description>
                <flux:input.group class="max-w-48">
                    <flux:input type="number" min="1" max="100" wire:model="values.{{ $setting->name }}" data-setting="{{ $setting->value }}" />
                    <flux:input.group.suffix>%</flux:input.group.suffix>
                </flux:input.group>
                <flux:error name="values.{{ $setting->name }}" />
            </flux:field>
        @endforeach
        <div>
            <flux:button type="submit" variant="primary">Guardar</flux:button>
        </div>
    </form>
</section>
