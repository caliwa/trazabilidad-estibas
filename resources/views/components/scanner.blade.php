@props([
    // Livewire method called with (text read, read id).
    'action',
    'label' => 'Lectura',
    'placeholder' => 'Lee el código con el escáner',
    // The box QR has several lines: Enter is part of the text, the read ends when the reader stops typing.
    'multiline' => false,
])

{{--
    Scanner field for hand readers that type like a keyboard (USB or
    Bluetooth) and, where the browser has BarcodeDetector (Chrome on Android),
    the device camera. A read is sent when the reader stops typing for a
    moment (or on Enter for one-line codes) with a fresh id, so a retried
    request is never counted twice. wire:ignore keeps the field intact while
    the page refreshes around it.
--}}
<div wire:ignore
    x-data="{
        value: '',
        timer: null,
        camera: false,
        canUseCamera: 'BarcodeDetector' in window && !! navigator.mediaDevices,
        stream: null,
        multiline: @js((bool) $multiline),
        id() {
            return (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
                : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16) });
        },
        typed() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.send(), this.multiline ? 350 : 600);
        },
        enter(e) {
            if (! this.multiline) { e.preventDefault(); this.send(); }
        },
        send(text = null) {
            clearTimeout(this.timer);
            const read = (text ?? this.value).replace(/\s+$/, '');
            this.value = '';
            if (read.trim() === '') return;
            $wire.call(@js($action), read, this.id());
            this.$nextTick(() => this.$refs.field?.focus());
        },
        async openCamera() {
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            } catch (e) { alert('No se pudo abrir la cámara: ' + e.message); return; }
            this.camera = true;
            await this.$nextTick();
            this.$refs.video.srcObject = this.stream;
            await this.$refs.video.play();
            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            const tick = async () => {
                if (! this.camera) return;
                try {
                    const codes = await detector.detect(this.$refs.video);
                    if (codes.length > 0) { const text = codes[0].rawValue; this.closeCamera(); this.send(text); return; }
                } catch (e) {}
                setTimeout(tick, 250);
            };
            tick();
        },
        closeCamera() {
            this.camera = false;
            this.stream?.getTracks().forEach(t => t.stop());
            this.stream = null;
            this.$nextTick(() => this.$refs.field?.focus());
        },
    }"
    x-init="$nextTick(() => $refs.field?.focus())"
    {{ $attributes->class('flex flex-col gap-2') }}
>
    <label class="text-sm font-medium text-zinc-800 dark:text-white">{{ $label }}</label>
    <div class="flex gap-2">
        <textarea x-ref="field" x-model="value" @input="typed" @keydown.enter="enter($event)"
            rows="{{ $multiline ? 3 : 1 }}" placeholder="{{ $placeholder }}" autocomplete="off" autocapitalize="off" spellcheck="false"
            class="block w-full resize-none rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-base text-zinc-900 shadow-xs focus:border-zinc-500 focus:outline-none dark:border-white/10 dark:bg-white/10 dark:text-white"></textarea>
        <template x-if="canUseCamera">
            <button type="button" @click="camera ? closeCamera() : openCamera()" title="Leer con la cámara"
                class="shrink-0 rounded-lg border border-zinc-300 px-3 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-white/10 dark:text-zinc-200 dark:hover:bg-white/5">
                <span x-text="camera ? 'Cerrar cámara' : 'Cámara'"></span>
            </button>
        </template>
    </div>
    <template x-if="camera">
        <video x-ref="video" playsinline muted class="w-full max-w-sm rounded-lg border border-zinc-300 dark:border-white/10"></video>
    </template>
    <p class="text-xs text-zinc-500 dark:text-zinc-400">Deja el cursor en el campo y lee el código. Se envía solo.</p>
</div>
