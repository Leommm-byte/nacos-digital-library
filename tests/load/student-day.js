/*
 * Load test: a busy hour on the portal (k6, https://k6.io).
 *
 * - "students": up to VUS students at once (default 150), each signed in,
 *   browsing the home page, library, search, a book, the reader (a page
 *   range of the PDF), saved books, elections with live results, and the
 *   assistant, with a few seconds of reading between clicks.
 * - "voters": during the peak, 300 other students vote in an election,
 *   three arriving every second (under two minutes), the rush before
 *   voting closes.
 *
 * Needs the LoadTestSeeder data. Run against a copy of the site, never the
 * live one:
 *   k6 run -e BASE=http://127.0.0.1 tests/load/student-day.js
 * CI: .github/workflows/load-test.yml.
 */
import http from 'k6/http';
import exec from 'k6/execution';
import { check, sleep } from 'k6';

const BASE = (__ENV.BASE || 'http://127.0.0.1').replace(/\/$/, '');
const VUS = Number(__ENV.VUS || 150);
const VOTERS = Number(__ENV.VOTERS || 300);
const PASSWORD = 'Password1!';
const SEARCHES = ['data structures', 'database normalisation', 'computer networks', 'operating system',
    'recursion', 'compiler design', 'past questions', 'software engineering', 'binary tree', 'encryption'];

