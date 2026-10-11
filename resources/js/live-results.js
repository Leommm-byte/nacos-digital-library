/*
 * Live results: open election pages (and the cards on /elections) update
 * themselves as votes come in, without a refresh.
 *
 * Each election's results are a small static JSON file that the server
 * rewrites after every ballot. Pages poll it with conditional requests:
 * the browser sends If-None-Match and the web server answers 304 while
 * nothing changed, with no PHP or database work. To stay cheap for
 * thousands of open pages on shared hosting:
 *
 * - one request per file, however many places on the page show it;
 * - every few seconds while results are moving, slowing step by step to
 *   the maximum while they aren't, and back to fast on the next change;
 * - paused while the tab is hidden or the phone is offline, with a check
 *   as soon as it's back;
 * - jitter, so pages opened together don't poll in step;
 * - after an error, waiting longer each time (up to two minutes);
 * - slower from the start when the phone asks to save data.
 *
 * Markup: [data-live-results="<file url>"] with data-poll and
 * data-poll-max (seconds), data-ballots, data-zone, and data-result-*
 * hooks for the numbers (see elections/partials/results.blade.php).
 */

const numbers = new Intl.NumberFormat('en');
const percents = new Intl.NumberFormat('en', { maximumFractionDigits: 1 });

const MAX_BACKOFF = 120_000;

export function initLiveResults() {
    const roots = [...document.querySelectorAll('[data-live-results]')];

    if (roots.length === 0 || !window.fetch) {
        return;
    }

    const groups = new Map();
    for (const root of roots) {
        const url = root.dataset.liveResults;
        if (!groups.has(url)) {
            groups.set(url, []);
        }
        groups.get(url).push(root);
    }

    for (const [url, group] of groups) {
        watch(url, group);
    }
}

function watch(url, roots) {
    const first = roots[0];
    const saveData = Boolean(navigator.connection?.saveData);
    const fastest = Math.max(2, Number(first.dataset.poll) || 5) * 1000 * (saveData ? 3 : 1);
    const slowest = Math.max(fastest, (Number(first.dataset.pollMax) || 30) * 1000);

    let ballots = Number(first.dataset.ballots ?? -1);
    let version = null;
    let every = fastest;
    let failures = 0;
    let timer = null;
    let busy = false;
    let finished = false;

    const schedule = (delay) => {
        clearTimeout(timer);
        if (finished || document.hidden) {
            return;
        }
        // Up to a quarter either way, so pages don't poll in step.
        timer = setTimeout(poll, delay * (0.75 + Math.random() / 2));
    };

    const next = () => {
        if (failures > 0) {
            return Math.min(MAX_BACKOFF, fastest * 2 ** failures);
        }
        return every;
    };

    const poll = async () => {
        if (busy || finished || document.hidden) {
            return;
        }
        if (navigator.onLine === false) {
            // Checked again by the "online" listener.
            return;
        }

        busy = true;
        try {
            // With the site's cookies: a tunnel in front of the site (ngrok
            // in development) answers requests without its cookie with a
            // warning page instead of the file.
            const response = await fetch(url, { cache: 'no-cache', credentials: 'same-origin' });
            if (!response.ok || !(response.headers.get('Content-Type') ?? '').includes('json')) {
                throw new Error(String(response.status));
            }

            const data = await response.json();
            failures = 0;

            if (data.final) {
                // Closed: reload once for the final layout (winners).
                finished = true;
                window.location.reload();
                return;
            }

            const changed = data.ballots !== ballots || (version !== null && data.updated_at !== version);
            version = data.updated_at;

            if (changed) {
                ballots = data.ballots;
                every = fastest;
                for (const root of roots) {
                    render(root, data);
                }
            } else {
                // Nothing new: slow down a step, up to the maximum.
                every = Math.min(slowest, Math.round(every * 1.5));
            }
        } catch {
            failures += 1;
        } finally {
            busy = false;
        }

        schedule(next());
    };

    const wake = () => {
        if (document.hidden || finished) {
            return;
        }
        // Back on the page: check now, then poll fast again.
        every = fastest;
        failures = 0;
        poll();
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            wake();
        }
    });
    window.addEventListener('online', wake);
    // Coming back to a page restored from the back/forward cache.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            wake();
        }
    });

    schedule(fastest);
}

function render(root, data) {
    const set = (selector, value, scope = root) => {
        const element = scope.querySelector(selector);
        if (element && element.textContent !== value) {
            element.textContent = value;
            flash(element);
        }
    };

    set('[data-result-ballots]', numbers.format(data.ballots));
    set('[data-result-electorate]', numbers.format(data.electorate));
    set('[data-result-turnout]', percents.format(data.turnout));

    const turnout = root.querySelector('[data-result-turnout-bar]');
    if (turnout) {
        turnout.value = data.turnout;
        turnout.setAttribute('aria-label', `Turnout ${percents.format(data.turnout)}%`);
    }

    const updated = root.querySelector('[data-result-updated]');
    if (updated) {
        updated.setAttribute('datetime', data.updated_at);
        updated.textContent = new Intl.DateTimeFormat('en', {
            hour: 'numeric',
            minute: '2-digit',
            timeZone: root.dataset.zone,
        }).format(new Date(data.updated_at)).toLowerCase();
    }

    for (const position of data.positions ?? []) {
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

// A brief highlight on a number that just changed (see .is-updated).
function flash(element) {
    element.classList.remove('is-updated');
    // Restarts the animation when the same number changes again.
    void element.offsetWidth;
    element.classList.add('is-updated');
    element.addEventListener('animationend', () => element.classList.remove('is-updated'), { once: true });
}
