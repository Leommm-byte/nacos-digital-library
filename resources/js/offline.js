/*
 * Offline support, with resources/sw/sw.js:
 * - registers the service worker;
 * - "Keep offline" on a book's page saves its PDF on this phone;
 * - the offline page lists the kept books, and the offline reader opens
 *   them with no connection;
 * - kept books belong to one account: logging out, or another student
 *   signing in on this phone, deletes them.
 */
const BOOKS_CACHE = 'nacos-books';
const INDEX = '/offline/books/';
const OWNER = '/offline/owner';

const supported = 'serviceWorker' in navigator && 'caches' in window && window.isSecureContext;

export function initOffline() {
    if (!supported) {
        return;
    }

    navigator.serviceWorker.register('/sw.js').catch(() => {});

    checkOwner();
    bindLogout();
    document.querySelectorAll('[data-keep-offline]').forEach(setupKeepButton);

    const page = document.querySelector('[data-offline-page]');
    if (page) {
        setupOfflinePage(page);
    }

    const reader = document.querySelector('[data-offline-reader]');
    if (reader) {
        setupOfflineReader(reader);
    }
}

/* Kept books ------------------------------------------------------------ */

async function books() {
    const cache = await caches.open(BOOKS_CACHE);
    const entries = [];
    for (const request of await cache.keys()) {
        if (new URL(request.url).pathname.startsWith(INDEX)) {
            const response = await cache.match(request);
            entries.push(await response.json());
        }
    }
    return entries.sort((a, b) => b.savedAt - a.savedAt);
}

async function find(id) {
    const cache = await caches.open(BOOKS_CACHE);
    const response = await cache.match(INDEX + encodeURIComponent(id));
    return response ? response.json() : null;
}

async function remove(entry) {
    const cache = await caches.open(BOOKS_CACHE);
    await cache.delete(entry.file);
    await cache.delete(INDEX + encodeURIComponent(entry.id));
}

async function forgetAll() {
    await caches.delete(BOOKS_CACHE);
}

// A different account on this phone: the previous student's books go.
async function checkOwner() {
    const user = document.body.dataset.user;
    if (!user || !(await caches.has(BOOKS_CACHE))) {
        return;
    }
    const cache = await caches.open(BOOKS_CACHE);
    const owner = await cache.match(OWNER);
    if (owner && (await owner.text()) !== user) {
        await forgetAll();
    }
}

function bindLogout() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-logout]');
        if (!form || form.dataset.cleared || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        form.dataset.cleared = '1';
        const done = () => form.submit();
        // Never hold up logging out for long.
        Promise.race([forgetAll(), new Promise((resolve) => setTimeout(resolve, 1500))]).then(done, done);
    }, true);
}

/* "Keep offline" button ------------------------------------------------- */

function setupKeepButton(root) {
    const ui = {
        save: root.querySelector('[data-keep-offline-save]'),
        progress: root.querySelector('[data-keep-offline-progress]'),
        percent: root.querySelector('[data-keep-offline-percent]'),
        remove: root.querySelector('[data-keep-offline-remove]'),
        error: root.querySelector('[data-keep-offline-error]'),
    };
    const data = root.dataset;

    const show = (state) => {
        ui.save.hidden = state !== 'idle';
        ui.progress.hidden = state !== 'saving';
        ui.remove.hidden = state !== 'kept';
    };

    root.hidden = false;
    find(data.id).then((entry) => show(entry && entry.file === data.file ? 'kept' : 'idle'));

    ui.save.addEventListener('click', async () => {
        ui.error.hidden = true;
        show('saving');
        try {
            await keep(data, (fraction) => (ui.percent.textContent = `${Math.round(fraction * 100)}%`));
            show('kept');
            ui.remove.focus();
        } catch (error) {
            show('idle');
            ui.error.textContent = error.name === 'QuotaExceededError'
                ? 'There isn\'t enough space on this phone. Remove some kept books or free up space.'
                : 'Couldn\'t save the book. Check your connection and try again.';
            ui.error.hidden = false;
        }
    });

    ui.remove.addEventListener('click', async () => {
        if (!window.confirm('Remove the offline copy of this book from this phone?')) {
            return;
        }
        const entry = await find(data.id);
        if (entry) {
            await remove(entry);
        }
        show('idle');
        ui.save.focus();
    });
}

