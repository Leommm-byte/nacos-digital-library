/*
 * `npm audit --audit-level=high`, with dated exceptions (CI, Front-end
 * build).
 *
 * Fails on any high or critical advisory, unless it is listed in ALLOWED
 * below and its date hasn't passed. On that date CI fails again, so the
 * exception gets looked at instead of being forgotten. Only add one when
 * no fixed version exists and the advisory can't reach the site (say why),
 * and remove it once a fix is out: this script says when it can go.
 *
 *   node scripts/npm-audit.mjs
 */
import { execFileSync } from 'node:child_process';

const ALLOWED = {
    // picomatch ReDoS through repeated extglobs, every version up to 4.0.7
    // (the latest). Used only by Vite while building, with our own fixed
    // file patterns; never shipped to browsers or run on the server. npm's
    // only "fix" downgrades Vite to 6.2.7. Decided with the owner, 18 Nov 2026.
    'GHSA-fc5q-39xc-vjpf': '2026-12-31',
};

const SERIOUS = new Set(['high', 'critical']);

let report;
try {
    report = execFileSync('npm', ['audit', '--json'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
} catch (error) {
    // npm audit exits non-zero when it finds anything; the JSON is still there.
    report = error.stdout;
}

const data = JSON.parse(report || '{}');
if (data.error) {
    console.error(`npm audit failed: ${data.error.summary ?? JSON.stringify(data.error)}`);
    process.exit(1);
}

const today = new Date().toISOString().slice(0, 10);
const found = new Map();

for (const vulnerability of Object.values(data.vulnerabilities ?? {})) {
    for (const via of vulnerability.via ?? []) {
        // Strings name another package's advisory; objects are the advisories.
        if (typeof via !== 'object' || !SERIOUS.has(via.severity)) {
            continue;
        }
        const id = String(via.url ?? '').split('/').pop() || `advisory ${via.source}`;
        found.set(id, { title: via.title, package: via.name, range: via.range, url: via.url, severity: via.severity });
    }
}

let failed = false;

for (const [id, advisory] of found) {
    const until = ALLOWED[id];
    const line = `${advisory.severity}  ${advisory.package} ${advisory.range}: ${advisory.title} (${advisory.url})`;

    if (until && today <= until) {
        console.log(`allowed until ${until}  ${line}`);
    } else if (until) {
        console.error(`EXPIRED ${until}  ${line}\n  The exception in scripts/npm-audit.mjs has run out: update the package, or decide again.`);
        failed = true;
    } else {
        console.error(`FAILED  ${line}`);
        failed = true;
    }
}

for (const id of Object.keys(ALLOWED)) {
    if (!found.has(id)) {
        console.log(`note: ${id} no longer appears; remove it from ALLOWED in scripts/npm-audit.mjs.`);
    }
}

if (failed) {
    console.error('\nFix: npm update <package>, or npm audit for details.');
    process.exit(1);
}

console.log(found.size === 0 ? 'npm audit: no high or critical advisories.' : 'npm audit: only allowed advisories.');
