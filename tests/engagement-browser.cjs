const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
            const page = await browser.newPage({ viewport });
            let liked = false, rating = 0, comments = 0, fail = false, posts = 0;
            function html() {
                return `<!doctype html><body class="md-page" style="margin:0">
                <div style="height:1000px">Miniatura</div>
                <div class="md-like-wrapper" style="height:60px"><form method="post" action="/mini/1/car#rating" class="md-like-form"><input name="csrf_token" value="token" type="hidden"><input name="action" value="${liked ? 'unlike' : 'like'}" type="hidden"><button>${liked ? 'Curtido' : 'Curtir'}</button></form><span class="md-like-count">${liked ? 1 : 0}</span></div>
                <div class="md-rating" style="height:60px"><form action="/mini/1/car#rating" class="md-rating-form"><input name="csrf_token" value="token" type="hidden"><button name="public_rating" value="5">5</button></form><span class="score">${rating}</span></div>
                <section id="comments"><form class="cm-form" action="/mini/1/car#comments"><input name="comment_action" value="create" type="hidden"><textarea name="comment_body" required></textarea><button>Comentar</button></form><p class="count">${comments}</p><form class="cm-delete-form" onsubmit="return confirm('Excluir?')" action="/mini/1/car#comments"><input name="comment_action" value="delete" type="hidden"><button>Excluir</button></form></section>
                <div style="height:1500px"></div><script src="/assets/js/engagement.js"></script>`;
            }
            await page.route('http://garage.test/**', async route => {
                const req = route.request();
                if (req.url().includes('/assets/js/')) return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(__dirname, '../assets/js/engagement.js'), 'utf8') });
                if (req.method() === 'POST') {
                    posts++;
                    assert.equal(req.headers()['x-requested-with'], 'Garage64');
                    const body = req.postData();
                    if (fail) return route.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Aguarde' }) });
                    if (body.includes('name="action"')) liked = !liked;
                    if (body.includes('name="public_rating"')) rating = 5;
                    if (body.includes('name="comment_body"')) comments++;
                    return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
                }
                return route.fulfill({ contentType: 'text/html', body: html() });
            });
            await page.goto('http://garage.test/collections');
            await page.goto('http://garage.test/mini/1/car');
            const initialHistory = await page.evaluate(() => history.length);
            await page.evaluate(() => scrollTo(0, 950));
            const initialScroll = await page.evaluate(() => scrollY);
            await page.locator('.md-like-form button').click();
            await page.waitForFunction(() => document.querySelector('.md-like-count').textContent === '1');
            assert.equal(await page.evaluate(() => scrollY), initialScroll);
            await page.locator('.md-rating-form button').click();
            await page.waitForFunction(() => document.querySelector('.score').textContent === '5');
            await page.locator('textarea').fill('Comentário de teste');
            await page.locator('.cm-form button').click();
            await page.waitForFunction(() => document.querySelector('.count').textContent === '1');
            assert.equal(await page.evaluate(() => history.length), initialHistory);
            assert.equal(page.url(), 'http://garage.test/mini/1/car');
            page.once('dialog', dialog => dialog.dismiss());
            await page.locator('.cm-delete-form button').click();
            assert.equal(posts, 3, 'cancelled deletion must not send a POST');
            fail = true;
            await page.locator('.md-like-form button').click();
            await page.waitForFunction(() => document.querySelector('[role="alert"]')?.textContent === 'Aguarde');
            assert.equal(await page.locator('.md-like-count').textContent(), '1');
            await page.goBack();
            assert.equal(page.url(), 'http://garage.test/collections');
            console.log(`PASS ${viewport.width}px: like, rating, comments, errors, cancellation, scroll and Back`);
            await page.close();
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
