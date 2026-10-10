/*
 * Performance budget for what a first visit downloads (gzipped, as the
 * server sends it). Run after `npm run build`; CI fails when a limit is
 * passed. Raise a limit only on purpose, with a reason in the commit.
 *
 *   node scripts/check-budget.mjs
 */
import { readFile } from 'node:fs/promises';
import { gzipSync } from 'node:zlib';

const BUDGET = {
    // Every page: the stylesheet, the main script and what it imports.
    css: 24 * 1024,
    js: 24 * 1024,
    // Both variable fonts (already compressed).
    fonts: 80 * 1024,
    // Loaded only on the pages that need them.
    'reader (without PDF.js)': 8 * 1024,
    'assistant': 6 * 1024,
    'elections': 4 * 1024,
};

const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));

async function size(file, compress = true) {
    const bytes = await readFile(`public/build/${file}`);
    return compress ? gzipSync(bytes, { level: 9 }).length : bytes.length;
}

// A chunk and the chunks it imports (not the dynamic imports).
async function total(entry) {
    const seen = new Set();
    const queue = [entry];
    let bytes = 0;
    while (queue.length) {
        const key = queue.shift();
        if (seen.has(key) || !manifest[key]) {
            continue;
        }
        seen.add(key);
        bytes += await size(manifest[key].file);
        queue.push(...(manifest[key].imports ?? []).filter((i) => !manifest[i]?.file.includes('pdf')));
    }
    return bytes;
}

const fonts = Object.values(manifest).filter((chunk) => chunk.file.endsWith('.woff2'));
const measured = {
    css: await size(manifest['resources/css/app.css'].file),
    js: await total('resources/js/app.js'),
    fonts: (await Promise.all(fonts.map((chunk) => size(chunk.file, false)))).reduce((a, b) => a + b, 0),
    'reader (without PDF.js)': await size(manifest['resources/js/reader.js'].file),
    'assistant': await size(manifest['resources/js/assistant.js'].file),
    'elections': await size(manifest['resources/js/elections.js'].file),
};

const kb = (bytes) => `${(bytes / 1024).toFixed(1)} KB`;
let over = false;
console.log('Performance budget (gzipped)');
for (const [name, limit] of Object.entries(BUDGET)) {
    const ok = measured[name] <= limit;
    over ||= !ok;
    console.log(`${ok ? '  ok ' : ' OVER'}  ${name.padEnd(26)} ${kb(measured[name]).padStart(9)} of ${kb(limit)}`);
}
if (over) {
    console.error('\nOver budget: make it smaller, or raise the limit in scripts/check-budget.mjs on purpose.');
    process.exitCode = 1;
}
