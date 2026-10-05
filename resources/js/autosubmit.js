/*
 * Forms marked data-autosubmit (library filters) apply as soon as a select
 * changes, so there's no separate button to press.
 */
export function initAutosubmit() {
    document.addEventListener('change', (event) => {
        const form = event.target.closest('form[data-autosubmit]');

        if (form && event.target.tagName === 'SELECT') {
            form.requestSubmit();
        }
    });
}
