const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({ channel:'chrome', headless:true });
    try {
        const page = await browser.newPage({ viewport:{ width:390,height:844 } });
        const response = await page.goto('http://127.0.0.1:8764/forgot-password.php');
        assert.equal(response.status(),200);
        assert.equal(response.headers()['referrer-policy'],'no-referrer');
        await page.locator('#email').fill('unknown@example.test');
        // The PHP development server does not process Apache clean URLs.
        await page.locator('form').evaluate(form => { form.action='/forgot-password.php'; });
        await page.locator('button[type=submit]').click();
        await page.waitForSelector('[role=status]');
        assert((await page.locator('[role=status]').textContent()).includes('Se houver uma conta'));
        await page.goto('http://127.0.0.1:8764/reset-password.php?token=invalid');
        await page.locator('#password').fill('new-password');
        await page.locator('#password-confirm').fill('new-password');
        await page.locator('form').evaluate(form => { form.action='/reset-password.php'; });
        await page.locator('button[type=submit]').click();
        await page.waitForSelector('[role=alert]');
        assert((await page.locator('[role=alert]').textContent()).includes('inválido'));
        await page.goto('http://127.0.0.1:8764/verify-email.php?token=invalid');
        assert(await page.locator('button[type=submit]').isVisible(), 'GET only offers confirmation, never consumes token');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false);
        await page.screenshot({path:'/private/tmp/garage64-confirm-email.png',fullPage:true});
        await page.goto('http://127.0.0.1:8764/forgot-password.php');
        await page.screenshot({path:'/private/tmp/garage64-forgot-password.png',fullPage:true});
        console.log('PASS mobile account pages: generic recovery response, invalid reset token, explicit confirmation, no-referrer and no overflow');
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