// p95 limits per kind of request. Generous on purpose: shared hosting is
// slower than this test machine, so failing here means a real problem.
const page = ['p(95)<1500'];
export const options = {
    scenarios: {
        students: {
            executor: 'ramping-vus',
            exec: 'student',
            startVUs: 0,
            stages: [
                { duration: '1m', target: VUS },
                { duration: '3m', target: VUS },
                { duration: '30s', target: 0 },
            ],
            gracefulRampDown: '20s',
        },
        // One student signs in and checks every 15 s that the session
        // holds (it once ended after about two minutes under load).
        session: {
            executor: 'per-vu-iterations',
            exec: 'session',
            vus: 1,
            iterations: 1,
            maxDuration: '4m',
        },
        // Voters arrive steadily, 3 a second, during the peak. (Starting
        // 100 at the same instant mostly measured bcrypt: the password
        // checks filled the CPU for a few seconds.)
        voters: {
            executor: 'constant-arrival-rate',
            exec: 'voter',
            rate: 3,
            timeUnit: '1s',
            duration: `${Math.ceil(VOTERS / 3)}s`,
            preAllocatedVUs: 60,
            maxVUs: 150,
            startTime: '1m30s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
        // Passwords are checked with bcrypt, slow on purpose against
        // guessing, so signing in costs more than a page.
        'http_req_duration{name:login}': ['p(95)<3000'],
        'http_req_duration{name:home}': page,
        'http_req_duration{name:library}': page,
        'http_req_duration{name:search}': ['p(95)<2000'],
        'http_req_duration{name:book}': page,
        'http_req_duration{name:reader}': page,
        'http_req_duration{name:pdf range}': ['p(95)<1000'],
        'http_req_duration{name:saved}': page,
        'http_req_duration{name:elections}': page,
        'http_req_duration{name:election}': page,
        'http_req_duration{name:live results}': ['p(95)<300'],
        'http_req_duration{name:assistant}': ['p(95)<2000'],
        'http_req_duration{name:vote}': ['p(95)<2000'],
    },
    summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
};

// Every failed request is logged (kind, status, address) so a failing
// run says what failed, not just how many.
const http_ = {
    get: (url, params) => logged(http.get(url, params), params),
    post: (url, body, params) => logged(http.post(url, body, params), params),
};

// For diagnosis: the first request that unexpectedly lands on the login
// page, and the one before it.
let previous = 'none';
let loggedOut = false;

function logged(res, params) {
    const name = params?.tags?.name ?? '?';
    if (res.status === 0 || res.status >= 400) {
        console.warn(`FAILED ${name} ${res.status} ${res.error || ''} ${res.url}`);
    }
    if (signedIn && !loggedOut && !name.startsWith('login') && /\/login(\?|$)/.test(res.url)) {
        loggedOut = true;
        console.warn(`SIGNED OUT at ${name} after ${previous} (iteration ${exec.vu.iterationInScenario})`);
    }
    previous = `${name} ${res.status}`;
    return res;
}

const matric = (n) => `F/ND/25/${String(n).padStart(7, '0')}`;
const pick = (list) => list[Math.floor(Math.random() * list.length)];
const think = () => sleep(2 + Math.random() * 3);

function token(response) {
    return response.html().find('input[name=_token]').first().attr('value');
}

function login(n) {
    const form = http_.get(`${BASE}/login`, { tags: { name: 'login page' } });
    const res = http_.post(`${BASE}/login`, {
        _token: token(form),
        matric_number: matric(n),
        password: PASSWORD,
    }, { tags: { name: 'login' } });
    return check(res, { 'signed in': (r) => r.status === 200 && r.body.includes('data-user=') });
}

let signedIn = false;
let csrf = null;

export function student() {
    if (!signedIn) {
        // Browsing students use the first accounts; voters start at 1001.
        signedIn = login(exec.vu.idInTest);
        if (!signedIn) {
            sleep(5);
            return;
        }
    }

    const home = http_.get(`${BASE}/`, { tags: { name: 'home' } });
    check(home, { 'home ok': (r) => r.status === 200 });
    csrf = token(home) || csrf;
    think();

    const library = http_.get(`${BASE}/library?page=${1 + Math.floor(Math.random() * 20)}`, { tags: { name: 'library' } });
    check(library, { 'library ok': (r) => r.status === 200 });
    think();

    const search = http_.get(`${BASE}/library?q=${encodeURIComponent(pick(SEARCHES))}`, { tags: { name: 'search' } });
    check(search, { 'search ok': (r) => r.status === 200 });
    const links = search.html().find('a.book-card-link').toArray().map((a) => a.attr('href'));
    const bookUrl = links.length ? pick(links) : library.html().find('a.book-card-link').first().attr('href');
    think();

    if (bookUrl) {
        const book = http_.get(bookUrl, { tags: { name: 'book' } });
        check(book, { 'book ok': (r) => r.status === 200 });
        think();

        const reader = http_.get(`${bookUrl}/read`, { tags: { name: 'reader' } });
        check(reader, { 'reader ok': (r) => r.status === 200 });
        const src = reader.html().find('[data-reader]').attr('data-src');
        if (src) {
            const range = http_.get(src, { headers: { Range: 'bytes=0-262143' }, tags: { name: 'pdf range' } });
            check(range, { 'pdf range served': (r) => r.status === 206 || r.status === 200 });
        }
        think();
    }

    if (exec.vu.iterationInScenario % 2 === 0) {
        check(http_.get(`${BASE}/saved`, { tags: { name: 'saved' } }), { 'saved ok': (r) => r.status === 200 });
        think();
    }

    const elections = http_.get(`${BASE}/elections`, { tags: { name: 'elections' } });
    check(elections, { 'elections ok': (r) => r.status === 200 });
    const electionUrl = elections.html().find('.election-card a').first().attr('href');
    if (electionUrl) {
        const election = http_.get(electionUrl, { tags: { name: 'election' } });
        check(election, { 'election ok': (r) => r.status === 200 });
        const live = election.html().find('[data-live-results]').attr('data-live-results');
        // A watcher polls the results every 10 seconds for half a minute.
        for (let i = 0; live && i < 3; i++) {
            check(http_.get(live, { tags: { name: 'live results' } }), { 'live results ok': (r) => r.status === 200 });
            sleep(10);
        }
    }

    if (csrf && exec.vu.iterationInScenario % 3 === 0) {
        // The panel posts with the token of the page it's on; it should be
        // the same one the home page had.
        const page = http_.get(`${BASE}/saved`, { tags: { name: 'saved' } });
        const current = token(page);
        if (current !== csrf) {
            const landed = page.url.replace(BASE, '');
            console.warn(`TOKEN CHANGED during iteration ${exec.vu.iterationInScenario} of VU ${exec.vu.idInTest}, saved page landed on ${landed}`);
        }
        const answer = http_.post(`${BASE}/assistant`, { _token: csrf, message: `Find books on ${pick(SEARCHES)}` }, {
            headers: { Accept: 'application/json' },
            tags: { name: 'assistant' },
        });
        if (answer.status === 419) {
            console.warn(`ASSISTANT 419 on iteration ${exec.vu.iterationInScenario} of VU ${exec.vu.idInTest}, token ${current === csrf ? 'unchanged' : 'changed'}`);
        }
        check(answer, { 'assistant answered': (r) => r.status === 200 && r.json('messages.1.role') === 'assistant' });
        think();
    }
}

export function session() {
    const form = http_.get(`${BASE}/login`, { tags: { name: 'login page' } });
    const res = http.post(`${BASE}/login`, { _token: token(form), matric_number: matric(1400), password: PASSWORD },
        { tags: { name: 'login' }, redirects: 0 });
    for (const [name, cookies] of Object.entries(res.cookies)) {
        for (const c of cookies) {
            console.warn(`SESSION cookie ${name}: max_age=${c.max_age} expires=${c.expires} path=${c.path} secure=${c.secure}`);
        }
    }
    http_.get(res.headers.Location || `${BASE}/`, { tags: { name: 'home' } });
    const started = Date.now();
    for (let i = 0; i < 14; i++) {
        sleep(15);
        const page = http_.get(`${BASE}/saved`, { tags: { name: 'saved' } });
        const landed = page.url.replace(BASE, '');
        console.warn(`SESSION after ${Math.round((Date.now() - started) / 1000)}s: ${page.status} ${landed}`);
    }
}

export function voter() {
    // Accounts 1001 onwards, away from the browsing students': one ballot each.
    const n = 1001 + exec.scenario.iterationInTest;
    const jar = http.cookieJar();
    jar.clear(BASE);

    if (!login(n)) {
        return;
    }

    const elections = http_.get(`${BASE}/elections`, { tags: { name: 'elections' } });
    const url = elections.html().find('.election-card a').first().attr('href');
    const ballot = http_.get(url, { tags: { name: 'election' } });
    const form = ballot.html().find('form[data-ballot]');

    if (!check(form, { 'ballot shown': (f) => f.size() === 1 })) {
        return;
    }

    const choices = {};
    form.find('input[type=radio][data-ballot-choice]').toArray().forEach((input) => {
        const name = input.attr('name');
        if (!(name in choices) || Math.random() < 0.4) {
            choices[name] = input.attr('value');
        }
    });

    sleep(5 + Math.random() * 10);

    const res = http_.post(form.attr('action'), { _token: form.find('input[name=_token]').attr('value'), ...choices }, { tags: { name: 'vote' } });
    check(res, { 'vote counted': (r) => r.status === 200 && r.body.includes('Your vote is in') });
}

export function handleSummary(data) {
    const rows = Object.entries(data.metrics)
        .filter(([name]) => name.startsWith('http_req_duration{name:'))
        .map(([name, metric]) => {
            const label = name.slice('http_req_duration{name:'.length, -1);
            const v = metric.values;
            const ok = Object.values(metric.thresholds || {}).every((t) => t.ok);
            return `| ${label} | ${v.med.toFixed(0)} | ${v['p(95)'].toFixed(0)} | ${v.max.toFixed(0)} | ${ok ? 'ok' : '**over**'} |`;
        });
    const failed = data.metrics.http_req_failed.values.rate * 100;
    const checks = data.metrics.checks.values.rate * 100;
    const md = [
        '## Load test',
        '',
        `${data.metrics.http_reqs.values.count} requests (${data.metrics.http_reqs.values.rate.toFixed(1)}/s), `
            + `peak ${data.metrics.vus_max.values.max} simulated students, `
            + `${failed.toFixed(2)}% failed, ${checks.toFixed(2)}% of checks passed.`,
        '',
        '| Request | Median ms | p95 ms | Max ms | Budget |',
        '|---|---:|---:|---:|---|',
        ...rows,
        '',
    ].join('\n');
    return { stdout: md, 'load-test-summary.md': md };
}
