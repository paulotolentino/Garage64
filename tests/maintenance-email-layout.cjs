const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
for(const width of [1280,390]){
const page=await browser.newPage({viewport:{width,height:900}});
await page.route('http://garage.test/**',route=>{
const request=route.request();
if(request.method()==='POST'){
assert(request.postData().includes('email_process'));
assert(request.postData().includes('csrf_token'));
return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,message:'Lote processado.'})});
}
if(request.url().includes('/assets/css/'))return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(__dirname,'../assets/css/style.css'))});
return route.fulfill({contentType:'text/html',body:execFileSync('php',[path.join(__dirname,'maintenance-email-layout.php')],{encoding:'utf8'})});
});
await page.goto('http://garage.test/admin/manutencao');
assert((await page.locator('body').textContent()).includes('a cada 7 dias'));
assert.equal(await page.locator('#email-message_delay').count(),0);
assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
await page.getByRole('button',{name:'Processar próximo lote'}).click();
await page.waitForFunction(()=>document.body.textContent.includes('Lote processado.'));
await page.screenshot({path:`/private/tmp/garage64-maintenance-email-${width}.png`,fullPage:true});
console.log(`PASS maintenance ${width}px: weekly policy, CSRF batch submit, result and no overflow`);
await page.close();}
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
