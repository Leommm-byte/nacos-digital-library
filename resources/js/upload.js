/*
 * The upload form (uploads/create.blade.php). Does the heavy work on the
 * student's device before anything is sent:
 *
 * - Photos of pages are shown as thumbnails (remove any before sending),
 *   turned upright and shrunk to at most 2000 px, so a 60-page book is a
 *   few megabytes instead of hundreds.
 * - The words on each photo are read on the device (Tesseract OCR, self-
 *   hosted, downloaded only when needed), so the book can be searched.
 *   Skipped when the server reads the pages itself (AI), on slow or
 *   data-saving connections, and once it takes too long: searchable text is
 *   a bonus, never a reason for the upload to drag.
 * - For a PDF, PDF.js counts the pages, takes the text and draws page 1 as
 *   the cover when none is chosen, within a time limit.
 * - The upload shows its progress, and errors appear next to their fields.
 * - While it works the screen is kept on and leaving the page asks first. A
 *   dropped connection (or a phone that paused the page) is retried when
 *   the student is back; the upload_key makes sure it is stored once.
 *
 * Without this script the form posts normally and the server does without.
 */
import { formatSize } from './files';
import { toast } from './toast';

const ORIGINAL_PHOTO_MAX = 25 * 1024 * 1024;
const TEXT_MAX_CHARS = 300_000;

// Reading text on the device gives up after this long, per page and overall.
const OCR_PAGE_MS = 20_000;
const OCR_TOTAL_MS = 90_000;
// Counting a PDF's pages, its text and its cover: then it is sent without.
const PDF_PREPARE_MS = 30_000;
// Tries at sending before telling the student it didn't work.
const SEND_TRIES = 4;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Resolves to `fallback` if `promise` takes longer than `ms`. */
function within(promise, ms, fallback) {
    let timer;
    return Promise.race([
        promise,
        new Promise((resolve) => {
            timer = setTimeout(() => resolve(fallback), ms);
        }),
    ]).finally(() => clearTimeout(timer));
}

/** A random UUID (v4), the form the server expects for upload_key. */
function newKey() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Data saver on, or a 2G/3G connection: the 7 MB OCR download isn't worth it. */
function slowNetwork() {
    const connection = navigator.connection;
    return Boolean(connection?.saveData) || /(^|-)(2g|3g)$/.test(connection?.effectiveType ?? '');
}

