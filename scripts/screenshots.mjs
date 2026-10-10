/*
 * Screenshots of every screen, for design review. Run against a seeded app:
 *   APP_URL=http://127.0.0.1:8000 node scripts/screenshots.mjs [out-dir]
 * CI runs it on every push to a claude/** branch (.github/workflows/screenshots.yml).
 */
import { chromium } from 'playwright';
import { mkdir, readFile, writeFile } from 'node:fs/promises';

const base = process.env.APP_URL ?? 'http://127.0.0.1:8000';
const out = process.argv[2] ?? 'screenshots';
const password = 'Password1!';

const viewports = {
    phone: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
    desktop: { viewport: { width: 1280, height: 860 }, deviceScaleFactor: 1 },
};

// [name, path, account] — account null means signed out.
const pages = [
    ['guest-home', '/', null],
    ['login', '/login', null],
    ['login-error', '/login', null, async (page) => {
        await page.fill('#matric_number', 'F/ND/24/0000004');
        await page.fill('#password', 'wrong-password');
        await page.click('form button[type=submit]');
        await page.waitForLoadState('networkidle');
    }],
    ['signup', '/signup', null],
    ['forgot-password', '/forgot-password', null],
    ['reset-with-code', '/reset-with-code', null],
    ['not-found', '/this-page-does-not-exist', null],
    ['home', '/', 'F/ND/24/0000004'],
    ['library', '/library', 'F/ND/24/0000004'],
    ['library-empty-search', '/library?q=zzqqxx', 'F/ND/24/0000004'],
    ['book', 'FIRST_BOOK', 'F/ND/24/0000004'],
    ['reader', 'FIRST_BOOK/read', 'F/ND/24/0000004', async (page) => {
        // Fails the run if PDF.js never draws the page.
        await page.locator('.reader-canvas').waitFor({ timeout: 20000 });
    }],
    ['saved', '/saved', 'F/ND/24/0000004'],
    ['books-read', '/reading', 'F/ND/24/0000004'],
    ['timetable', '/timetable', 'F/ND/24/0000004'],
    ['timetable-empty', '/timetable', 'F/HD/24/3211001'],
    ['exams', '/exams', 'F/ND/24/0000004'],
    ['exams-everyone', '/exams?show=all', 'F/ND/24/0000004'],
    ['timetable-governor', '/timetable', 'F/HD/24/3212002'],
    ['timetable-edit', '/timetable/edit', 'F/HD/24/3212002'],
    ['timetable-edit-open', '/timetable/edit', 'F/HD/24/3212002', async (page) => {
        // A lecture opened for changes.
        await page.locator('.tt-edit-row summary').first().click();
    }],
    ['profile', '/profile', 'F/ND/24/0000004'],
    ['settings', '/settings', 'F/ND/24/0000004'],
    ['reset-codes', '/reset-codes', 'F/ND/23/0000003'],
    ['upload', '/upload', 'F/ND/24/0000004'],
    ['upload-errors', '/upload', 'F/ND/24/0000004', async (page) => {
        // Submitting with nothing chosen shows the inline error.
        await page.click('[data-upload-submit]');
        await page.waitForTimeout(300);
        // Back to the top: scrolled, the sticky header covers a field, which
        // the accessibility check reads as a too-small target.
        await page.evaluate(() => window.scrollTo(0, 0));
    }],
    ['uploads-empty', '/uploads', 'F/ND/24/0000004'],
    ['uploads', '/uploads', 'F/ND/23/0000003'],
    ['upload-status', 'FIRST_UPLOAD', 'F/ND/23/0000003'],
    ['upload-changes', 'LINK /uploads .upload-row:has-text("Changes requested")', 'F/ND/24/0000004'],
    ['upload-edit', 'LINK /uploads .upload-row:has-text("Changes requested") /edit', 'F/ND/24/0000004'],
    ['notifications', '/notifications', 'F/ND/24/0000004'],
    ['review-queue', '/review', 'F/HD/24/3212002'],
    ['home-reviewer', '/', 'F/HD/24/3212002'],
    ['announcements', '/announcements', 'F/ND/24/0000004'],
    ['announcements-manage', '/announcements/manage', 'F/HD/24/3212002'],
    ['announcement-new', '/announcements/create', 'F/HD/24/3212002'],
    ['review-book', 'LINK /review .upload-row', 'F/HD/24/3212002', async (page) => {
        // The page previews are drawn by PDF.js.
        await page.locator('.review-preview canvas').first().waitFor({ timeout: 20000 });
    }],
    ['review-approved', 'LINK /review?status=approved .upload-row', 'F/HD/24/3212002'],
    ['elections', '/elections', 'F/ND/24/0000004'],
    ['election-guest', 'LINK /elections .election-card a', null],
    ['election-ballot', 'LINK /elections .election-card a', 'F/ND/24/0000004', async (page) => {
        // Pick a candidate in the first two positions.
        for (const position of (await page.locator('.ballot-position').all()).slice(0, 2)) {
            await position.locator('.ballot-option').first().click();
        }
        await page.evaluate(() => window.scrollTo(0, 0));
    }],
    ['election-results', 'LINK /elections .upload-row', 'F/ND/24/0000004'],
    // End to end: the governor votes on the first run; later runs show the
    // "you have voted" state. Fails the run if the vote isn't recorded.
    ['election-voted', 'LINK /elections .election-card a', 'F/HD/24/3212002', async (page) => {
        if (await page.locator('[data-ballot]').count()) {
            for (const position of await page.locator('.ballot-position').all()) {
                await position.locator('.ballot-option').first().click();
            }
            page.once('dialog', (dialog) => dialog.accept());
            await page.click('[data-ballot] button[type=submit]');
            await page.waitForLoadState('networkidle');
        }
        await page.locator('.vote-done').waitFor({ timeout: 10000 });
    }],
    ['elections-manage', '/elections/manage', 'F/HD/24/3211001'],
    ['election-new', '/elections/manage/create', 'F/HD/24/3211001'],
    ['nominal-roll', '/nominal-roll', 'F/HD/24/3211001'],
    ['admin-dashboard', '/admin', 'F/HD/24/3211001'],
    ['admin-users', '/admin/users', 'F/HD/24/3211001'],
    ['admin-user', 'LINK /admin/users?q=Tobi .user-cell', 'F/HD/24/3211001'],
    ['admin-accounts', '/admin/accounts', 'F/HD/24/3211001'],
    // End to end: creates the accounts of one class and lands on its slips.
    ['admin-slips', '/admin/accounts', 'F/HD/24/3211001', async (page) => {
        await page.locator('input[name="classes[]"]:not([disabled])').first().check();
        await page.click('form[action$="/admin/accounts"] button[type=submit]');
        await page.waitForURL(/\/admin\/accounts\/slips$/, { timeout: 30000 });
    }],
    ['admin-books', '/admin/books', 'F/HD/24/3211001'],
    ['admin-reports', '/admin/reports', 'F/HD/24/3211001'],
    ['admin-audit', '/admin/audit', 'F/HD/24/3211001'],
    ['admin-settings', '/admin/settings', 'F/HD/24/3211001'],
    ['admin-timetables', '/timetable/edit?class=full_time%7CND1%7C', 'F/HD/24/3211001'],
    ['exams-manage', '/exams/manage', 'F/HD/24/3211001'],
    ['election-setup', 'LINK /elections/manage .upload-row:has-text("Draft")', 'F/HD/24/3211001'],
    ['election-monitor', 'LINK /elections/manage .upload-row:has-text("Voting open")', 'F/HD/24/3211001'],
    // End to end: these really upload, and fail the run if they don't land
    // on the thank-you page.
    ['upload-pdf-chosen', '/upload', 'F/ND/24/0000004', async (page) => {
        await page.setInputFiles('#pdf', { name: 'past-questions.pdf', mimeType: 'application/pdf', buffer: samplePdf() });
    }],
    ['upload-pdf-done', '/upload', 'F/ND/24/0000004', async (page) => {
        await page.setInputFiles('#pdf', { name: 'past-questions.pdf', mimeType: 'application/pdf', buffer: samplePdf() });
        await fillDetails(page, 'Operating Systems Past Questions');
        await page.click('[data-upload-submit]');
        await page.waitForURL(/\/uploads\/[^/]+$/, { timeout: 30000 });
    }],
    ['upload-photos-chosen', '/upload', 'F/ND/24/0000004', async (page) => {
        await page.click('label:has(input[name="type"][value="scan"])');
        await page.setInputFiles('#pages', [samplePhoto('page-1.png'), samplePhoto('page-2.png')]);
    }],
    ['upload-photos-done', '/upload', 'F/ND/24/0000004', async (page) => {
        await page.click('label:has(input[name="type"][value="scan"])');
        await page.setInputFiles('#pages', [samplePhoto('page-1.png'), samplePhoto('page-2.png')]);
        await fillDetails(page, 'Networks Lecture Notes');
        await page.click('[data-upload-submit]');
        await page.waitForURL(/\/uploads\/[^/]+$/, { timeout: 90000 });
    }],
    // End to end: asks the assistant (the free helper in CI, no API key)
    // and fails the run if no answer arrives.
    ['assistant', '/assistant', 'F/ND/24/0000004', async (page) => {
        if (await page.locator('.assistant-log .assistant-msg:not([data-assistant-intro])').count()) {
            page.once('dialog', (dialog) => dialog.accept());
            await page.click('[data-assistant-clear] button');
            await page.waitForTimeout(500);
        }
        await askAssistant(page, 'Show my saved books', 1);
        await askAssistant(page, 'How are my uploads doing?', 2);
    }],
    ['assistant-panel', '/library', 'F/ND/24/0000004', async (page) => {
        await page.click('[data-assistant-open]');
        await page.locator('#assistant-panel').waitFor({ state: 'visible', timeout: 5000 });
        const before = await page.locator('#assistant-panel .assistant-msg.is-bot:not([data-assistant-intro])').count();
        await askAssistant(page, 'Find books for ND1', before + 1, '#assistant-panel');
        return 'viewport';
    }],
    // End to end: keeps the first book on the phone, goes offline and opens
    // it again. Fails the run if the offline reader can't draw the page.
    ['offline-reader', 'FIRST_BOOK', 'F/ND/24/0000004', async (page) => {
        const keep = page.locator('[data-keep-offline-save]');
        if (await keep.isVisible()) {
            await keep.click();
        }
        await page.locator('[data-keep-offline-remove]').waitFor({ state: 'visible', timeout: 30000 });
        await page.evaluate(() => navigator.serviceWorker.ready);
        // Let the worker store the reader (PDF.js) too.
        await page.waitForTimeout(3000);
        const id = await page.locator('[data-keep-offline]').getAttribute('data-id');
        await page.context().setOffline(true);
        // Playwright's offline mode doesn't reach the service worker's own
        // requests, so the offline reader is opened directly; the book
        // itself must come from the copy on the phone.
        await page.goto(`${base}/offline/read?book=${encodeURIComponent(id)}`);
        await page.locator('[data-offline-reader] .reader-canvas').waitFor({ timeout: 20000 });
    }],
    ['offline', '/offline', 'F/ND/24/0000004', async (page) => {
        await page.locator('[data-offline-books] li').first().waitFor({ timeout: 5000 });
    }],
    ['offline-guest', '/offline', null],
    ['styleguide', '/styleguide', 'F/ND/24/0000004'],
];