async function keep(data, onProgress) {
    const response = await fetch(data.file, { credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok || !response.body) {
        throw new Error(String(response.status));
    }

    const total = Number(response.headers.get('Content-Length')) || Number(data.size) || 0;
    const reader = response.body.getReader();
    const parts = [];
    let loaded = 0;
    for (;;) {
        const { done, value } = await reader.read();
        if (done) {
            break;
        }
        parts.push(value);
        loaded += value.length;
        if (total) {
            onProgress(Math.min(1, loaded / total));
        }
    }
    const blob = new Blob(parts, { type: 'application/pdf' });

    const cache = await caches.open(BOOKS_CACHE);
    // Someone else's books never stay alongside these.
    const owner = await cache.match(OWNER);
    if (owner && (await owner.text()) !== data.owner) {
        await forgetAll();
    }

    const books = await caches.open(BOOKS_CACHE);
    // An older version of the same book is replaced.
    const old = await find(data.id);
    if (old && old.file !== data.file) {
        await books.delete(old.file);
    }

    await books.put(data.file, new Response(blob, {
        headers: { 'Content-Type': 'application/pdf', 'Content-Length': String(blob.size) },
    }));
    await books.put(OWNER, new Response(data.owner));
    await books.put(INDEX + encodeURIComponent(data.id), new Response(JSON.stringify({
        id: data.id,
        file: data.file,
        title: data.title,
        author: data.author,
        level: data.level,
        size: blob.size,
        watermark: data.watermark,
        savedAt: Date.now(),
    }), { headers: { 'Content-Type': 'application/json' } }));

    // The reader itself (PDF.js) has to be on the phone too.
    const registration = await navigator.serviceWorker.ready;
    registration.active?.postMessage({ type: 'cache-reader' });
    // Ask the browser not to clear kept books when space runs low.
    navigator.storage?.persist?.().catch(() => {});
}

/* Offline page ---------------------------------------------------------- */

async function setupOfflinePage(page) {
    page.querySelector('[data-offline-retry]')?.addEventListener('click', () => window.location.reload());
    window.addEventListener('online', () => window.location.reload());

    const list = page.querySelector('[data-offline-books]');
    const empty = page.querySelector('[data-offline-empty]');
    const entries = await books();

    if (!entries.length) {
        return;
    }

    for (const entry of entries) {
        list.append(bookRow(entry, async (row) => {
            if (window.confirm(`Remove “${entry.title}” from this phone?`)) {
                await remove(entry);
                row.remove();
                if (!list.children.length) {
                    list.hidden = true;
                    empty.hidden = false;
                }
            }
        }));
    }
    list.hidden = false;
    empty.hidden = true;
}

function bookRow(entry, onRemove) {
    const row = document.createElement('li');
    row.className = 'offline-book';

    const link = document.createElement('a');
    link.href = `/library/${encodeURIComponent(entry.id)}/read`;
    link.className = 'offline-book-link';
    const title = document.createElement('span');
    title.className = 'offline-book-title';
    title.textContent = entry.title;
    const meta = document.createElement('span');
    meta.className = 'offline-book-meta';
    meta.textContent = [entry.author, entry.level, fileSize(entry.size)].filter(Boolean).join(' · ');
    link.append(title, meta);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-ghost btn-sm';
    button.textContent = 'Remove';
    button.setAttribute('aria-label', `Remove ${entry.title} from this phone`);
    button.addEventListener('click', () => onRemove(row));

    row.append(link, button);
    return row;
}

function fileSize(bytes) {
    return bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`;
}

/* Offline reader -------------------------------------------------------- */

async function setupOfflineReader(root) {
    // Shown in place of /library/<id>/read, or opened as /offline/read?book=<id>.
    const match = window.location.pathname.match(/^\/library\/([^/]+)\/read$/);
    const id = match ? decodeURIComponent(match[1]) : new URLSearchParams(window.location.search).get('book');
    const entry = id ? await find(id) : null;

    if (!entry) {
        window.location.replace('/offline');
        return;
    }

    let page = 1;
    try {
        page = Number(localStorage.getItem(`reader-page:${entry.id}`)) || 1;
    } catch {
        // Private browsing: start at the first page.
    }

    document.title = `${entry.title} · Reading offline`;
    root.querySelector('[data-offline-title]').textContent = entry.title;
    root.querySelector('[data-offline-author]').textContent = entry.author;
    root.dataset.src = entry.file;
    root.dataset.watermark = entry.watermark ?? '';
    root.dataset.book = entry.id;
    root.dataset.startPage = String(page);
    root.setAttribute('data-reader', '');

    const { initReader } = await import('./reader');
    initReader();
}
