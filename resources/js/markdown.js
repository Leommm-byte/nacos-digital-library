/*
 * The announcement editor's toolbar: wraps the selection in Markdown (bold,
 * italics, a heading, lists, a link). Typing Markdown works the same, and
 * without this script the toolbar stays hidden.
 */
const FORMATS = {
    bold: { wrap: '**', placeholder: 'bold text' },
    italic: { wrap: '*', placeholder: 'italic text' },
    heading: { line: () => '## ', placeholder: 'Heading' },
    bullets: { line: () => '- ', placeholder: 'List item' },
    numbers: { line: (index) => `${index + 1}. `, placeholder: 'List item' },
};

export function initMarkdownEditors() {
    for (const editor of document.querySelectorAll('[data-markdown-editor]')) {
        const toolbar = editor.querySelector('[data-editor-toolbar]');
        const textarea = editor.querySelector('textarea');

        if (!toolbar || !textarea) {
            continue;
        }

        toolbar.hidden = false;
        toolbar.addEventListener('click', (event) => {
            const button = event.target.closest('[data-format]');
            if (button) {
                apply(textarea, button.dataset.format);
            }
        });
    }
}

function apply(textarea, format) {
    const { selectionStart: start, selectionEnd: end, value } = textarea;
    const selected = value.slice(start, end);
    let text;
    let caret;

    if (format === 'link') {
        const label = selected || 'link text';
        text = `[${label}](https://)`;
        // Leaves the cursor where the address goes.
        caret = [start + label.length + 3, start + label.length + 11];
    } else if (FORMATS[format].wrap) {
        const { wrap, placeholder } = FORMATS[format];
        const inner = selected || placeholder;
        text = `${wrap}${inner}${wrap}`;
        caret = [start + wrap.length, start + wrap.length + inner.length];
    } else {
        // Line formats apply to every selected line, from its start.
        const { line, placeholder } = FORMATS[format];
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        const lines = (value.slice(lineStart, end) || placeholder).split('\n');
        text = lines.map((content, index) => line(index) + content).join('\n');
        // A list or heading needs a blank line before it.
        const before = value.slice(0, lineStart);
        const gap = before === '' || before.endsWith('\n\n') ? '' : '\n';
        text = gap + text;

        textarea.focus();
        textarea.setRangeText(text, lineStart, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }

    textarea.focus();
    textarea.setRangeText(text, start, end, 'end');
    textarea.setSelectionRange(caret[0], caret[1]);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}
