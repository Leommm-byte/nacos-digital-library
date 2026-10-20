/*
 * A thin bar along the top while the next page loads, so a tap always shows
 * that something is happening (an installed app has no address bar spinner).
 * It appears only if the page takes more than a moment (navigation.css).
 */
export function initNavigationProgress() {
    const bar = document.createElement('div');
    bar.className = 'nav-progress';
    bar.setAttribute('aria-hidden', 'true');
    document.body.append(bar);

    let timer;
    const stop = () => {
        clearTimeout(timer);
        bar.classList.remove('is-loading');
    };
    // A link that only downloads a file never leaves the page.
    const start = () => {
        bar.classList.add('is-loading');
        clearTimeout(timer);
        timer = setTimeout(stop, 20_000);
    };

    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[href]');
        if (
            !link || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || link.hasAttribute('download') || (link.target && link.target !== '_self')
        ) {
            return;
        }

        const url = new URL(link.href, location.href);
        const samePage = url.pathname === location.pathname && url.search === location.search;
        if (url.origin === location.origin && !(samePage && url.hash)) {
            start();
        }
    });

    document.addEventListener('submit', (event) => {
        if (!event.defaultPrevented && !event.target.hasAttribute('data-no-progress')) {
            start();
        }
    });

    // Back on this page from the back-forward cache.
    window.addEventListener('pageshow', stop);
}
