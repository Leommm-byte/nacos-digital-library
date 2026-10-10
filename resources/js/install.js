/*
 * The "Install the app" card (partials/install-banner.blade.php).
 * - Android and desktop Chrome/Edge: the browser offers installing
 *   (beforeinstallprompt); the card's button opens that prompt.
 * - iPhone and iPad: there is no prompt, so the card shows the steps.
 * - Hidden when already installed, after installing, and for a day after
 *   "Not now".
 */
const DISMISS_KEY = 'install-dismissed-at';
const DAY = 24 * 60 * 60 * 1000;

let deferred = null;

export function initInstall() {
    // Listened for on every page: the browser may fire it before the card's
    // page is shown.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        document.querySelectorAll('[data-install]').forEach((card) => show(card, 'prompt'));
    });

    window.addEventListener('appinstalled', () => {
        deferred = null;
        document.querySelectorAll('[data-install]').forEach((card) => (card.hidden = true));
    });

    document.querySelectorAll('[data-install]').forEach((card) => {
        card.querySelector('[data-install-dismiss]').addEventListener('click', () => {
            card.hidden = true;
            try {
                localStorage.setItem(DISMISS_KEY, String(Date.now()));
            } catch {
                // Private browsing: it simply shows again next time.
            }
        });

        card.querySelector('[data-install-button]').addEventListener('click', async () => {
            if (!deferred) {
                return;
            }
            deferred.prompt();
            const { outcome } = await deferred.userChoice;
            deferred = null;
            if (outcome === 'accepted') {
                card.hidden = true;
            }
        });

        if (isIos()) {
            show(card, 'ios');
        }
    });
}

function show(card, mode) {
    if (installed() || dismissedRecently()) {
        return;
    }
    card.querySelector('[data-install-button]').hidden = mode !== 'prompt';
    card.querySelector('[data-install-text]').hidden = mode === 'ios';
    card.querySelector('[data-install-ios]').hidden = mode !== 'ios';
    card.hidden = false;
}

function installed() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

function dismissedRecently() {
    try {
        return Date.now() - Number(localStorage.getItem(DISMISS_KEY) || 0) < DAY;
    } catch {
        return false;
    }
}

// iPhone, iPod, or an iPad (which reports itself as a Mac with touch).
function isIos() {
    const ua = window.navigator.userAgent;
    return /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
}
