/*
 * Save / unsave books without leaving the page (components/bookmark-button).
 * Falls back to a normal form post if the request fails.
 */
import { toast } from './toast';

function setState(form, saved) {
    const button = form.querySelector('button');
    const method = form.querySelector('input[name="_method"]');

    form.action = saved ? form.dataset.destroyUrl : form.dataset.storeUrl;
    method.value = saved ? 'DELETE' : 'POST';
    button.setAttribute('aria-pressed', String(saved));
    button.setAttribute('aria-label', saved ? button.dataset.labelOn : button.dataset.labelOff);
}

function updateSavedPage(form, saved) {
    const item = form.closest('[data-saved-item]');

    if (!item || saved) {
        return;
    }

    item.remove();

    const count = document.querySelector('[data-saved-count]');
    if (count) {
        count.textContent = String(Math.max(0, Number(count.textContent) - 1));
    }

    if (!document.querySelector('[data-saved-item]')) {
        document.querySelector('[data-saved-empty]')?.classList.remove('hidden');
    }
}

export function initBookmarks() {
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-bookmark]');

        if (!form || !window.fetch) {
            return;
        }

        event.preventDefault();

        const button = form.querySelector('button');
        if (button.getAttribute('aria-busy') === 'true') {
            return;
        }
        button.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const data = await response.json();

            // Keep every button for this book in sync (e.g. card and "related").
            document.querySelectorAll(`form[data-bookmark][data-store-url="${form.dataset.storeUrl}"]`).forEach((other) => {
                setState(other, data.saved);
            });
            updateSavedPage(form, data.saved);
            toast(data.message);
        } catch {
            form.submit();
        } finally {
            button.removeAttribute('aria-busy');
        }
    });
}