export function initUpload() {
    const form = document.querySelector('form[data-upload]');

    if (!form || !window.FormData || !window.XMLHttpRequest) {
        return;
    }

    const $ = (selector) => form.querySelector(selector);
    const ui = {
        pdf: $('[data-upload-pdf]'),
        pdfList: $('[data-upload-pdf-list]'),
        pages: $('[data-upload-pages]'),
        pageList: $('[data-upload-page-list]'),
        cover: $('[data-upload-cover]'),
        progress: $('[data-upload-progress]'),
        step: $('[data-upload-step]'),
        percent: $('[data-upload-percent]'),
        bar: $('[data-upload-bar]'),
        submit: $('[data-upload-submit]'),
    };
    const limits = {
        pdf: Number(form.dataset.pdfMax),
        page: Number(form.dataset.pageMax),
        cover: Number(form.dataset.coverMax),
        pages: Number(form.dataset.maxPages),
        pixels: Number(form.dataset.pagePixels) || 2000,
    };
    const assets = form.dataset.assets;
    // The server reads every page with AI, so the device needn't.
    const serverReads = form.dataset.serverReads === '1';

    // Photos chosen so far (the file input only holds the last selection).
    let photos = [];
    let pdfFile = null;

    const type = () => form.querySelector('input[name="type"]:checked')?.value ?? 'pdf';

    /* Choosing files ------------------------------------------------- */

    function setPdf(file) {
        pdfFile = file ?? null;
        ui.pdfList.replaceChildren();
        clearError('pdf');

        if (!pdfFile) {
            return;
        }

        const problem = !/\.pdf$/i.test(pdfFile.name) && pdfFile.type !== 'application/pdf'
            ? 'Choose a PDF file.'
            : pdfFile.size > limits.pdf
              ? `This PDF is ${formatSize(pdfFile.size)}. The limit is ${formatSize(limits.pdf)}.`
              : null;

        const row = document.createElement('li');
        row.className = 'upload-file' + (problem ? ' is-invalid' : '');
        row.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>';
        const name = document.createElement('span');
        name.className = 'min-w-0 flex-1 truncate font-medium';
        name.textContent = pdfFile.name;
        const size = document.createElement('span');
        size.className = 'text-muted';
        size.textContent = formatSize(pdfFile.size);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'icon-btn';
        remove.setAttribute('aria-label', `Remove ${pdfFile.name}`);
        remove.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
        remove.addEventListener('click', () => {
            ui.pdf.value = '';
            setPdf(null);
            ui.pdf.focus();
        });
        row.append(name, size, remove);
        ui.pdfList.append(row);

        if (problem) {
            showError('pdf', problem);
            pdfFile = null;
        }
    }

    function addPhotos(files) {
        clearError('pages');
        const images = [...files].filter((file) => /^image\/(jpeg|png|webp)$/.test(file.type));

        if (images.length < files.length) {
            showError('pages', 'Only JPG, PNG and WebP photos can be added.');
        }

        for (const file of images) {
            if (photos.length >= limits.pages) {
                showError('pages', `A book can have at most ${limits.pages} pages. Split longer books into parts.`);
                break;
            }
            if (file.size > ORIGINAL_PHOTO_MAX) {
                showError('pages', `${file.name} is too large (${formatSize(file.size)}).`);
                continue;
            }
            photos.push({ file, url: URL.createObjectURL(file) });
        }

        renderPhotos();
    }

    function renderPhotos() {
        ui.pageList.replaceChildren(
            ...photos.map((photo, index) => {
                const item = document.createElement('li');
                const img = document.createElement('img');
                img.src = photo.url;
                img.alt = '';
                img.decoding = 'async';
                const number = document.createElement('span');
                number.className = 'upload-page-number';
                number.textContent = String(index + 1);
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'upload-page-remove';
                remove.setAttribute('aria-label', `Remove page ${index + 1}`);
                remove.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
                remove.addEventListener('click', () => {
                    URL.revokeObjectURL(photo.url);
                    photos.splice(index, 1);
                    renderPhotos();
                });
                item.append(img, number, remove);
                return item;
            }),
        );
    }

    ui.pdf.addEventListener('change', () => setPdf(ui.pdf.files[0]));
    ui.pages.addEventListener('change', () => {
        addPhotos(ui.pages.files);
        ui.pages.value = '';
    });

    for (const zone of form.querySelectorAll('[data-dropzone]')) {
        zone.addEventListener('dragover', (event) => {
            event.preventDefault();
            zone.classList.add('is-dragging');
        });
        zone.addEventListener('dragleave', () => zone.classList.remove('is-dragging'));
        zone.addEventListener('drop', (event) => {
            event.preventDefault();
            zone.classList.remove('is-dragging');
            const files = event.dataTransfer?.files ?? [];
            if (zone.contains(ui.pdf)) {
                setPdf(files[0]);
            } else {
                addPhotos(files);
            }
        });
    }

    /* Errors ---------------------------------------------------------- */

    const fieldFor = (name) => (name === 'pages' ? ui.pages : form.querySelector(`[name="${name}"]`));

    function clearError(name) {
        const field = fieldFor(name);
        form.querySelector(`#${CSS.escape(name)}-error`)?.remove();
        field?.removeAttribute('aria-invalid');
    }

    function showError(name, message) {
        const field = fieldFor(name);
        if (!field) {
            return false;
        }

        clearError(name);
        const error = document.createElement('p');
        error.id = `${name}-error`;
        error.className = 'field-error';
        error.textContent = message;
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', error.id);

        const anchor = field.closest('.upload-panel')?.lastElementChild ?? field.closest('div');
        anchor.after(error);
        return true;
    }

    function showErrors(errors) {
        form.querySelectorAll('.field-error').forEach((error) => error.remove());
        let first = null;
        let unplaced = [];

        for (const [key, messages] of Object.entries(errors)) {
            const name = key.startsWith('pages.') ? 'pages' : key.startsWith('page_text') ? null : key;
            if (name && showError(name, messages[0])) {
                first ??= fieldFor(name);
            } else {
                unplaced.push(messages[0]);
            }
        }

        if (unplaced.length) {
            toast(unplaced[0]);
        }
        first?.closest('.card, .upload-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* Progress -------------------------------------------------------- */

    function progress(step, fraction = null) {
        ui.progress.hidden = false;
        ui.step.textContent = step;
        ui.percent.textContent = fraction === null ? '' : `${Math.round(fraction * 100)}%`;
        ui.bar.style.transform = `scaleX(${fraction ?? 0})`;
    }

    /* Keeping the page alive ----------------------------------------- */

    let busy = false;
    let wakeLock = null;

    async function keepAwake() {
        try {
            wakeLock = (await navigator.wakeLock?.request('screen')) ?? null;
        } catch {
            // Not allowed (low battery, no permission): fine.
        }
    }

    // A wake lock ends when the page is hidden; take it again on return.
    document.addEventListener('visibilitychange', () => {
        if (busy && document.visibilityState === 'visible') {
            keepAwake();
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (busy) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    function start() {
        busy = true;
        keepAwake();
    }

    function done() {
        busy = false;
        wakeLock?.release().catch(() => {});
        wakeLock = null;
        ui.progress.hidden = true;
        ui.submit.disabled = false;
        ui.submit.removeAttribute('aria-busy');
        form.querySelectorAll('input, select, textarea, button').forEach((el) => el.removeAttribute('inert'));
    }

    /* Preparing files on the device ----------------------------------- */

    async function shrink(file) {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, limits.pixels / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));
        return { canvas, blob: blob ?? file };
    }

    let ocr = null;
    async function readText(canvas, onProgress) {
        try {
            ocr ??= import('tesseract.js').then(({ createWorker }) =>
                createWorker('eng', 1, {
                    workerPath: `${assets}tesseract/worker.min.js`,
                    corePath: `${assets}tesseract/core`,
                    langPath: `${assets}tesseract/lang`,
                    workerBlobURL: false,
                    logger: (message) => {
                        if (message.status === 'recognizing text') {
                            readText.onProgress?.(message.progress);
                        }
                    },
                }),
            );
            const worker = await within(ocr, OCR_PAGE_MS, null);
            if (!worker) {
                throw new Error('OCR took too long to load');
            }
            readText.onProgress = onProgress;
            const result = await within(worker.recognize(canvas), OCR_PAGE_MS, null);
            if (!result) {
                throw new Error('OCR took too long');
            }
            return result.data.text.trim();
        } catch {
            ocr?.then((worker) => worker.terminate()).catch(() => {});
            // Reading text is a bonus: the upload goes ahead without it.
            ocr = Promise.reject(new Error('OCR unavailable'));
            ocr.catch(() => {});
            return '';
        }
    }

    async function preparePdf(data) {
        data.append('pdf', pdfFile);
        progress('Reading the PDF…');

        // Whatever is ready when time runs out is sent; the rest is skipped.
        const deadline = Date.now() + PDF_PREPARE_MS;
        const fields = {};
        await within(readPdf(fields, deadline), PDF_PREPARE_MS, null);
        for (const [name, value] of Object.entries(fields)) {
            if (name === 'cover') {
                data.append('cover', value, 'cover.jpg');
            } else {
                data.append(name, value);
            }
        }
    }

    async function readPdf(fields, deadline) {
        try {
            const { getDocument, GlobalWorkerOptions } = await import('pdfjs-dist/legacy/build/pdf.min.mjs');
            const { default: workerUrl } = await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url');
            GlobalWorkerOptions.workerSrc = workerUrl;

            const pdf = await getDocument({
                data: new Uint8Array(await pdfFile.arrayBuffer()),
                standardFontDataUrl: `${assets}pdfjs/standard_fonts/`,
                cMapUrl: `${assets}pdfjs/cmaps/`,
                wasmUrl: `${assets}pdfjs/wasm/`,
            }).promise;
            fields.page_count = String(pdf.numPages);

            if (!ui.cover.files.length) {
                const page = await pdf.getPage(1);
                const base = page.getViewport({ scale: 1 });
                const viewport = page.getViewport({ scale: 600 / base.width });
                const canvas = document.createElement('canvas');
                canvas.width = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                await page.render({ canvas, viewport }).promise;
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
                if (blob) {
                    fields.cover = blob;
                }
            }

            let text = '';
            for (let number = 1; number <= pdf.numPages && text.length < TEXT_MAX_CHARS && Date.now() < deadline; number++) {
                progress(`Reading the PDF: page ${number} of ${pdf.numPages}`, number / pdf.numPages);
                const content = await (await pdf.getPage(number)).getTextContent();
                text += content.items.map((item) => item.str ?? '').join(' ') + '\n\n';
                fields.text = text.slice(0, TEXT_MAX_CHARS);
            }
            await pdf.destroy();
        } catch {
            // Damaged or unusual PDFs still upload; the server checks them.
        }
    }

    async function preparePhotos(data) {
        const total = photos.length;
        const deadline = Date.now() + OCR_TOTAL_MS;
        let reading = !serverReads && !slowNetwork();

        for (const [index, photo] of photos.entries()) {
            const label = `page ${index + 1} of ${total}`;
            progress(`Preparing ${label}…`, index / total);
            const { canvas, blob } = await shrink(photo.file);
            data.append('pages[]', blob, `page-${index + 1}.jpg`);

            reading &&= Date.now() < deadline;
            if (reading) {
                progress(`Reading the text on ${label}…`, index / total);
                const text = await readText(canvas, (fraction) =>
                    progress(`Reading the text on ${label}…`, (index + fraction) / total),
                );
                data.append(`page_text[${index}]`, text);
                // Unavailable or too slow on this phone: send the rest as is.
                reading = ocr !== null && !(await ocr.then(() => false, () => true));
            }
            canvas.width = canvas.height = 0;
        }
    }

    /* Sending --------------------------------------------------------- */

    function sendOnce(data) {
        return new Promise((resolve) => {
            const request = new XMLHttpRequest();
            request.open('POST', form.action);
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    progress('Uploading…', event.loaded / event.total);
                }
            });
            request.addEventListener('load', () => resolve(request));
            request.addEventListener('error', () => resolve(null));
            request.addEventListener('abort', () => resolve(null));
            request.send(data);
        });
    }

    const backOnline = () =>
        new Promise((resolve) => {
            const check = () => {
                if (navigator.onLine !== false && document.visibilityState === 'visible') {
                    document.removeEventListener('visibilitychange', check);
                    window.removeEventListener('online', check);
                    resolve();
                }
            };
            document.addEventListener('visibilitychange', check);
            window.addEventListener('online', check);
            check();
        });

    /**
     * Sends, and tries again after a dropped connection, a server hiccup
     * (5xx) or while an earlier try is still being saved (409). Answers
     * that won't change by trying again (201, 4xx) come straight back.
     */
    async function send(data) {
        let response = null;

        for (let attempt = 1; attempt <= SEND_TRIES; attempt++) {
            if (attempt > 1) {
                progress('The connection dropped. Trying again…', null);
                await backOnline();
                await sleep(response?.status === 409 ? 10_000 : 2_000 * attempt);
                progress('Uploading…', 0);
            }

            response = await sendOnce(data);
            const retry = response === null || response.status === 0 || response.status === 409 || response.status >= 500;
            if (!retry) {
                return response;
            }
        }

        return response;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (type() === 'pdf' && !pdfFile) {
            showError('pdf', 'Choose a PDF to upload.');
            return;
        }
        if (type() === 'scan' && !photos.length) {
            showError('pages', 'Add at least one photo of a page.');
            return;
        }

        ui.submit.disabled = true;
        ui.submit.setAttribute('aria-busy', 'true');
        start();

        // A new key for each new upload (the form may have come back from
        // the browser's history with the last one's); retries reuse it.
        const key = form.querySelector('input[name="upload_key"]');
        if (key) {
            key.value = newKey();
        }

        // Everything but the file inputs, which are added after preparing.
        const data = new FormData(form);
        ['pdf', 'pages[]', 'cover'].forEach((name) => data.delete(name));
        if (ui.cover.files.length) {
            data.append('cover', ui.cover.files[0]);
        }

        if (type() === 'pdf') {
            await preparePdf(data);
        } else {
            await preparePhotos(data);
        }

        progress('Uploading…', 0);
        const response = await send(data);

        if (response && response.status === 201) {
            progress('Done', 1);
            busy = false;
            window.location.assign(JSON.parse(response.responseText).redirect);
            return;
        }

        done();

        if (response?.status === 422) {
            showErrors(JSON.parse(response.responseText).errors ?? {});
        } else if (response?.status === 413) {
            toast('This upload is too large. Try fewer or smaller pages.');
        } else if (response?.status === 419) {
            toast('Your session expired. Reload the page and try again.');
        } else if (response?.status === 409) {
            toast('Your upload is still being saved. Check Your uploads in a minute.');
        } else if (response?.status === 429) {
            toast('Too many uploads in a short time. Wait a minute and try again.');
        } else {
            toast('The upload didn\'t go through. Check your connection and try again.');
        }
    });
}

/*
 * An upload's status page: while the AI pass reads the pages, check every
 * few seconds and update the count, pausing while the tab is hidden.
 */
export function initUploadStatus() {
    const box = document.querySelector('[data-upload-status]');

    if (!box) {
        return;
    }

    const text = box.querySelector('[data-upload-status-text]');
    const bar = box.querySelector('[data-upload-status-bar]');

    const check = async () => {
        if (document.visibilityState === 'hidden') {
            return setTimeout(check, 5000);
        }

        try {
            const response = await fetch(box.dataset.uploadStatus, { headers: { Accept: 'application/json' } });
            const status = await response.json();

            if (status.status !== 'queued') {
                text.textContent = 'The text of every page is searchable.';
                bar?.remove();
                box.querySelector('p.text-sm')?.remove();
                return;
            }

            text.textContent = `Making the text searchable: ${status.done} of ${status.total} pages`;
            if (bar) {
                bar.value = status.done;
                bar.max = Math.max(1, status.total);
            }
        } catch {
            // Try again on the next round.
        }

        setTimeout(check, 8000);
    };

    setTimeout(check, 5000);
}
