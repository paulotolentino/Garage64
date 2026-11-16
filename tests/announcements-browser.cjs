const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});try{
for(const width of [1440,390]) {
const page=await browser.newPage({viewport:{width,height:1000}});let queued=0,sent=0;
await page.route('http://garage.test/**',async route=>{
const req=route.request(),url=new URL(req.url());
if(req.method()==='POST'){
const body=req.postData();assert(body.includes('csrf_token'));
let result={ok:true};
if(body.includes('email_campaign_save'))result={...result,id:17,eligible:3,message:'Rascunho salvo.'};
else if(body.includes('email_campaign_preview'))result={...result,html:'<!doctype html><html><body><h1>Novidades</h1><p>Prévia de teste</p></body></html>'};
else if(body.includes('email_campaign_test'))result={...result,message:'Teste aceito.'};
else if(body.includes('email_campaign_upload'))result={...result,url:'http://garage.test/uploads/campaigns/'+ 'a'.repeat(32)+'.png'};
else if(body.includes('email_campaign_queue')){assert(body.includes('name="confirm"'));queued++;result={...result,counts:{total:3,sent:0,pending:3,failed:0,skipped:0}};}
else if(body.includes('email_campaign_process')){sent++;result={...result,result:{sent:1,skipped:0,failed:0},counts:{total:3,sent,pending:Math.max(0,3-sent),failed:0,skipped:0}};}
return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
}
if(url.pathname.startsWith('/assets/')){
const file=path.join(__dirname,'..',url.pathname);return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':'application/javascript'});
}
if(url.pathname.startsWith('/uploads/'))return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRXsAAAAASUVORK5CYII=','base64')});
if(url.pathname.includes('activity'))return route.fulfill({contentType:'application/json',body:'{}'});
return route.fulfill({contentType:'text/html',body:execFileSync('php',[path.join(__dirname,'announcements-layout.php')],{encoding:'utf8'})});
});
await page.goto('http://garage.test/admin/announcements');
await page.waitForSelector('.ql-editor');
await page.locator('#campaign-subject').fill('Novidades na sua garagem');
await page.locator('.ql-editor').fill('Agora você pode conversar com outros colecionadores.');
await page.locator('#campaign-image').setInputFiles({name:'image.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRXsAAAAASUVORK5CYII=','base64')});
await page.waitForSelector('.ql-editor img');
await page.getByRole('button',{name:'Salvar rascunho'}).click();
await page.waitForFunction(()=>document.querySelector('#campaign-id').value==='17');
assert.equal(queued,0,'Saving must not broadcast');
assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
await page.evaluate(()=>scrollTo(0,0));
await page.waitForTimeout(100);
await page.screenshot({path:`/private/tmp/garage64-announcements-${width}.png`,fullPage:true});
await page.getByRole('button',{name:'Prévia',exact:true}).click();
await page.waitForSelector('#campaignPreview.show');
assert.equal(queued,0,'Preview must not broadcast');
await page.locator('#campaignPreview [aria-label=Fechar]').click();
await page.waitForSelector('#campaignPreview.show',{state:'hidden'});
await page.getByRole('button',{name:'Enviar teste para mim'}).click();
await page.waitForFunction(()=>document.querySelector('#campaign-feedback').textContent==='Teste aceito.');
assert.equal(queued,0,'Test email must not broadcast');
await page.getByRole('button',{name:'Revisar envio'}).click();
await page.waitForSelector('#campaignConfirm.show');
assert(await page.locator('#campaign-send').isDisabled());
await page.locator('#campaign-confirm-check').check();
await page.locator('#campaign-send').click();
await page.waitForFunction(()=>document.querySelector('#campaign-status').textContent==='Concluído');
assert.equal(queued,1);assert.equal(sent,3);
assert(await page.locator('#campaign-subject').getAttribute('readonly')!==null);
console.log(`PASS announcements ${width}px: rich editor, draft, isolated preview, confirmation and batched progress; no overflow`);
await page.close();}
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
