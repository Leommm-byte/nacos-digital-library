/*
 * The assistant chat (resources/views/assistant). On signed-in pages the
 * floating button opens a panel; on /assistant the chat is inline. Messages
 * go to the server as CSRF-protected POSTs; answers come back as text
 * blocks and links, drawn here with the DOM (never innerHTML), so an
 * answer can't inject markup.
 */

export function initAssistant() {
    document.querySelectorAll('[data-assistant]').forEach(setup);
}

function setup(root) {
    const inline = root.hasAttribute('data-inline');
    const panel = root.querySelector('.assistant-panel');
    const opener = root.querySelector('[data-assistant-open]');
    const log = root.querySelector('[data-assistant-log]');
    const form = root.querySelector('[data-assistant-form]');
    const input = form.querySelector('textarea');
    const status = root.querySelector('[data-assistant-status]');
    const introChips = root.querySelector('[data-assistant-intro] .assistant-chips');
    let loaded = inline;
    let busy = false;

    // The suggestion buttons under the greeting are small forms (for the
    // no-JS page); with JS they ask straight away.
    root.addEventListener('submit', (event) => {
        const chip = event.target.closest('.assistant-chips form');
        if (chip && !event.defaultPrevented) {
            event.preventDefault();
            ask(new FormData(chip).get('message'));
        }
    });

    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        ask(input.value);
    });

    // Enter sends; Shift+Enter starts a new line.
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });
    input.addEventListener('input', () => grow(input));

    const clear = root.querySelector('[data-assistant-clear]');
    clear?.addEventListener('submit', async (event) => {
        if (event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        try {
            await send(clear.action, new FormData(clear));
            log.querySelectorAll('.assistant-msg:not([data-assistant-intro])').forEach((el) => el.remove());
            introChips.hidden = false;
            input.focus();
        } catch {
            clear.submit();
        }
    });

    if (!inline && opener && panel) {
        opener.addEventListener('click', (event) => {
            if (event.metaKey || event.ctrlKey || event.shiftKey) {
                return;
            }
            event.preventDefault();
            panel.hidden ? open() : close();
        });
        root.querySelector('[data-assistant-close]')?.addEventListener('click', close);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                close();
            }
        });
    }

    if (inline) {
        scrollDown(false);
    }

    function open() {
        panel.hidden = false;
        opener.setAttribute('aria-expanded', 'true');
        root.classList.add('is-open');
        input.focus();
        if (!loaded) {
            load();
        }
        scrollDown(false);
    }

    function close() {
        panel.hidden = true;
        opener.setAttribute('aria-expanded', 'false');
        root.classList.remove('is-open');
        opener.focus();
    }

    async function load() {
        loaded = true;
        try {
            const response = await fetch(root.dataset.url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const data = await response.json();
            data.messages.forEach((message) => log.append(draw(message)));
            introChips.hidden = data.messages.length > 0;
            updateStatus(data.ai, data.remaining);
            scrollDown(false);
        } catch {
            loaded = false;
        }
    }

    async function ask(text) {
        text = String(text ?? '').trim();
        if (text === '' || busy) {
            if (text === '') input.focus();
            return;
        }

        busy = true;
        form.querySelector('button').setAttribute('aria-busy', 'true');
        log.setAttribute('aria-busy', 'true');
        removeChips();

        const pending = draw({ role: 'user', blocks: paragraph(text), links: [] });
        pending.classList.add('is-sending');
        log.append(pending);
        const typing = typingIndicator();
        log.append(typing);
        input.value = '';
        grow(input);
        scrollDown(true);

        const body = new FormData(form);
        body.set('message', text);

        try {
            const data = await send(form.action, body);
            pending.remove();
            data.messages.forEach((message) => log.append(draw(message)));
            updateStatus(true, data.remaining);
        } catch (error) {
            pending.classList.remove('is-sending');
            input.value = text;
            log.append(draw({
                role: 'assistant',
                blocks: paragraph(error.message === '429'
                    ? 'You\'re sending questions very fast. Wait a minute and try again.'
                    : error.message === '422'
                        ? 'That question is too long. Keep it under 1,000 characters.'
                        : 'Sorry, I couldn\'t answer just now. Check your connection and try again.'),
                links: [],
                error: true,
            }));
        } finally {
            typing.remove();
            busy = false;
            form.querySelector('button').removeAttribute('aria-busy');
            log.setAttribute('aria-busy', 'false');
            scrollDown(true);
        }
    }

    function updateStatus(ai, remaining) {
        if (!status) {
            return;
        }
        if (remaining === null || remaining === undefined) {
            if (ai === false) {
                status.textContent = 'Quick answers about the library and your account';
            }
            return;
        }
        status.textContent = remaining > 0
            ? `Smart answers on · ${remaining} left today`
            : 'Quick answers until tomorrow';
    }

    // Suggestions only make sense until the next question.
    function removeChips() {
        log.querySelectorAll('.assistant-chips').forEach((chips) => {
            if (chips === introChips) {
                chips.hidden = true;
            } else {
                chips.remove();
            }
        });
    }

    function scrollDown(smooth) {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        log.scrollTo({ top: log.scrollHeight, behavior: smooth && !reduce ? 'smooth' : 'auto' });
    }

    // Suggestions after an answer ask straight away too.
    log.addEventListener('click', (event) => {
        const chip = event.target.closest('button[data-ask]');
        if (chip) {
            ask(chip.dataset.ask);
        }
    });
}

