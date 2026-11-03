'use strict';
// Called only by the disposable real-worker acceptance run.
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const net = require('node:net');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
assert.equal(process.env.RUN_DB_TESTS, '1');
assert.match(process.env.DB_NAME || '', /^retailmind_acceptance_[a-f0-9]{12}$/);
assert.equal(process.env.DB_HOST, '127.0.0.1');
(async () => {
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    const origin = `http://127.0.0.1:${port}`;
    const server = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, '-t', 'src/frontend'],
        {env: process.env, stdio: ['ignore', 'ignore', 'pipe']});
    let errors = ''; server.stderr.on('data', chunk => { errors += chunk; });
    let browser;
    try {
        for (let i = 0; i < 100; i++) {
            try { if ((await fetch(origin, {signal: AbortSignal.timeout(1000)})).ok) break; } catch {}
            if (i === 99) throw new Error(errors);
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('acceptance_admin');
        await page.locator('#landing-login-password').fill('AcceptanceTest@2026');
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(origin + '/components/report/predictions.php');
        let text = await page.locator('body').innerText();
        assert.ok(text.includes(process.env.ACCEPTANCE_MODEL), 'Page identifies worker model');
        assert.ok(text.includes(process.env.ACCEPTANCE_ORIGIN), 'Page identifies forecast origin');
        const row = page.getByRole('row').filter({hasText: 'ISOLATED-001'});
        assert.equal(await row.count(), 1);
        const cells = await row.locator('td').allTextContents();
        assert.deepEqual(cells.slice(3, 6).map(Number), JSON.parse(process.env.ACCEPTANCE_TOTALS));
        const excluded = page.getByRole('row').filter({hasText: 'ISOLATED-002'});
        assert.match(await excluded.innerText(), /Stock-rule/);
        assert.match(await excluded.innerText(), /Unavailable/);
        const output = path.resolve('.scratch/ticket-417');
        fs.mkdirSync(output, {recursive: true});
        await page.screenshot({path: path.join(output, 'real-worker-dashboard.png'), fullPage: true});
        await page.setViewportSize({width: 390, height: 844});
        await page.screenshot({path: path.join(output, 'real-worker-dashboard-narrow.png'), fullPage: true});
        assert.ok(await page.getByRole('heading', {name: /Demand Forecast/}).first().isVisible());
        await page.setViewportSize({width: 1280, height: 720});
        await page.goto(origin + '/components/inventory_management/replenishment_queue.php');
        const queue = page.locator('tr.queue-item').filter({hasText: 'ISOLATED-001'});
        assert.equal(await queue.count(), 1);
        assert.match(await queue.innerText(), /Forecast details/);
        assert.ok((await queue.innerText()).includes(String(JSON.parse(process.env.ACCEPTANCE_TOTALS)[2])));
        await page.screenshot({path: path.join(output, 'real-worker-guidance.png'), fullPage: true});
        console.log(JSON.stringify({authenticated_pages: ['predictions', 'replenishment_queue'], totals: cells.slice(3, 6).map(Number)}));
    } finally {
        await browser?.close();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
