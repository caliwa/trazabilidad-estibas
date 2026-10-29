/**
 * QR reading with the device camera on any phone browser. Chrome on Android
 * has BarcodeDetector; Safari on iPhone and others do not, so there the frames
 * are decoded with jsQR, loaded only when the camera is opened.
 *
 * The camera needs a secure page (HTTPS or localhost): otherwise the browser
 * does not offer navigator.mediaDevices and the camera button stays hidden.
 */
const MAX_SIDE = 800;

async function nativeDetector() {
    if (! ('BarcodeDetector' in window)) {
        return null;
    }

    try {
        const formats = await window.BarcodeDetector.getSupportedFormats();
        if (! formats.includes('qr_code')) {
            return null;
        }
        const detector = new window.BarcodeDetector({ formats: ['qr_code'] });

        return async (video) => (await detector.detect(video))[0]?.rawValue ?? null;
    } catch (e) {
        return null;
    }
}

async function jsQrDetector() {
    const { default: jsQR } = await import('jsqr');
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    return async (video) => {
        const width = video.videoWidth;
        const height = video.videoHeight;
        if (! width || ! height) {
            return null;
        }
        // Smaller frames decode faster and a label QR is still big enough.
        const scale = Math.min(1, MAX_SIDE / Math.max(width, height));
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        const image = context.getImageData(0, 0, canvas.width, canvas.height);

        return jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' })?.data || null;
    };
}

window.QrCamera = {
    available() {
        return !! (navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    },

    /**
     * Opens the back camera in $video and resolves with the first QR read.
     * Returns a function that stops the camera (and the reading).
     */
    async start(video, onRead) {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        let running = true;
        const stop = () => {
            running = false;
            stream.getTracks().forEach((track) => track.stop());
            video.srcObject = null;
        };

        video.setAttribute('playsinline', '');
        video.muted = true;
        video.srcObject = stream;
        await video.play();

        const detect = (await nativeDetector()) ?? (await jsQrDetector());
        const tick = async () => {
            if (! running) {
                return;
            }
            try {
                const text = await detect(video);
                if (text) {
                    stop();
                    onRead(text);

                    return;
                }
            } catch (e) {
                // A frame that cannot be read yet: try the next one.
            }
            setTimeout(tick, 200);
        };
        tick();

        return stop;
    },
};