async function askAssistant(page, question, answers, scope = '[data-assistant]') {
    await page.fill(`${scope} [data-assistant-form] textarea`, question);
    await page.press(`${scope} [data-assistant-form] textarea`, 'Enter');
    await page.locator(`${scope} .assistant-msg.is-bot:not([data-assistant-intro])`).nth(answers - 1).waitFor({ timeout: 15000 });
    await page.waitForTimeout(300);
}

// A minimal valid one-page PDF.
function samplePdf() {
    const content = 'BT /F1 28 Tf 72 760 Td (Past Questions 2024) Tj ET';
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        `<< /Length ${content.length} >>\nstream\n${content}\nendstream`,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    let pdf = '%PDF-1.4\n';
    const offsets = objects.map((body, i) => {
        const offset = pdf.length;
        pdf += `${i + 1} 0 obj\n${body}\nendobj\n`;
        return offset;
    });
    const xref = pdf.length;
    pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    pdf += offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('');
    pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
    return Buffer.from(pdf, 'latin1');
}

// A small page-like PNG (grey lines on white) standing in for a photo.
function samplePhoto(name) {
    const png = 'iVBORw0KGgoAAAANSUhEUgAAAlgAAAMgCAIAAABwAouTAAAKEklEQVR42u3X0Q1dMQhEwefIvVHzVrepAsVXmakAwccRp+0PAP5Xf6wAACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEACEEQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgBQAgB4F+5Xxx6ZlwO4E1JfIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAsOu0tQUAfIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAsOZ+ceiZcTmANyXxEQKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEALArtPWFgDwEQKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEALAmvvFoWfG5QDelMRHCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCAC7TltbAMBHCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABr7heHnhmXA3hTEh8hAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAOw6bW0BAB8hAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAKy5Xxx6ZlwO4E1JfIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAsOu0tQUAfIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAsOZ+ceiZcTmANyXxEQKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEALArtPWFgDwEQKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEALAmvvFoWfG5QDelMRHCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCAC7TltbAMBHCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABr7heHnhmXA3hTEh8hAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAOw6bW0BAB8hAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAKy5Xxx6ZlwO4E1JfIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAsOu0tQUAfIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAsOZ+ceiZcTmANyXxEQKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEALArtPWFgDwEQKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEAKAEALAmvvFoWfG5QDelMRHCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCAC7TltbAMBHCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABr7heHnhmXA3hTEh8hAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAOw6bW0BAB8hAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAAghAEJoBQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQAIIQBCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCABCCAC/3+/3+wv4g1ElwCke0wAAAABJRU5ErkJggg==';
    return { name, mimeType: 'image/png', buffer: Buffer.from(png, 'base64') };
}

