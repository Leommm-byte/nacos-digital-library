/*
 * The "Install the app" card (partials/install-banner.blade.php) and pop-up
 * (partials/install-dialog.blade.php).
 * - Android and desktop Chrome/Edge: the browser offers installing
 *   (beforeinstallprompt); the card's button opens that prompt.
 * - iPhone and iPad: there is no prompt, so the card shows the steps.
 * - Hidden when already installed, after installing, and for a day after
 *   "Not now".
 * - The pop-up shows once, on a phone, from the second visit (a visit is a
 *   browser session); closing it in any way hides it for 30 days.
 */
const DISMISS_KEY = 'install-dismissed-at';
const DIALOG_KEY = 'install-dialog-dismissed-at';
const VISITS_KEY = 'install-visits';
const DAY = 24 * 60 * 60 * 1000;
const DIALOG_PAUSE = 30 * DAY;
// Lets the page settle before the pop-up appears.
const DIALOG_DELAY = 1200;

let deferred = null;

export function initInstall() {
    const visits = countVisit();
    const dialog = document.querySelector('[data-install-dialog]');
    if (dialog) {
        initDialog(dialog, visits);
    }

    // Listened for on every page: the browser may fire it before the card's
    // page is shown.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        document.querySelectorAll('[data-install]').forEach((card) => show(card, 'prompt'));
        if (dialog) {
            showDialog(dialog, 'prompt', visits);
        }
    });

    window.addEventListener('appinstalled', () => {
        deferred = null;
        document.querySelectorAll('[data-install]').forEach((card) => (card.hidden = true));
        if (dialog?.open) {
            dialog.close();
        }
    });

    document.querySelectorAll('[data-install]').forEach((card) => {
        card.querySelector('[data-install-dismiss]').addEventListener('click', () => {
            card.hidden = true;
            remember(DISMISS_KEY);
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

function initDialog(dialog, visits) {
    const close = () => {
        remember(DIALOG_KEY);
        dialog.close();
    };
    dialog.querySelectorAll('[data-install-dialog-dismiss], [data-install-dialog-done]').forEach((button) => button.addEventListener('click', close));
    // A tap on the dimmed page around it closes it too.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            close();
        }
    });
    // Escape.
    dialog.addEventListener('cancel', () => remember(DIALOG_KEY));

    dialog.querySelector('[data-install-dialog-install]').addEventListener('click', async () => {
        remember(DIALOG_KEY);
        if (!deferred) {
            dialog.close();
            return;
        }
        deferred.prompt();
        await deferred.userChoice;
        deferred = null;
        dialog.close();
    });

    // Outside production, ?install=ios or ?install=prompt shows it at once
    // (for the screenshots).
    const preview = new URLSearchParams(window.location.search).get('install');
    if (dialog.hasAttribute('data-install-preview') && (preview === 'ios' || preview === 'prompt')) {
        open(dialog, preview);
        return;
    }

    if (isIos()) {
        showDialog(dialog, 'ios', visits);
    }
}

function showDialog(dialog, mode, visits) {
    const phone = window.matchMedia('(pointer: coarse)').matches;
    if (!phone || visits < 2 || installed() || dismissedWithin(DIALOG_KEY, DIALOG_PAUSE)) {
        return;
    }
    window.setTimeout(() => {
        // Not over another pop-up, and not if it was installed meanwhile.
        if (!dialog.open && !installed() && !document.querySelector('dialog[open]')) {
            open(dialog, mode);
        }
    }, DIALOG_DELAY);
}

function open(dialog, mode) {
    dialog.querySelector('[data-install-dialog-ios]').hidden = mode !== 'ios';
    dialog.querySelector('[data-install-dialog-install]').hidden = mode !== 'prompt';
    dialog.querySelector('[data-install-dialog-done]').hidden = mode !== 'ios';
    dialog.showModal();
    // The dialog itself takes focus, so the close button doesn't open with
    // a focus ring; Tab moves through its buttons as usual.
    dialog.focus();
}

// Counts browser sessions, not page loads.
function countVisit() {
    try {
        let visits = Number(localStorage.getItem(VISITS_KEY) || 0);
        if (!sessionStorage.getItem(VISITS_KEY)) {
            sessionStorage.setItem(VISITS_KEY, '1');
            visits += 1;
            localStorage.setItem(VISITS_KEY, String(visits));
        }
        return visits;
    } catch {
        // Private browsing: never pester.
        return 0;
    }
}

function remember(key) {
    try {
        localStorage.setItem(key, String(Date.now()));
    } catch {
        // Private browsing: nothing to remember it in.
    }
}

function dismissedWithin(key, period) {
    try {
        return Date.now() - Number(localStorage.getItem(key) || 0) < period;
    } catch {
        return false;
    }
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
    return dismissedWithin(DISMISS_KEY, DAY);
}

// iPhone, iPod, or an iPad (which reports itself as a Mac with touch).
function isIos() {
    const ua = window.navigator.userAgent;
    return /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
}
