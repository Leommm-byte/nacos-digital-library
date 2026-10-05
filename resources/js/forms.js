/*
 * Small progressive enhancements for forms. Everything works without them.
 *  - [data-password-toggle="<input id>"]: show/hide a password.
 *  - [data-password-rules="<input id>"]: tick off password rules while typing
 *    (the same rules the server enforces).
 */
// Mirrors Laravel's Password::min(8)->mixedCase()->numbers()->symbols().
const RULES = {
    length: (v) => [...v].length >= 8,
    case: (v) => /\p{Ll}/u.test(v) && /\p{Lu}/u.test(v),
    number: (v) => /\p{N}/u.test(v),
    symbol: (v) => /[\p{Z}\p{S}\p{P}]/u.test(v),
};

export function initForms() {
    // Show that a form is being sent and stop double submissions. Forms
    // handled in place (bookmarks) manage their own state.
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (event.defaultPrevented || form.matches('[data-bookmark], [method="GET" i]')) {
            return;
        }

        const button = event.submitter ?? form.querySelector('button[type="submit"]');
        if (button) {
            // After the browser has read the form, so the button's own value is sent.
            window.setTimeout(() => {
                button.setAttribute('aria-busy', 'true');
                button.disabled = true;
            }, 0);
        }
    });

    // Coming back with the browser's Back button restores the page as it
    // was, including disabled buttons; undo that.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            document.querySelectorAll('button[aria-busy="true"]').forEach((button) => {
                button.removeAttribute('aria-busy');
                button.disabled = false;
            });
        }
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-password-toggle]');
        const input = button && document.getElementById(button.dataset.passwordToggle);

        if (!input) {
            return;
        }

        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });

    document.querySelectorAll('[data-password-rules]').forEach((list) => {
        const input = document.getElementById(list.dataset.passwordRules);

        if (!input) {
            return;
        }

        const update = () => {
            list.querySelectorAll('[data-rule]').forEach((item) => {
                const check = RULES[item.dataset.rule];
                item.classList.toggle('is-met', Boolean(check && check(input.value)));
            });
        };

        input.addEventListener('input', update);
        update();
    });
}
