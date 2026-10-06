/*
 * Screenshots of every screen, for design review. Run against a seeded app:
 *   APP_URL=http://127.0.0.1:8000 node scripts/screenshots.mjs [out-dir]
 * CI runs it on every push to a claude/** branch (.github/workflows/screenshots.yml).
 */
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

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
    ['profile', '/profile', 'F/ND/24/0000004'],
    ['settings', '/settings', 'F/ND/24/0000004'],
    ['reset-codes', '/reset-codes', 'F/ND/23/0000003'],
    ['upload', '/upload', 'F/ND/24/0000004'],
    ['upload-errors', '/upload', 'F/ND/24/0000004', async (page) => {
        // Submitting with nothing chosen shows the inline error.
        await page.click('[data-upload-submit]');
        await page.waitForTimeout(300);
    }],
    ['uploads-empty', '/uploads', 'F/ND/24/0000004'],
    ['uploads', '/uploads', 'F/ND/23/0000003'],
    ['upload-status', 'FIRST_UPLOAD', 'F/ND/23/0000003'],
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
    ['styleguide', '/styleguide', 'F/ND/24/0000004'],
];

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
            const url = path === 'FIRST_UPLOAD'
                ? await firstUploadUrl(context)
                : path.startsWith('FIRST_BOOK') ? context.bookUrl + path.slice('FIRST_BOOK'.length) : `${base}${path}`;
            page.on('pageerror', (error) => failures.push(`${name}: script error: ${error.message}`));
            try {
                const response = await page.goto(url, { waitUntil: 'networkidle' });
                if (response && response.status() >= 500) {
                    failures.push(`${name}: HTTP ${response.status()}`);
                }
                if (before) {
                    await before(page);
                }
                await page.waitForTimeout(250);
                await page.screenshot({ path: `${out}/${name}--${viewportName}--${theme}.png`, fullPage: true });
            } catch (error) {
                failures.push(`${name} (${viewportName}, ${theme}): ${error.message.split('\n')[0]}`);
            }
            await page.close();
        }

        for (const context of Object.values(contexts)) {
            await context.close();
        }
    }
}

await browser.close();
console.log(`Saved screenshots to ${out}/`);

if (failures.length) {
    // Still publish the screenshots, but make the run fail visibly.
    console.error(`Problems:\n- ${[...new Set(failures)].join('\n- ')}`);
    process.exitCode = 1;
}