async function fillDetails(page, title) {
    await page.fill('#title', title);
    await page.fill('#author', 'Screenshot Bot');
}

async function login(context, matric) {
    const page = await context.newPage();
    await page.goto(`${base}/login`);
    await page.fill('#matric_number', matric);
    await page.fill('#password', password);
    await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
    await page.close();
}

async function firstBookUrl(context) {
    const page = await context.newPage();
    await page.goto(`${base}/library`);
    const link = page.locator('.book-card-link').first();
    if (!(await link.count())) {
        console.error('No book links on /library (is the page broken?)');
        await page.close();
        return `${base}/library`;
    }
    const href = await link.getAttribute('href');
    // Save two books so "Saved" has content.
    const forms = page.locator('form[data-bookmark] button');
    for (let i = 0; i < Math.min(2, await forms.count()); i++) {
        if ((await forms.nth(i).getAttribute('aria-pressed')) !== 'true') {
            await forms.nth(i).click();
            await page.waitForTimeout(300);
        }
    }
    await page.close();
    return href;
}

async function firstUploadUrl(context) {
    const page = await context.newPage();
    await page.goto(`${base}/uploads`);
    const href = await page.locator('.upload-row').first().getAttribute('href').catch(() => null);
    await page.close();
    return href ?? `${base}/uploads`;
}

