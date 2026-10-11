/*
 * File fields (components/file-input.blade.php). The browser's own control
 * prints the whole file name, which pushes the page sideways on a phone,
 * and has no way to take a choice back. This shows a button instead and,
 * once a file is chosen, its name (shortened to fit), its size, a remove
 * button and, for a photo, a thumbnail. The real input stays in the form,
 * focusable, and is what's sent.
 *
 * Removing clears the input and fires "change", so other scripts (the
 * upload form's cover, for one) see the field empty again.
 */
export function formatSize(bytes) {
    return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export function initFileInputs(root = document) {
    for (const field of root.querySelectorAll('[data-file-input]:not(.is-enhanced)')) {
        const input = field.querySelector('input[type="file"]');
        const chosen = field.querySelector('[data-file-input-chosen]');
        const name = field.querySelector('[data-file-input-name]');
        const size = field.querySelector('[data-file-input-size]');
        const clear = field.querySelector('[data-file-input-clear]');
        const icon = field.querySelector('[data-file-input-icon]');
        const thumb = field.querySelector('[data-file-input-thumb]');
        let url = null;

        const render = () => {
            const file = input.files?.[0];
            field.classList.toggle('has-file', Boolean(file));
            chosen.hidden = !file;
            name.textContent = file?.name ?? '';
            name.title = file?.name ?? '';
            size.textContent = file ? formatSize(file.size) : '';
            clear.setAttribute('aria-label', file ? `Remove ${file.name}` : 'Remove the chosen file');

            if (url) {
                URL.revokeObjectURL(url);
                url = null;
            }
            const photo = Boolean(file && thumb && /^image\/(jpeg|png|webp|gif)$/.test(file.type));
            if (photo) {
                url = URL.createObjectURL(file);
                thumb.src = url;
            } else if (thumb) {
                thumb.removeAttribute('src');
            }
            if (thumb) {
                thumb.hidden = !photo;
            }
            icon?.classList.toggle('hidden', photo);
        };

        for (const pick of field.querySelectorAll('[data-file-input-pick]')) {
            pick.addEventListener('click', () => input.click());
        }
        input.addEventListener('change', render);
        clear.addEventListener('click', () => {
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        });

        field.classList.add('is-enhanced');
        // A file kept by the browser after going back to the page.
        render();
    }
}
