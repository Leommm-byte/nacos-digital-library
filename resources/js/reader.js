/*
 * The book reader (library/reader.blade.php), built on a self-hosted
 * PDF.js. The server answers HTTP Range requests and auto-fetching is off,
 * so only the pages being read are downloaded, not the whole book first.
 *
 * - Renders one page at a time, sharp on high-density screens, fitted to
 *   the screen (width on phones, whole page on larger screens).
 * - Turn pages with the buttons, the arrow keys or a swipe; jump to a page;
 *   zoom from 60% to 300%.
 * - Fetches the next page in the background while you read.
 * - Saves your place shortly after each page turn and when you leave, and
 *   reopens the book there.
 * - Draws the reader's matric number over each page.
 *
 * The legacy build of PDF.js is used so older Android phones still work.
 */
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist/legacy/build/pdf.min.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

const MIN_ZOOM = 0.6;
const MAX_ZOOM = 3;
const ZOOM_STEP = 0.2;
// iOS Safari refuses canvases above about 16.7 million pixels.
const MAX_CANVAS_PIXELS = 16_000_000;
const SAVE_DELAY = 1500;
const SWIPE_DISTANCE = 60;

GlobalWorkerOptions.workerSrc = workerUrl;

export async function initReader() {
    const root = document.querySelector('[data-reader]');

    if (!root) {
        return;
    }

    const get = (name) => root.querySelector(`[data-reader-${name}]`);
    const ui = {
        stage: get('stage'),
        page: get('page'),
        loading: get('loading'),
        bar: get('bar'),
        error: get('error'),
        errorText: get('error-text'),
        retry: get('retry'),
        prev: get('prev'),
        next: get('next'),
        jump: get('jump'),
        input: get('input'),
        total: get('total'),
        zoomIn: get('zoom-in'),
        zoomOut: get('zoom-out'),
        zoomReset: get('zoom-reset'),
        status: get('status'),
        controls: root.querySelectorAll('[data-reader-control]'),
    };

    const state = {
        pdf: null,
        page: 1,
        total: 0,
        zoom: 1,
        renderId: 0,
        task: null,
        fetched: new Set(),
        savedPage: null,
        saveTimer: null,
        width: 0,
    };

    ui.retry.addEventListener('click', () => window.location.reload());

    const assets = root.dataset.assets;
    const loadingTask = getDocument({
        url: root.dataset.src,
        rangeChunkSize: 256 * 1024,
        disableAutoFetch: true,
        disableStream: true,
        enableXfa: false,
        standardFontDataUrl: `${assets}standard_fonts/`,
        cMapUrl: `${assets}cmaps/`,
        cMapPacked: true,
        wasmUrl: `${assets}wasm/`,
        iccUrl: `${assets}iccs/`,
    });

    loadingTask.onProgress = ({ loaded, total }) => {
        if (total) {
            ui.bar.style.transform = `scaleX(${Math.min(1, loaded / total)})`;
        }
    };

    try {
        state.pdf = await loadingTask.promise;
    } catch (error) {
        showError(error);
        return;
    }

    state.total = state.pdf.numPages;
    ui.total.textContent = String(state.total);
    ui.input.max = String(state.total);
    ui.controls.forEach((control) => (control.disabled = false));

    const start = Number(root.dataset.startPage) || 1;
    state.page = Math.min(Math.max(1, start), state.total);
    state.savedPage = state.page;

    try {
        await render();
    } catch (error) {
        showError(error);
        return;
    }

    ui.loading.hidden = true;
    ui.page.hidden = false;

    bindControls();

    /* ---------------------------------------------------------------- */

    function showError(error) {
        const missing = error?.name === 'ResponseException' || error?.name === 'MissingPDFException';

        ui.errorText.textContent = missing
            ? 'The file for this book could not be found. Please try again later.'
            : 'Check your connection and try again.';
        ui.loading.hidden = true;
        ui.page.hidden = true;
        ui.error.hidden = false;
    }

    // Fitted to the width on phones (for reading text), to the whole page
    // on larger screens.
    function fitScale(page) {
        const base = page.getViewport({ scale: 1 });
        const styles = getComputedStyle(ui.stage);
        const width = ui.stage.clientWidth - parseFloat(styles.paddingLeft) - parseFloat(styles.paddingRight);
        const height = ui.stage.clientHeight - parseFloat(styles.paddingTop) - parseFloat(styles.paddingBottom);
        const byWidth = width / base.width;

        return window.matchMedia('(min-width: 48rem)').matches ? Math.min(byWidth, height / base.height) : byWidth;
    }

    async function render() {
        const id = ++state.renderId;
        state.task?.cancel();

        const page = await state.pdf.getPage(state.page);

        if (id !== state.renderId) {
            return;
        }

        const viewport = page.getViewport({ scale: fitScale(page) * state.zoom });
        let ratio = Math.min(window.devicePixelRatio || 1, 3);
        if (viewport.width * viewport.height * ratio * ratio > MAX_CANVAS_PIXELS) {
            ratio = Math.sqrt(MAX_CANVAS_PIXELS / (viewport.width * viewport.height));
        }

        // Drawn off-screen and swapped in when finished, so turning a page
        // never flashes a blank canvas.
        const canvas = document.createElement('canvas');
        canvas.className = 'reader-canvas';
        canvas.width = Math.floor(viewport.width * ratio);
        canvas.height = Math.floor(viewport.height * ratio);
        canvas.style.width = `${Math.floor(viewport.width)}px`;
        canvas.style.height = `${Math.floor(viewport.height)}px`;
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', `Page ${state.page} of ${state.total}`);

        state.task = page.render({
            canvas,
            viewport,
            transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : undefined,
        });

        try {
            await state.task.promise;
        } catch (error) {
            if (error?.name === 'RenderingCancelledException') {
                return;
            }
            throw error;
        }

        if (id !== state.renderId) {
            return;
        }

        drawWatermark(canvas, root.dataset.watermark);
        ui.page.replaceChildren(canvas);
        prefetch(state.page + 1);
    }

    // The reader's matric number, repeated diagonally and faintly across
    // the page. A deterrent against sharing screenshots, not a lock.
    function drawWatermark(canvas, text) {
        if (!text) {
            return;
        }

        const ctx = canvas.getContext('2d');
        const size = Math.max(12, Math.round(canvas.width / 30));
        const diagonal = Math.hypot(canvas.width, canvas.height);

        ctx.save();
        ctx.globalAlpha = 0.07;
        ctx.fillStyle = '#0b5a32';
        ctx.font = `600 ${size}px Inter, system-ui, sans-serif`;
        ctx.textBaseline = 'middle';
        ctx.translate(canvas.width / 2, canvas.height / 2);
        ctx.rotate(-Math.PI / 6);

        const step = ctx.measureText(text).width + size * 4;
        let row = 0;
        for (let y = -diagonal / 2; y < diagonal / 2; y += size * 6, row++) {
            for (let x = -diagonal / 2 - (row % 2) * (step / 2); x < diagonal / 2; x += step) {
                ctx.fillText(text, x, y);
            }
        }
        ctx.restore();
    }

    // Downloads the next page's data in the background.
    function prefetch(number) {
        if (number > state.total || state.fetched.has(number)) {
            return;
        }

        state.fetched.add(number);
        state.pdf
            .getPage(number)
            .then((page) => page.getOperatorList())
            .catch(() => state.fetched.delete(number));
    }

    function go(number) {
        const page = Math.min(Math.max(1, number), state.total);

        if (page === state.page) {
            ui.input.value = String(page);
            return;
        }

        state.page = page;
        updateControls();
        ui.stage.scrollTo({ top: 0, left: 0 });
        ui.status.textContent = `Page ${page} of ${state.total}`;
        render().catch(showError);
        scheduleSave();
    }

    function setZoom(zoom) {
        state.zoom = Math.round(Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom)) * 10) / 10;
        updateControls();
        render().catch(showError);
    }

    function updateControls() {
        ui.input.value = String(state.page);
        ui.prev.disabled = state.page <= 1;
        ui.next.disabled = state.page >= state.total;
        ui.zoomOut.disabled = state.zoom <= MIN_ZOOM;
        ui.zoomIn.disabled = state.zoom >= MAX_ZOOM;
        ui.zoomReset.textContent = `${Math.round(state.zoom * 100)}%`;
    }

    /* Saving the reader's place ------------------------------------- */

    function progressData() {
        const data = new FormData();
        data.append('_token', root.dataset.token);
        data.append('page', String(state.page));
        data.append('pages', String(state.total));
        return data;
    }

    function scheduleSave() {
        clearTimeout(state.saveTimer);
        state.saveTimer = setTimeout(save, SAVE_DELAY);
    }

    // The page is also remembered on the phone, so a book kept offline
    // reopens where it was left. The offline reader has no server to tell.
    function remember() {
        try {
            if (root.dataset.book) {
                localStorage.setItem(`reader-page:${root.dataset.book}`, String(state.page));
            }
        } catch {
            // Storage unavailable (private browsing): nothing to do.
        }
    }

    function save() {
        if (state.page === state.savedPage) {
            return;
        }

        state.savedPage = state.page;
        remember();
        if (!root.dataset.progressUrl) {
            return;
        }
        fetch(root.dataset.progressUrl, {
            method: 'POST',
            body: progressData(),
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => (state.savedPage = null));
    }

    // Leaving or switching away: the beacon survives the page closing.
    function saveNow() {
        clearTimeout(state.saveTimer);

        if (state.page === state.savedPage) {
            return;
        }

        state.savedPage = state.page;
        remember();
        if (root.dataset.progressUrl && !navigator.sendBeacon?.(root.dataset.progressUrl, progressData())) {
            save();
        }
    }

    /* Controls ------------------------------------------------------- */

    function bindControls() {
        updateControls();

        ui.prev.addEventListener('click', () => go(state.page - 1));
        ui.next.addEventListener('click', () => go(state.page + 1));
        ui.zoomIn.addEventListener('click', () => setZoom(state.zoom + ZOOM_STEP));
        ui.zoomOut.addEventListener('click', () => setZoom(state.zoom - ZOOM_STEP));
        ui.zoomReset.addEventListener('click', () => setZoom(1));

        ui.jump.addEventListener('submit', (event) => {
            event.preventDefault();
            go(Number(ui.input.value) || state.page);
            ui.input.blur();
        });
        ui.input.addEventListener('focus', () => ui.input.select());
        ui.input.addEventListener('blur', () => (ui.input.value = String(state.page)));

        document.addEventListener('keydown', (event) => {
            if (event.ctrlKey || event.metaKey) {
                // Deterrent only: discourages printing and saving the book.
                if (['p', 's'].includes(event.key.toLowerCase())) {
                    event.preventDefault();
                }
                return;
            }

            if (event.target.closest('input, textarea, select') || event.altKey) {
                return;
            }

            const actions = {
                ArrowRight: () => go(state.page + 1),
                ArrowLeft: () => go(state.page - 1),
                PageDown: () => go(state.page + 1),
                PageUp: () => go(state.page - 1),
                Home: () => go(1),
                End: () => go(state.total),
                '+': () => setZoom(state.zoom + ZOOM_STEP),
                '=': () => setZoom(state.zoom + ZOOM_STEP),
                '-': () => setZoom(state.zoom - ZOOM_STEP),
                0: () => setZoom(1),
            };

            if (actions[event.key]) {
                event.preventDefault();
                actions[event.key]();
            }
        });

        root.addEventListener('contextmenu', (event) => {
            if (event.target.closest('[data-reader-stage]')) {
                event.preventDefault();
            }
        });

        // Swipe left or right to turn the page, when the page isn't zoomed
        // in far enough to need sideways scrolling.
        let touch = null;
        ui.stage.addEventListener(
            'touchstart',
            (event) => {
                touch = event.touches.length === 1 ? { x: event.touches[0].clientX, y: event.touches[0].clientY } : null;
            },
            { passive: true },
        );
        ui.stage.addEventListener(
            'touchend',
            (event) => {
                if (!touch || ui.stage.scrollWidth > ui.stage.clientWidth + 1) {
                    return;
                }

                const dx = event.changedTouches[0].clientX - touch.x;
                const dy = event.changedTouches[0].clientY - touch.y;
                touch = null;

                if (Math.abs(dx) > SWIPE_DISTANCE && Math.abs(dy) < Math.abs(dx) / 2) {
                    go(state.page + (dx < 0 ? 1 : -1));
                }
            },
            { passive: true },
        );

        // Re-fit when the available width changes (rotation, resizing), not
        // when a phone's address bar slides in and out.
        state.width = ui.stage.clientWidth;
        let resizeTimer;
        new ResizeObserver(() => {
            if (Math.abs(ui.stage.clientWidth - state.width) < 2) {
                return;
            }
            state.width = ui.stage.clientWidth;
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => render().catch(showError), 150);
        }).observe(ui.stage);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                saveNow();
            }
        });
        window.addEventListener('pagehide', saveNow);
    }
}
