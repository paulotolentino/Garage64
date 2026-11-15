const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        const messages = [];
        const seen = [];
        let reject = false;
        await page.route('http://garage.test/**', async route => {
            const request = route.request();
            if (request.url().endsWith('messages.js')) return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(__dirname, '../assets/js/messages.js'), 'utf8') });
            if (request.url().includes('search_users=1')) {
                const query = new URL(request.url()).searchParams.get('q');
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ users: query === 'paulo' ? [{ id: 2, display_name: 'Paulo', username: 'paulo', slug: 'paulo' }] : [] }) });
            }
            if (request.method() === 'POST') {
                if (request.postData().includes('mark_seen')) {
                    seen.push(request.postData());
                    return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
                }
                if (reject) return route.fulfill({ status: 422, contentType: 'application/json', body: '{"ok":false,"error":"Aguarde"}' });
                messages.push({ id: '1', sender_id: 1, body: '<img src=x onerror="alert(1)">', created_at: '2026-11-14 12:00:00' });
                return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
            }
            if (request.url().includes('poll=1')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ messages, me: 1 }) });
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><div id="newConversation"><input id="conversation-search"><p id="conversation-search-status"></p><div id="conversation-search-results"></div></div><div id="message-list" style="height:300px;overflow:auto;overflow-wrap:anywhere"></div><form id="message-form"><input name="csrf_token" value="token" type="hidden"><textarea name="body" required></textarea><button>Enviar</button><p id="message-error" role="alert"></p></form><script src="/messages.js"></script>` });
        });
        await page.goto('http://garage.test/admin/messages?user=2');
        const historyLength = await page.evaluate(() => history.length);
        await page.locator('textarea').fill('Oi!');
        await page.locator('button').click();
        await page.waitForFunction(() => document.querySelector('[data-message-id="1"]'));
        assert.equal(await page.locator('#message-list img').count(), 0, 'messages must render as text, never HTML');
        assert.equal(await page.locator('textarea').inputValue(), '');
        assert.equal(await page.evaluate(() => history.length), historyLength);
        await page.evaluate(() => { document.hasFocus = () => false; });
        messages.push({ id: '2', sender_id: 2, body: 'Olá!', created_at: '2026-11-14 12:00:01' });
        await page.waitForFunction(() => document.querySelector('[data-message-id="2"]'), { timeout: 10000 });
        await page.waitForTimeout(1200);
        assert.equal(seen.length, 0, 'unfocused conversation must not mark messages seen');
        await page.evaluate(() => { document.hasFocus = () => true; document.querySelector('#message-list').style.marginTop = '2000px'; });
        await page.waitForTimeout(1200);
        assert.equal(seen.length, 0, 'off-screen messages must not be seen');
        await page.evaluate(() => { document.querySelector('#message-list').style.marginTop = '0'; });
        await page.waitForFunction(() => document.querySelector('[data-message-id="2"]').dataset.seen === '1');
        assert.equal(seen.length, 1);
        messages[0].read_at = '2026-11-14 12:01:00';
        await page.waitForFunction(() => document.querySelector('[data-message-id="1"] .inbox-receipt')?.textContent === 'Vista 14/11 12:01');
        console.log('PASS receipts: unfocused/offscreen ignored, visible incoming acknowledged, outgoing receipt updated');
        assert.equal(await page.locator('[data-message-id="1"]').count(), 1, 'polling must not duplicate messages');
        reject = true;
        await page.locator('textarea').fill('Manter rascunho');
        await page.locator('button').click();
        await page.waitForFunction(() => document.querySelector('#message-error').textContent === 'Aguarde');
        assert.equal(await page.locator('textarea').inputValue(), 'Manter rascunho');
        await page.locator('#conversation-search').fill('paulo');
        await page.waitForSelector('#conversation-search-results a');
        assert.equal(await page.locator('#conversation-search-results a').getAttribute('href'), '/admin/messages?user=2');
        await page.locator('#conversation-search').fill('inexistente');
        await page.waitForFunction(() => document.querySelector('#conversation-search-status').textContent === 'Nenhum colecionador encontrado.');
        assert.equal(await page.locator('#conversation-search-results a').count(), 0);
        console.log('PASS new conversation: user search, recipient link and empty results');
        console.log('PASS mobile chat: send, receive, safe text rendering, no duplicates, no navigation, draft preserved on error');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
