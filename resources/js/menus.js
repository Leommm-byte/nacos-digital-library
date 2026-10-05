/*
 * Dropdown menus are <details class="menu"> elements, so they open without
 * JavaScript. This adds what <details> lacks: closing on Escape, on an
 * outside click, and when another menu opens.
 */
export function initMenus() {
    const openMenus = () => document.querySelectorAll('details.menu[open]');

    document.addEventListener('click', (event) => {
        openMenus().forEach((menu) => {
            if (!menu.contains(event.target)) {
                menu.open = false;
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        openMenus().forEach((menu) => {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        });
    });
}