// 'LINK <page> <selector> [suffix]': the first matching link on that page.
async function linkUrl(context, spec) {
    const [, from, ...rest] = spec.split(' ');
    const suffix = rest.at(-1)?.startsWith('/') ? rest.pop() : '';
    const page = await context.newPage();
    await page.goto(`${base}${from}`);
    const href = await page.locator(rest.join(' ')).first().getAttribute('href', { timeout: 5000 }).catch(() => null);
    await page.close();
    if (!href) {
        failures.push(`${spec}: no link found`);
    }
    return (href ?? `${base}${from}`) + suffix;
}

// Accessibility: every screen is checked with axe-core after it's
// photographed. Serious and critical problems fail the run; everything is
// listed in accessibility.md next to the screenshots.
const axeSource = await readFile(new URL('../node_modules/axe-core/axe.min.js', import.meta.url), 'utf8');
const axeFindings = new Map();

async function checkAccessibility(page, name, where) {
    // Evaluated through the browser's debugging protocol, so the page's
    // Content-Security-Policy doesn't block it.
    await page.evaluate(axeSource);
    const violations = await page.evaluate(async () => {
        const result = await window.axe.run(document, {
            resultTypes: ['violations'],
            runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] },
        });
        return result.violations.map((v) => ({
            id: v.id,
            impact: v.impact,
            help: v.help,
            targets: v.nodes.slice(0, 3).map((n) => n.target.join(' ')),
        }));
    });
    for (const violation of violations) {
        const key = `${violation.id}|${name}`;
        const entry = axeFindings.get(key) ?? { ...violation, page: name, where: [] };
        entry.where.push(where);
        axeFindings.set(key, entry);
    }
}

