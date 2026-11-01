/*
 * Load test: election day in a department's space (k6, https://k6.io;
 * docs/department-elections.md).
 *
 * - "voters": VOTERS registered students (default 600) sign in at
 *   /vote/load-dept and vote, four arriving every second (two and a half
 *   minutes), as before voting closes.
 * - "registrations": REGISTRATIONS students on the roll (default 100)
 *   register during the rush, one a second.
 * - "watchers": WATCHERS people (default 300), signed out, keep the
 *   results open: the election page once, then the results file every
 *   5 seconds, as live-results.js does.
 *
 * Every request comes from one address, as on campus Wi-Fi.
 *
 * Needs the LoadTestSeeder data. Run against a copy of the site, never the
 * live one:
 *   k6 run -e BASE=http://127.0.0.1 tests/load/department-rush.js
 * CI: .github/workflows/load-test.yml, after the student day.
 */
import http from 'k6/http';
import exec from 'k6/execution';
import { check, sleep } from 'k6';

const BASE = (__ENV.BASE || 'http://127.0.0.1').replace(/\/$/, '');
const SPACE = `${BASE}/vote/load-dept`;
const VOTERS = Number(__ENV.VOTERS || 600);
const REGISTRATIONS = Number(__ENV.REGISTRATIONS || 100);
const WATCHERS = Number(__ENV.WATCHERS || 300);
const PASSWORD = 'Password1!';
const page = ['p(95)<1500'];

export const options = {
    scenarios: {
        voters: {
            executor: 'constant-arrival-rate',
            exec: 'voter',
            rate: 4,
            timeUnit: '1s',
            duration: `${Math.ceil(VOTERS / 4)}s`,
            preAllocatedVUs: 80,
            maxVUs: 200,
            startTime: '20s',
        },
        registrations: {
            executor: 'constant-arrival-rate',
            exec: 'registration',
            rate: 1,
            timeUnit: '1s',
            duration: `${REGISTRATIONS}s`,
            preAllocatedVUs: 10,
            maxVUs: 40,
            startTime: '20s',
        },
        watchers: {
            executor: 'ramping-vus',
            exec: 'watcher',
            startVUs: 0,
            stages: [
                { duration: '20s', target: WATCHERS },
                { duration: `${Math.ceil(VOTERS / 4) + 20}s`, target: WATCHERS },
                { duration: '10s', target: 0 },
            ],
            gracefulRampDown: '10s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
        // bcrypt makes signing in and registering cost more than a page.
        'http_req_duration{name:dept login}': ['p(95)<3000'],
        'http_req_duration{name:dept register}': ['p(95)<3000'],
        'http_req_duration{name:dept home}': page,
        'http_req_duration{name:dept election}': page,
        'http_req_duration{name:dept results page}': page,
        'http_req_duration{name:dept live results}': ['p(95)<300'],
        'http_req_duration{name:dept vote}': ['p(95)<2000'],
    },
    summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
    noCookiesReset: true,
};

function logged(res, params) {
    if (res.status === 0 || res.status >= 400) {
        console.warn(`FAILED ${params?.tags?.name ?? '?'} ${res.status} ${res.error || ''} ${res.url}`);
    }
    return res;
}

const get = (url, name) => logged(http.get(url, { tags: { name } }), { tags: { name } });
const post = (url, body, name) => logged(http.post(url, body, { tags: { name } }), { tags: { name } });
const matric = (n) => `F/ND/24/3${String(n).padStart(6, '0')}`;
const token = (res) => res.html().find('input[name=_token]').first().attr('value');

export function voter() {
    // Registered voters 1 to VOTERS, one ballot each.
    const n = 1 + exec.scenario.iterationInTest;
    http.cookieJar().clear(BASE);

    const form = get(`${SPACE}/login`, 'dept login page');
    const res = post(`${SPACE}/login`, { _token: token(form), login: matric(n), password: PASSWORD }, 'dept login');
    if (!check(res, { 'dept signed in': (r) => r.status === 200 && r.body.includes('Log out') })) {
        return;
    }

    const url = res.html().find('.election-card a.stretched-link').first().attr('href');
    const ballot = get(url, 'dept election');
    const ballotForm = ballot.html().find('form[data-ballot]');
    if (!check(ballotForm, { 'dept ballot shown': (f) => f.size() === 1 })) {
        return;
    }

    const choices = {};
    ballotForm.find('input[type=radio][data-ballot-choice]').toArray().forEach((input) => {
        const name = input.attr('name');
        if (!(name in choices) || Math.random() < 0.4) {
            choices[name] = input.attr('value');
        }
    });

    sleep(5 + Math.random() * 10);

    const vote = post(ballotForm.attr('action'), { _token: ballotForm.find('input[name=_token]').attr('value'), ...choices }, 'dept vote');
    check(vote, { 'dept vote counted': (r) => r.status === 200 && r.body.includes('Your vote is in') });
}

export function registration() {
    // Students on the roll who haven't registered (after the seeded voters).
    const n = 700 + 1 + exec.scenario.iterationInTest;
    http.cookieJar().clear(BASE);

    const form = get(`${SPACE}/register`, 'dept register page');
    const res = post(`${SPACE}/register`, {
        _token: token(form),
        fullname: `Load Student ${n}`,
        matric_number: matric(n),
        password: PASSWORD,
        password_confirmation: PASSWORD,
    }, 'dept register');
    check(res, { 'dept registered': (r) => r.status === 200 && r.body.includes('Log out') });
}

const watching = { url: null, etag: null, wait: 5 };

export function watcher() {
    if (!watching.url) {
        const home = get(SPACE, 'dept home');
        const url = home.html().find('.election-card a.stretched-link').first().attr('href');
        if (!url) {
            sleep(5);
            return;
        }
        const res = get(url, 'dept results page');
        check(res, { 'dept results page ok': (r) => r.status === 200 && r.body.includes('data-live-results') });
        const file = res.html().find('[data-live-results]').attr('data-live-results');
        watching.url = file && file.startsWith('http') ? file : `${BASE}${file}`;
    }

    // Like live-results.js: ask with the last ETag; slow down while nothing changes.
    const params = { tags: { name: 'dept live results' }, headers: watching.etag ? { 'If-None-Match': watching.etag } : {} };
    const res = logged(http.get(watching.url, params), params);
    check(res, { 'dept live results ok': (r) => r.status === 200 || r.status === 304 });
    if (res.status === 200) {
        watching.etag = res.headers.Etag || res.headers.ETag || watching.etag;
        watching.wait = 5;
    } else {
        watching.wait = Math.min(watching.wait + 2, 15);
    }
    sleep(watching.wait);
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
        '## Department election rush',
        '',
        `${data.metrics.http_reqs.values.count} requests (${data.metrics.http_reqs.values.rate.toFixed(1)}/s), `
            + `peak ${data.metrics.vus_max.values.max} simulated people at once, all from one address, `
            + `${failed.toFixed(2)}% failed, ${checks.toFixed(2)}% of checks passed.`,
        '',
        '| Request | Median ms | p95 ms | Max ms | Budget |',
        '|---|---:|---:|---:|---|',
        ...rows,
        '',
    ].join('\n');
    return { stdout: md, 'department-rush-summary.md': md };
}
