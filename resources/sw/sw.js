/*
 * Service worker, served at /sw.js by ServiceWorkerController, which fills
 * in the version and the asset lists below.
 *
 * What it keeps, and nothing else:
 * - The app's look (CSS, JS, fonts, icons) and two public pages with no
 *   personal data: /offline and /offline/read.
 * - Books a student chose to keep offline (resources/js/offline.js), with
 *   the reader's assets. They belong to one account and are deleted when
 *   that student logs out or someone else signs in.
 *
 * Signed-in pages, admin pages and API answers are never stored: pages
 * always come from the network, and only when that fails does the offline
 * page (or, for a kept book, the offline reader) appear.
 */
const VERSION = '__VERSION__';
const CORE = __CORE__;
const READER = __READER__;

const STATIC_CACHE = `nacos-static-${VERSION}`;
const BOOKS_CACHE = 'nacos-books';
const BOOK_INDEX = '/offline/books/';

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC_CACHE);
        await cache.addAll(CORE.map(guest));
        // Kept books must still open offline after an update.
        if (await hasBooks()) {
            await cache.addAll(READER.map(guest)).catch(() => {});
        }
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const name of await caches.keys()) {
            if (name.startsWith('nacos-static-') && name !== STATIC_CACHE) {
                await caches.delete(name);
            }
        }
        await self.clients.claim();
    })());
});

// The page asks for the reader's assets when a book is kept offline.
self.addEventListener('message', (event) => {
    if (event.data?.type === 'cache-reader') {
        event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.addAll(READER.map(guest))).catch(() => {}));
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigate(request, url));
        return;
    }

    if (/^\/library\/[^/]+\/file$/.test(url.pathname)) {
        event.respondWith(bookFile(request));
        return;
    }

    // Built assets have content hashes in their names; the scan reader's
    // language data is large and only for uploads, so it isn't kept.
    if ((url.pathname.startsWith('/build/') && !url.pathname.startsWith('/build/tesseract/'))
        || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/images/')) {
        event.respondWith(cacheFirst(request));
    }
});

// Kept pages are fetched without cookies, so they are the signed-out
// version: nothing about the student can end up in them.
function guest(url) {
    return new Request(url, { credentials: 'omit', redirect: 'error' });
}

async function navigate(request, url) {
    try {
        return await fetch(request);
    } catch (error) {
        const cache = await caches.open(STATIC_CACHE);
        const read = url.pathname.match(/^\/library\/([^/]+)\/read$/);

        if (read && await bookIndex(read[1])) {
            const shell = await cache.match('/offline/read');
            if (shell) {
                return shell;
            }
        }

        return (await cache.match('/offline')) ?? Response.error();
    }
}

async function cacheFirst(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request, { ignoreSearch: false });
    if (cached) {
        return cached;
    }

    const response = await fetch(request);
    if (response.ok && response.type === 'basic') {
        cache.put(request, response.clone());
    }
    return response;
}

/**
 * A kept book is read from the phone, online or not, which saves data.
 * PDF.js asks for byte ranges, so ranges are cut from the stored copy.
 */
async function bookFile(request) {
    const cache = await caches.open(BOOKS_CACHE);
    const cached = await cache.match(request.url);

    if (!cached) {
        return fetch(request);
    }

    const blob = await cached.blob();
    const range = request.headers.get('Range')?.match(/^bytes=(\d+)-(\d*)$/);
    const headers = {
        'Content-Type': 'application/pdf',
        'Accept-Ranges': 'bytes',
        'Cache-Control': 'no-store',
    };

    if (!range) {
        return new Response(blob, { status: 200, headers: { ...headers, 'Content-Length': String(blob.size) } });
    }

    const start = Number(range[1]);
    const end = Math.min(range[2] === '' ? blob.size - 1 : Number(range[2]), blob.size - 1);

    if (start >= blob.size || start > end) {
        return new Response(null, { status: 416, headers: { 'Content-Range': `bytes */${blob.size}` } });
    }

    return new Response(blob.slice(start, end + 1), {
        status: 206,
        headers: {
            ...headers,
            'Content-Length': String(end - start + 1),
            'Content-Range': `bytes ${start}-${end}/${blob.size}`,
        },
    });
}

async function bookIndex(id) {
    const cache = await caches.open(BOOKS_CACHE);
    return cache.match(`${BOOK_INDEX}${encodeURIComponent(id)}`);
}

async function hasBooks() {
    if (!(await caches.has(BOOKS_CACHE))) {
        return false;
    }
    const cache = await caches.open(BOOKS_CACHE);
    return (await cache.keys()).length > 0;
}
