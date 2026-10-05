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
