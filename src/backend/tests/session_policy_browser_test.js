const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
    let expired = false;
    const methods = [];
    const navigationHeaders = [];
    const server = http.createServer((req, res) => {
        if (req.url === '/activity') {
            methods.push(req.method);
            res.writeHead(expired ? 401 : 200, {'Content-Type': 'application/json'});
            res.end(JSON.stringify({remaining: 3600}));
        } else if (req.url === '/navigation.js') {
            res.setHeader('Content-Type', 'text/javascript');
            res.end(fs.readFileSync(path.resolve(__dirname, '../../frontend/assets/js/page-navigation.js')));
        } else if (req.url.endsWith('.php')) {
            navigationHeaders.push(req.headers);
            res.setHeader('Content-Type', 'application/vnd.retailmind.page+json');
            res.end(JSON.stringify({role: 'cashier', html: '<html><body><main class="main-content"><input></main></body></html>'}));
        } else if (req.url === '/session-activity.js') {
            res.setHeader('Content-Type', 'text/javascript');
            res.end(fs.readFileSync(path.resolve(__dirname, '../../frontend/assets/js/session-activity.js')));
        } else {
            res.end('<body><!--rm-shell-start--><script src="/navigation.js" data-workspace="cashier"></script><script id="rm-session-policy" type="application/json">{"endpoint":"/activity","login":"/login","csrf":"token"}</script><script src="/session-activity.js"></script><!--rm-shell-end--><main class="main-content"><input></main>');
        }
    });
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.clock.install();
        const initialCheck = page.waitForResponse('**/activity');
        await page.goto('http://127.0.0.1:' + server.address().port);
        await initialCheck;
        await page.clock.runFor(31000);
        await page.waitForTimeout(100);
        assert.ok(methods.length >= 2, 'Idle browser checks expiry automatically');
        assert.ok(methods.every(method => method === 'GET'), 'Automatic checks never renew inactivity');
        await page.locator('input').press('a');
        await page.waitForTimeout(100);
        assert.ok(methods.includes('POST'), 'Real typing renews inactivity');
        const posts = methods.filter(method => method === 'POST').length;
        await page.evaluate(() => document.dispatchEvent(new KeyboardEvent('keydown')));
        await page.waitForTimeout(100);
        assert.equal(methods.filter(method => method === 'POST').length, posts, 'Synthetic events do not renew inactivity');
        await page.evaluate(() => window.RetailMindNavigation.prefetch('/prefetched.php'));
        await page.waitForTimeout(100);
        assert.equal(navigationHeaders.at(-1)['x-retailmind-background'], '1', 'Prefetch is marked automatic');
        await page.evaluate(() => window.RetailMindNavigation.navigate('/next.php', {fetch: {headers: {'X-RetailMind-Background': '1'}}}));
        assert.equal(navigationHeaders.at(-1)['x-retailmind-background'], '1', 'Automatic refresh marker survives navigation');
        const checks = methods.length;
        await page.clock.runFor(31000);
        await page.waitForTimeout(100);
        assert.ok(methods.length > checks, 'Shared session timer survives page navigation');
        await page.locator('input').press('b');
        await page.waitForTimeout(100);
        assert.ok(methods.filter(method => method === 'POST').length > posts, 'Typing after navigation renews activity');
        expired = true;
        await page.clock.runFor(31000);
        await page.waitForURL('**/login');
        console.log('Staff session browser policy: passed');
    } finally { await browser?.close(); server.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
