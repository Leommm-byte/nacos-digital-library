/*
 * Brief on-screen confirmation, also read out by screen readers through the
 * polite live region in the layout.
 */
let timer;

export function toast(message) {
    const el = document.getElementById('toast');
    const live = document.getElementById('live-region');

    if (live) {
        live.textContent = '';
        // A new text node makes screen readers announce repeats too.
        window.setTimeout(() => {
            live.textContent = message;
        }, 50);
    }

    if (!el) {
        return;
    }

    el.textContent = message;
    el.classList.add('is-visible');
    window.clearTimeout(timer);
    timer = window.setTimeout(() => el.classList.remove('is-visible'), 2600);
}
