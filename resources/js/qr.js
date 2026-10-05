/*
 * Draws QR codes for [data-qr="<text>"] elements (two-step verification
 * setup). The library is only downloaded on pages that need it.
 */
export async function initQr() {
    const targets = document.querySelectorAll('[data-qr]');

    if (!targets.length) {
        return;
    }

    const { default: QRCode } = await import('qrcode');

    for (const target of targets) {
        const svg = await QRCode.toString(target.dataset.qr, {
            type: 'svg',
            margin: 0,
            errorCorrectionLevel: 'M',
            color: { dark: '#111a15', light: '#ffffff' },
        });

        target.innerHTML = svg;
    }
}
