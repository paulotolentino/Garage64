const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const width of [1280, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            await page.route('http://garage.test/**', route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/assets/')) {
                    const file = path.join(__dirname, '..', url.pathname);
                    return route.fulfill({ body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'application/javascript' });
                }
                if (url.searchParams.has('poll')) return route.fulfill({ contentType:'application/json', body:'{"messages":[],"me":1}' });
                if (url.pathname.includes('activity')) return route.fulfill({ contentType:'application/json', body:'{}' });
                return route.fulfill({ contentType:'text/html', body:execFileSync('php', [path.join(__dirname,'messages-layout.php'), url.searchParams.has('empty') ? 'empty' : 'thread'], { encoding:'utf8' }) });
            });
            await page.goto('http://garage.test/admin/messages?user=2');
            await page.waitForTimeout(800);
            assert(await page.locator('#message-form').isVisible());
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
            const composer = await page.locator('#message-form').boundingBox();
            assert(composer.y + composer.height <= 900, 'composer fits viewport');
            await page.screenshot({ path:`/private/tmp/garage64-messages-${width}.png`, fullPage:true });
            if (width === 390) assert(await page.locator('.inbox-back').isVisible());
            await page.goto('http://garage.test/admin/messages?empty=1');
            if (width === 1280) assert(await page.locator('.inbox-empty').isVisible());
            await page.locator('#inbox-filter').fill('não existe');
            assert(await page.locator('#inbox-filter-empty').isVisible());
            console.log(`PASS layout ${width}px: real PHP view, no overflow, visible composer, empty state and filter`);
            await page.close();
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
