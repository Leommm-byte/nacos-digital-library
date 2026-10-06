/*
 * The review page's preview (review/show.blade.php): draws the first pages
 * of the upload with PDF.js, which only fetches the byte ranges it needs.
 */
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist/legacy/build/pdf.min.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

const PAGES = 4;

GlobalWorkerOptions.workerSrc = workerUrl;

export async function initReviewPreview() {
    const box = document.querySelector('[data-pdf-preview]');

    if (!box) {
        return;
    }

    const slots = [...box.children];
    const assets = box.dataset.assets;

    try {
        const pdf = await getDocument({
            url: box.dataset.src,
            disableAutoFetch: true,
            disableStream: true,
            standardFontDataUrl: `${assets}standard_fonts/`,
            cMapUrl: `${assets}cmaps/`,
            wasmUrl: `${assets}wasm/`,
        }).promise;

        for (let number = 1; number <= PAGES; number++) {
            const slot = slots[number - 1];

            if (number > pdf.numPages) {
                slot?.remove();
                continue;
            }

            const page = await pdf.getPage(number);
            const width = (slot?.clientWidth || 160) * Math.min(window.devicePixelRatio || 1, 2);
            const viewport = page.getViewport({ scale: width / page.getViewport({ scale: 1 }).width });
            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', `Page ${number}`);
            await page.render({ canvas, viewport }).promise;
            slot?.replaceWith(canvas);
        }
    } catch {
        box.replaceChildren(Object.assign(document.createElement('p'), {
            className: 'text-sm text-muted',
            textContent: 'The preview couldn\'t be shown. Open the book to check it.',
        }));
    }
}
