/*
 * Election pages: countdowns, the ballot summary and live results.
 *
 * Live results are a static JSON file. Polling uses conditional requests
 * (the browser sends If-None-Match, the server answers 304 when nothing
 * changed), pauses while the tab is hidden and adds jitter so thousands of
 * open pages don't poll in step.
 */

const numbers = new Intl.NumberFormat('en');
const percents = new Intl.NumberFormat('en', { maximumFractionDigits: 1 });

function plural(count, word) {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

function formatLeft(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const days = Math.floor(total / 86400);
    const pad = (n) => String(n).padStart(2, '0');
    const clock = `${pad(Math.floor((total % 86400) / 3600))}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`;

    return `${days > 0 ? `${plural(days, 'day')} ` : ''}${clock} left`;
}

function initCountdowns() {
    const timers = [...document.querySelectorAll('[data-countdown]')];

    if (timers.length === 0) {
        return;
    }

    // Measured against the server's clock, not the phone's.
    const offset = Number(timers[0].dataset.now) - Date.now();
    let reloading = false;

    const tick = () => {
        const now = Date.now() + offset;

        for (const timer of timers) {
            const left = Date.parse(timer.getAttribute('datetime')) - now;
            const text = timer.querySelector('[data-countdown-text]');

            if (left > 0) {
                text.textContent = formatLeft(left);
                continue;
            }

            text.textContent = 'Voting has ended';

            if (timer.hasAttribute('data-reload') && !reloading) {
                // Give the server a moment, then show the closed state.
                reloading = true;
                setTimeout(() => window.location.reload(), 3000 + Math.random() * 4000);
            }
        }
    };

    tick();
    setInterval(tick, 1000);
}

function initBallot() {
    const form = document.querySelector('[data-ballot]');

    if (!form) {
        return;
    }

    const summary = form.querySelector('[data-ballot-summary]');
    const positions = form.querySelectorAll('.ballot-position').length;

    const update = () => {
        const chosen = form.querySelectorAll('[data-ballot-choice]:checked').length;
        summary.textContent = chosen === 0
            ? `Choose a candidate for at least one of the ${plural(positions, 'position')}.`
            : `You've chosen ${chosen} of ${plural(positions, 'position')}.`;
    };

    form.addEventListener('change', update);
    update();
}

function render(root, data) {
    const set = (selector, value, scope = root) => {
        const element = scope.querySelector(selector);
        if (element) {
            element.textContent = value;
        }
    };

    set('[data-result-ballots]', numbers.format(data.ballots));
    set('[data-result-electorate]', numbers.format(data.electorate));
    set('[data-result-turnout]', percents.format(data.turnout));
    root.querySelector('[data-result-turnout-bar]')?.setAttribute('value', String(data.turnout));

    const updated = root.querySelector('[data-result-updated]');
    if (updated) {
        updated.setAttribute('datetime', data.updated_at);
        updated.textContent = new Intl.DateTimeFormat('en', {
            hour: 'numeric',
            minute: '2-digit',
            timeZone: root.dataset.zone,
        }).format(new Date(data.updated_at)).toLowerCase();
    }

    for (const position of data.positions) {
        set(`[data-result-position-votes="${position.id}"]`, numbers.format(position.votes));
        set(`[data-result-skipped="${position.id}"]`, numbers.format(position.skipped));

        for (const candidate of position.candidates) {
            const row = root.querySelector(`[data-result-candidate="${candidate.id}"]`);
            if (!row) {
                continue;
            }

            set('[data-result-votes]', numbers.format(candidate.votes), row);
            set('[data-result-percent]', percents.format(candidate.percent), row);

            const bar = row.querySelector('[data-result-bar]');
            bar.value = candidate.percent;
            bar.setAttribute('aria-label', `${candidate.name}: ${percents.format(candidate.percent)}%`);

            const label = candidate.tied ? 'Tied' : candidate.leading ? 'Leading' : '';
            const badge = row.querySelector('[data-result-badge]');
            badge.hidden = label === '';
            set('[data-result-badge-text]', label, row);
            row.classList.toggle('is-leading', candidate.leading);
        }
    }
}

function initLiveResults() {
    const root = document.querySelector('[data-live-results]');

    if (!root || !window.fetch) {
        return;
    }

    const url = root.dataset.liveResults;
    const every = Number(root.dataset.poll || 15) * 1000;
    let ballots = Number(root.dataset.ballots || 0);
    let timer = null;
    let failures = 0;

    const schedule = () => {
        clearTimeout(timer);
        // Up to a third extra, and longer after errors.
        const delay = every * (1 + Math.random() / 3) * Math.min(8, 2 ** failures);
        timer = setTimeout(poll, delay);
    };

    const poll = async () => {
        if (document.hidden) {
            return;
        }

        try {
            const response = await fetch(url, { cache: 'no-cache', credentials: 'omit' });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const data = await response.json();
            failures = 0;

            if (data.final) {
                // Closed: reload once for the final layout (winners).
                window.location.reload();
                return;
            }

            if (data.ballots !== ballots) {
                ballots = data.ballots;
                render(root, data);
            }
        } catch {
            failures += 1;
        }

        schedule();
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            poll();
        }
    });

    schedule();
}

/**
 * Candidate form: shows the chosen photo straight away, cropped like the
 * saved one, and greys the current one out when "Remove photo" is ticked.
 */
function initPhotoPickers() {
    for (const picker of document.querySelectorAll('[data-photo-picker]')) {
        const input = picker.querySelector('[data-photo-input]');
        const preview = picker.querySelector('[data-photo-preview]');
        const remove = picker.querySelector('[data-photo-remove]');
        const original = preview.innerHTML;
        let url = null;

        input.addEventListener('change', () => {
            if (url) {
                URL.revokeObjectURL(url);
                url = null;
            }
            const file = input.files[0];
            if (!file) {
                preview.innerHTML = original;
                return;
            }
            url = URL.createObjectURL(file);
            const frame = document.createElement('span');
            frame.className = 'candidate-avatar candidate-avatar-xl has-photo';
            const img = document.createElement('img');
            img.alt = '';
            img.src = url;
            frame.append(img);
            preview.replaceChildren(frame);
            if (remove) {
                remove.checked = false;
                preview.classList.remove('is-removed');
            }
        });

        remove?.addEventListener('change', () => {
            preview.classList.toggle('is-removed', remove.checked);
        });
    }
}

export function initElections() {
    initCountdowns();
    initBallot();
    initLiveResults();
    initPhotoPickers();
}
