@props([
    // Livewire property that receives the text read.
    'model',
    // Livewire method called right after, as if the person had pressed the button.
    'submit',
])

{{--
    "Cámara" button for a field that is read with a hand scanner: opens the
    back camera of the phone (window.QrCamera), puts the QR read into the
    field and sends it. Hidden where the browser offers no camera (for
    example a page served without HTTPS).
--}}
<div wire:ignore
    x-data="{
        open: false,
        stop: null,
        available: !! window.QrCamera?.available(),
        async start() {
            this.open = true;
            await this.$nextTick();
            try {
                this.stop = await window.QrCamera.start(this.$refs.video, (text) => {
                    this.close();
                    $wire.set(@js($model), text.replace(/\s+$/, ''), false);
                    $wire.call(@js($submit));
                });
            } catch (e) { this.open = false; alert('No se pudo abrir la cámara: ' + e.message); }
        },
        close() {
            this.open = false;
            this.stop?.();
            this.stop = null;
        },
    }"
    {{-- Closing the modal (Cancelar, X, Esc) turns the camera off. --}}
    x-init="$el.closest('dialog')?.addEventListener('close', () => close())"
    {{ $attributes->class('flex flex-col gap-2') }}
>
    <template x-if="available">
        <button type="button" data-qr-camera @click="open ? close() : start()"
            class="self-start rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-white/10 dark:text-zinc-200 dark:hover:bg-white/5">
            <span x-text="open ? 'Cerrar cámara' : 'Leer con la cámara'"></span>
        </button>
    </template>
    <template x-if="open">
        <video x-ref="video" playsinline muted class="w-full rounded-lg border border-zinc-300 dark:border-white/10"></video>
    </template>
</div>
