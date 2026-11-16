const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');const {execFileSync}=require('node:child_process');const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
for(const width of [1280,390]){const page=await browser.newPage({viewport:{width,height:900}});let restored=false;
await page.route('http://garage.test/**',route=>{
const req=route.request(),url=new URL(req.url());
if(req.method()==='POST'){assert(url.pathname==='/admin/view-mode');assert(req.postData().includes('name="csrf_token"')||req.postData().includes('csrf_token='));restored=true;}
if(url.pathname.startsWith('/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(__dirname,'..',url.pathname))});
if(url.pathname.includes('activity'))return route.fulfill({contentType:'application/json',body:'{}'});
return route.fulfill({contentType:'text/html',body:execFileSync('php',[path.join(__dirname,'view-mode-layout.php'),restored?'normal':'support'],{encoding:'utf8'})});
});
await page.goto('http://garage.test/admin/');await page.waitForFunction(()=>document.readyState==='complete');
assert(await page.locator('.view-mode-banner').isVisible());assert(await page.locator('#name').isDisabled());assert(await page.locator('#filter').isEnabled());
assert.equal(await page.locator('.admin-nav-link[href="/admin/announcements"]').count(),0);
assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
await page.screenshot({path:`/private/tmp/garage64-support-${width}.png`,fullPage:true});
await page.locator('[data-view-mode-exit] button').click();await page.waitForFunction(()=>!document.querySelector('.view-mode-banner'));
assert(restored);assert(await page.locator('#name').isEnabled());
console.log(`PASS support ${width}px: banner, read-only controls, GET search, hidden admin tools and return`);await page.close();}
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
