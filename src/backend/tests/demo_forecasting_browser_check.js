// Called by observed_demand_mysql_test.py against its imported, trained Demo.
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const net = require('node:net');
const {chromium} = require('playwright');
assert.match(process.env.DB_NAME || '', /^retailmind_demo_[a-f0-9]{12}$/);
(async () => {
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    const origin = 'http://127.0.0.1:' + port;
    const server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend'],
        {env: {...process.env, APP_ENV: 'development'}, stdio: ['ignore', 'ignore', 'pipe']});
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
        await page.locator('#landing-login-username').fill('demo_inventory_manager');
        await page.locator('#landing-login-password').fill(process.env.DEMO_PASSWORD);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(origin + '/components/report/predictions.php');
        assert.match(await page.locator('body').innerText(), /Demo/i, 'Displayed forecasts identify Demo evidence');
        const row = page.getByRole('row').filter({hasText: 'DEMO-P0001'});
        assert.equal(await row.count(), 1, 'Imported product has displayed forecasts');
        const cells = await row.locator('td').allTextContents();
        assert.deepEqual(cells.slice(3, 6).map(Number), JSON.parse(process.env.DEMO_FORECAST_TOTALS));
        await page.goto(origin + '/components/report/forecast_analytics.php');
        const comparisons = page.locator('.data-table tbody tr');
        assert.equal(await comparisons.count(), 25);
        for (const comparison of await comparisons.all()) {
            assert.equal(await comparison.locator('td').nth(5).innerText(), 'Unknown');
            assert.equal(await comparison.locator('td').nth(6).innerText(), '—');
        }
        await page.goto(origin + '/components/inventory_management/replenishment_requests.php');
        const form = page.locator('form').filter({has: page.locator('input[name="source"][value="ml_forecast"]')}).first();
        assert.equal(await form.count(), 1, await page.locator('body').innerText());
        assert.equal(await form.locator('input[name="forecast_job_id"]').inputValue(), process.env.DEMO_FORECAST_JOB);
        const product = await form.locator('input[name="product_id"]').inputValue();
        const quantity = await form.locator('input[name="request_qty"]').inputValue();
        assert.ok(Number(quantity) > 0);
        await Promise.all([page.waitForNavigation(), form.getByRole('button', {name: 'Create Request from Forecast'}).click()]);
        assert.match(await page.locator('.message.success').innerText(), /created successfully/);
        console.log(JSON.stringify({product_id: Number(product), quantity: Number(quantity)}));
    } finally {
        await browser?.close();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
