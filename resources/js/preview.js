/*
 * Live preview: an input or select with data-preview="key" updates every
 * [data-preview-text="key"] (its text, or the selected option's label) and
 * [data-preview-initial="key"] (first letter) on the page.
 */
export function initPreview() {
    document.querySelectorAll('[data-preview]').forEach((field) => {
        const key = field.dataset.preview;

        const update = () => {
            const value = field.tagName === 'SELECT' ? (field.selectedOptions[0]?.textContent ?? '') : field.value;
            const text = value.trim();

            if (!text) {
                return;
            }

            document.querySelectorAll(`[data-preview-text="${key}"]`).forEach((el) => {
                el.textContent = text;
            });
            document.querySelectorAll(`[data-preview-initial="${key}"]`).forEach((el) => {
                el.textContent = text.charAt(0).toUpperCase();
            });
        };

        field.addEventListener(field.tagName === 'SELECT' ? 'change' : 'input', update);
    });
}
