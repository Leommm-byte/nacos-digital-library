/*
 * Election pages: countdowns, the ballot summary, live results (see
 * live-results.js) and candidate photos.
 */
import { initLiveResults } from './live-results';

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

/**
 * Candidate form: shows the chosen photo straight away, cropped like the
 * saved one, with a button to take the choice back, and greys the current
 * one out when "Remove photo" is ticked.
 */
function initPhotoPickers() {
    for (const picker of document.querySelectorAll('[data-photo-picker]')) {
        const input = picker.querySelector('[data-photo-input]');
        const preview = picker.querySelector('[data-photo-preview]');
        const remove = picker.querySelector('[data-photo-remove]');
        const clear = picker.querySelector('[data-photo-clear]');
        const original = preview.innerHTML;
        let url = null;

        input.addEventListener('change', () => {
            if (url) {
                URL.revokeObjectURL(url);
                url = null;
            }
            const file = input.files[0];
            if (clear) {
                clear.hidden = !file;
            }
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

        clear?.addEventListener('click', () => {
            input.value = '';
            input.dispatchEvent(new Event('change'));
            input.focus();
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
