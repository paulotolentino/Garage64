const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');const path=require('node:path');const assert=require('node:assert/strict');
(async()=>{
const html=execFileSync('php',['-r',`require 'includes/config.php'; require 'includes/email_campaigns.php'; echo campaign_email_template('Novidades','<h2>Novidades&nbsp;na&nbsp;sua&nbsp;garagem</h2><p>Agora&nbsp;você&nbsp;pode&nbsp;conversar&nbsp;com&nbsp;outros&nbsp;colecionadores.&nbsp;Acompanhe&nbsp;mensagens,&nbsp;notificações&nbsp;e&nbsp;novidades&nbsp;da&nbsp;comunidade.</p><ul><li>Confirmação&nbsp;de&nbsp;e-mail&nbsp;e&nbsp;recuperação&nbsp;de&nbsp;senha.</li></ul><p><a href="https://garage64.online/collections">Acessar minha coleção</a></p><p><a href="https://garage64.online/">https://garage64.online/${'a'.repeat(100)}</a></p>')['html'];`],{cwd:path.join(__dirname,'..'),encoding:'utf8'});
assert(!html.includes('&nbsp;'));
assert(html.includes('word-break:normal'));
const browser=await chromium.launch({channel:'chrome',headless:true});
try{for(const width of [320,375,640]){
const page=await browser.newPage({viewport:{width,height:900}});await page.setContent(html);
const broken=await page.evaluate(()=>{
const result=[];const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);let node;
while(node=walker.nextNode()) {
for(const match of node.textContent.matchAll(/[\p{L}]+/gu)) {
if(match[0].length<3 || match[0].length>35)continue;
const range=document.createRange();range.setStart(node,match.index);range.setEnd(node,match.index+match[0].length);
const rows=new Set([...range.getClientRects()].filter(r=>r.width>0).map(r=>Math.round(r.top)));
if(rows.size>1)result.push(match[0]);
}}
return result;
});
assert.deepEqual(broken,[],`Words broken at ${width}px`);
assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`Horizontal overflow at ${width}px`);
await page.screenshot({path:`/private/tmp/garage64-email-wrapping-${width}.png`,fullPage:true});
console.log(`PASS ${width}px: real email HTML, NBSP normalized, words kept whole and long URL fits`);await page.close();
}}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
