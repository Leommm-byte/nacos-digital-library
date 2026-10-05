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
    ['saved', '/saved', 'F/ND/24/0000004'],
    ['profile', '/profile', 'F/ND/24/0000004'],
    ['settings', '/settings', 'F/ND/24/0000004'],
    ['reset-codes', '/reset-codes', 'F/ND/23/0000003'],
    ['styleguide', '/styleguide', 'F/ND/24/0000004'],
];

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
    const href = await page.locator('.book-card-link').first().getAttribute('href');
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
            const url = path === 'FIRST_BOOK' ? context.bookUrl : `${base}${path}`;
            await page.goto(url, { waitUntil: 'networkidle' });
            if (before) {
                await before(page);
            }
            await page.waitForTimeout(250);
            await page.screenshot({ path: `${out}/${name}--${viewportName}--${theme}.png`, fullPage: true });
            await page.close();
        }

        for (const context of Object.values(contexts)) {
            await context.close();
        }
    }
}

await browser.close();
console.log(`Saved screenshots to ${out}/`);