async function send(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        body,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error(String(response.status));
    }
    return response.json();
}

function paragraph(text) {
    return [{ type: 'p', items: [[{ text, bold: false }]] }];
}

/**
 * Builds one message from {role, blocks, links, suggestions}. Mirrors
 * resources/views/assistant/partials/message.blade.php.
 */
function draw(message) {
    const bot = message.role === 'assistant';
    const el = element('div', `assistant-msg ${bot ? 'is-bot' : 'is-user'}${message.error ? ' is-error' : ''}`);
    el.append(element('span', 'sr-only', bot ? 'Assistant:' : 'You:'));

    const bubble = element('div', 'assistant-bubble');
    for (const block of message.blocks ?? []) {
        if (block.type === 'p') {
            block.items.forEach((runs) => bubble.append(line('p', runs)));
        } else if (block.type === 'ul' || block.type === 'ol') {
            const list = document.createElement(block.type);
            block.items.forEach((runs) => list.append(line('li', runs)));
            bubble.append(list);
        }
    }
    el.append(bubble);

    const links = (message.links ?? []).filter((link) => sameOrigin(link.url));
    if (bot && links.length) {
        const list = element('ul', 'assistant-links');
        for (const link of links) {
            const a = document.createElement('a');
            a.href = link.url;
            a.append(element('span', 'assistant-link-label', link.label));
            if (link.note) {
                a.append(element('span', 'assistant-link-note', link.note));
            }
            const item = document.createElement('li');
            item.append(a);
            list.append(item);
        }
        el.append(list);
    }

    if (bot && message.suggestions?.length) {
        const chips = element('div', 'assistant-chips');
        for (const suggestion of message.suggestions) {
            const button = element('button', 'assistant-chip', suggestion);
            button.type = 'button';
            button.dataset.ask = suggestion;
            chips.append(button);
        }
        el.append(chips);
    }

    return el;
}

function line(tag, runs) {
    const el = document.createElement(tag);
    for (const run of runs) {
        if (run.bold) {
            el.append(element('strong', '', run.text));
        } else {
            el.append(document.createTextNode(run.text));
        }
    }
    return el;
}

function typingIndicator() {
    const el = element('div', 'assistant-msg is-bot');
    const bubble = element('div', 'assistant-bubble assistant-typing');
    bubble.append(element('span', 'sr-only', 'The assistant is typing'));
    for (let i = 0; i < 3; i++) {
        bubble.append(element('span', 'assistant-dot'));
    }
    el.append(bubble);
    return el;
}

function element(tag, className, text) {
    const el = document.createElement(tag);
    if (className) {
        el.className = className;
    }
    if (text !== undefined) {
        el.textContent = text;
    }
    return el;
}

function sameOrigin(url) {
    try {
        return new URL(url, window.location.href).origin === window.location.origin;
    } catch {
        return false;
    }
}

// The textarea grows with its text, up to a few lines (CSS sets the cap).
function grow(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = `${textarea.scrollHeight}px`;
}
