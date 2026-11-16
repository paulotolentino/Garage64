const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async()=>{
    const browser = await chromium.launch({channel:'chrome',headless:true});
    try {
        for (const width of [1280,390]) {
            const page = await browser.newPage({viewport:{width,height:900}});
            await page.route('http://garage.test/**', route=>{
                const url = new URL(route.request().url());
                if(url.pathname.startsWith('/assets/')) {
                    const file=path.join(__dirname,'..',url.pathname);
                    return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':'image/svg+xml'});
                }
                return route.fulfill({contentType:'text/html',body:execFileSync('php',[path.join(__dirname,'account-access-layout.php'),url.searchParams.get('state')||'pending'],{encoding:'utf8'})});
            });
            for(const state of ['pending','sent','complete','invalid','token']) {
                await page.goto('http://garage.test/verify-email?state='+state);
                await page.waitForTimeout(250);
                assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
                const main=await page.locator('.account-access-main').boundingBox();
                assert(main.width<=500);
                if(state==='sent') {
                    assert((await page.locator('h1').textContent()).includes('caixa de entrada'));
                    assert(await page.locator('.account-access-help').isVisible());
                    await page.screenshot({path:`/private/tmp/garage64-verify-redesign-${width}.png`,fullPage:true});
                }
                if(state==='complete') assert.equal(await page.locator('form').count(),0);
                if(state==='invalid') {
                    assert(await page.locator('[role=alert]').isVisible());
                    assert(await page.getByRole('button',{name:'Reenviar confirmação'}).isVisible());
                    assert.equal(await page.getByRole('button',{name:'Confirmar meu e-mail'}).count(),0);
                }
                if(state==='token') {
                    assert.equal(await page.locator('h1').textContent(),'Falta só um passo');
                    assert(await page.getByRole('button',{name:'Confirmar meu e-mail'}).isVisible());
                    assert.equal(await page.getByRole('button',{name:'Reenviar confirmação'}).count(),0);
                }
            }
            console.log(`PASS ${width}px: pending, sent, confirmed, invalid and token states; no overflow`);
            await page.close();
        }
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