const failures = [];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

for (const [viewportName, viewport] of Object.entries(viewports)) {
    for (const theme of ['light', 'dark']) {
        const contexts = {};

        for (const [name, path, account, before] of pages) {
            const key = account ?? 'guest';
            if (!contexts[key]) {
                const context = await browser.newContext({ ...viewport, colorScheme: theme, reducedMotion: 'reduce' });
                await context.addInitScript((t) => localStorage.setItem('theme', t), theme);
                if (account) {
                    await login(context, account);
                    context.bookUrl = await firstBookUrl(context);
                }
                contexts[key] = context;
            }

            const context = contexts[key];
            const page = await context.newPage();
            const url = path.startsWith('LINK ')
                ? await linkUrl(context, path)
                : path === 'FIRST_UPLOAD'
                ? await firstUploadUrl(context)
                : path.startsWith('FIRST_BOOK') ? context.bookUrl + path.slice('FIRST_BOOK'.length) : `${base}${path}`;
            page.on('pageerror', (error) => failures.push(`${name}: script error: ${error.message}`));
            try {
                const response = await page.goto(url, { waitUntil: 'networkidle' });
                if (response && response.status() >= 500) {
                    failures.push(`${name}: HTTP ${response.status()}`);
                }
                // A step can return 'viewport' to photograph only the screen
                // (for fixed panels).
                const shot = before ? await before(page) : null;
                await page.waitForTimeout(250);
                await page.screenshot({ path: `${out}/${name}--${viewportName}--${theme}.png`, fullPage: shot !== 'viewport' });
                await checkAccessibility(page, name, `${viewportName} ${theme}`);
            } catch (error) {
                failures.push(`${name} (${viewportName}, ${theme}): ${error.message.split('\n')[0]}`);
            }
            // Back online for the next screen, even if this one failed.
            await page.context().setOffline(false);
            await page.close();
        }

        for (const context of Object.values(contexts)) {
            await context.close();
        }
    }
}

await browser.close();
console.log(`Saved screenshots to ${out}/`);

const findings = [...axeFindings.values()].sort((a, b) => a.page.localeCompare(b.page) || a.id.localeCompare(b.id));
const serious = findings.filter((f) => f.impact === 'serious' || f.impact === 'critical');
await writeFile(`${out}/accessibility.md`, [
    '# Accessibility (axe-core)',
    '',
    findings.length ? `${findings.length} findings, ${serious.length} serious or critical.` : 'No findings.',
    '',
    ...findings.map((f) => `- **${f.impact}** \`${f.id}\` on **${f.page}** (${f.where.join(', ')}): ${f.help}. ${f.targets.map((t) => `\`${t}\``).join(', ')}`),
    '',
].join('\n'));
console.log(`Accessibility: ${findings.length} findings, ${serious.length} serious or critical (see accessibility.md).`);
for (const f of serious) {
    failures.push(`accessibility: ${f.impact} ${f.id} on ${f.page} (${f.where.join(', ')}): ${f.help} ${f.targets.join(', ')}`);
}

if (failures.length) {
    // Still publish the screenshots, but make the run fail visibly.
    console.error(`Problems:\n- ${[...new Set(failures)].join('\n- ')}`);
    process.exitCode = 1;
}
