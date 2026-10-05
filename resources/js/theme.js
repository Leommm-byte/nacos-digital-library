/*
 * Theme toggle: system → light → dark → system. The choice is stored in
 * localStorage and applied before first paint by the inline script in
 * resources/views/partials/head.blade.php, so there is no flash.
 */
const ORDER = ['system', 'light', 'dark'];
const LABELS = { system: 'System theme', light: 'Light theme', dark: 'Dark theme' };

function stored() {
    try {
        const value = localStorage.getItem('theme');
        return ORDER.includes(value) ? value : 'system';
    } catch {
        return 'system';
    }
}

function apply(theme) {
    const root = document.documentElement;

    if (theme === 'system') {
        delete root.dataset.theme;
    } else {
        root.dataset.theme = theme;
    }

    try {
        if (theme === 'system') {
            localStorage.removeItem('theme');
        } else {
            localStorage.setItem('theme', theme);
        }
    } catch {
        // Storage blocked (private mode): the choice lasts for this page only.
    }

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.dataset.current = theme;
        button.setAttribute('aria-label', `${LABELS[theme]} (change theme)`);
        button.title = LABELS[theme];
    });
}

export function initTheme() {
    apply(stored());

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-theme-toggle]');

        if (button) {
            const next = ORDER[(ORDER.indexOf(stored()) + 1) % ORDER.length];
            apply(next);
        }
    });
}
