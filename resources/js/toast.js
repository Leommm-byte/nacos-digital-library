/*
 * Brief confirmation at the bottom of the screen. The element is a polite
 * live region, so screen readers announce it too. A message flashed by
 * the server is rendered already visible and hidden after a few seconds.
 */
const DURATION = 4000;
let timer;

function hideLater(el) {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => el.classList.remove('is-visible'), DURATION);
}

export function toast(message) {
    const el = document.getElementById('toast');

    if (!el) {
        return;
    }

    el.textContent = message;
    el.classList.add('is-visible');
    hideLater(el);
}

export function initToast() {
    const el = document.getElementById('toast');

    if (el?.classList.contains('is-visible')) {
        hideLater(el);
    }
}
