<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group heading="Inicio" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        Inicio
                    </flux:sidebar.item>
                    <livewire:notifications.bell />
                </flux:sidebar.group>

                @canany(['pallets.operate', 'pallets.view', 'box_labels.print', 'orders.view'])
                    <flux:sidebar.group heading="Producción" class="grid">
                        @can('orders.view')
                            <flux:sidebar.item icon="chart-bar" :href="route('orders.index')" :current="request()->routeIs('orders.*')" wire:navigate>Tablero de OF</flux:sidebar.item>
                        @endcan
                        @can('pallets.view')
                            <flux:sidebar.item icon="squares-2x2" :href="route('pallets.index')" :current="request()->routeIs('pallets.index', 'pallets.show')" wire:navigate>Estibas</flux:sidebar.item>
                        @endcan
                        @can('box_labels.print')
                            <flux:sidebar.item icon="qr-code" :href="route('box-labels.index')" :current="request()->routeIs('box-labels.*')" wire:navigate>Etiquetas de caja</flux:sidebar.item>
                        @endcan
                        @can('pallets.operate')
                            <flux:sidebar.item icon="cog-6-tooth" :href="route('production.station')" :current="request()->routeIs('production.station')" wire:navigate>Estación de producción</flux:sidebar.item>
                        @endcan
                        @can('pallets.view')
                            <flux:sidebar.item icon="exclamation-triangle" :href="route('pallets.rejected')" :current="request()->routeIs('pallets.rejected')" wire:navigate>Lecturas rechazadas</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany

                @canany(['quality.manage', 'audits.count', 'discrepancies.resolve'])
                    <flux:sidebar.group heading="CEDI" class="grid">
                        @can('quality.manage')
                            <flux:sidebar.item icon="shield-exclamation" :href="route('quality.index')" :current="request()->routeIs('quality.*')" wire:navigate>Calidad</flux:sidebar.item>
                        @endcan
                        @can('audits.count')
                            <flux:sidebar.item icon="qr-code" :href="route('cedi.count')" :current="request()->routeIs('cedi.count')" wire:navigate>Conteo ciego</flux:sidebar.item>
                        @endcan
                        @can('discrepancies.resolve')
                            <flux:sidebar.item icon="exclamation-triangle" :href="route('discrepancies.index')" :current="request()->routeIs('discrepancies.*')" wire:navigate>Discrepancias</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany

                @can('receipts.request')
                    <flux:sidebar.group heading="SAP" class="grid">
                        <flux:sidebar.item icon="inbox-arrow-down" :href="route('receipts.index')" :current="request()->routeIs('receipts.*')" wire:navigate>Recibos de producción</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @canany(['users.manage', 'audit.view', 'ops.alerts'])
                    <flux:sidebar.group heading="Administración" class="grid">
                        @can('ops.alerts')
                            <flux:sidebar.item icon="bell-alert" :href="route('operations.index')" :current="request()->routeIs('operations.*')" wire:navigate>Estado operativo</flux:sidebar.item>
                        @endcan
                        @can('users.manage')
                            <flux:sidebar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>Usuarios</flux:sidebar.item>
                        @endcan
                        @can('audit.view')
                            <flux:sidebar.item icon="shield-check" :href="route('admin.audit')" :current="request()->routeIs('admin.audit')" wire:navigate>Auditoría</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
