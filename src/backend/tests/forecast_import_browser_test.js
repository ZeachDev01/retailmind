const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const net = require('node:net');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Forecast import browser: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let browser, server;
    try {
        const data = JSON.parse(fixture('create'));
        const before = JSON.parse(fixture('state'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...process.env, RM_THEME_TEST_DATABASE: database }, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext();
        const page = await context.newPage();
        const login = async role => {
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
        };
        await login('inventory_manager');
        const importUrl = origin + '/components/report/forecast_import.php';
        assert.equal((await page.goto(importUrl)).status(), 200);
        const forbidden = await context.request.post(importUrl, { form: { confirm_import: '1' } });
        assert.equal(forbidden.status(), 403, 'Missing CSRF must be denied');
        const upload = async csv => {
            await page.locator('#forecast-csv').setInputFiles({ name: 'history.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
            await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Preview CSV' }).click()]);
        };
        await upload('sale_date,sku,quantity\n2026-01-01,UNKNOWN,5\n');
        assert.match(await page.locator('p[role="alert"]').innerText(), /not an active product/);
        const csv = 'sale_date,sku,quantity\n' + Array.from({ length: 35 }, (_, day) => {
            const date = new Date(Date.UTC(2026, 0, 1 + day)).toISOString().slice(0, 10);
            return `${date},THEME-ITEM,5`;
        }).join('\n');
        await upload(csv);
        assert.equal(await page.locator('tbody tr').count(), 20, 'Preview must be limited to 20 rows');
        await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Import 35 daily totals', exact: true }).click()]);
        assert.match(await page.locator('p[role="status"]').innerText(), /Imported 35 daily totals/);
        await upload(csv);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Import 35 daily totals', exact: true }).click()]);
        assert.match(await page.locator('p[role="status"]').innerText(), /Imported 0 daily totals; skipped 35/);
        await page.goto(origin + '/components/report/data_readiness.php');
        assert.match(await page.locator('tbody').innerText(), /36 with sales/);
        const after = JSON.parse(fixture('state'));
        assert.equal(after.sales, before.sales, 'History imports must not create receipts');
        assert.equal(after.stock, before.stock, 'History imports must not change stock');
        await context.close();
        const cashierContext = await browser.newContext();
        const cashier = await cashierContext.newPage();
        await cashier.goto(origin + '/?login=1');
        await cashier.locator('#landing-login-username').fill('theme_cashier');
        await cashier.locator('#landing-login-password').fill(data.password);
        await Promise.all([cashier.waitForNavigation(), cashier.getByRole('button', { name: 'Log in', exact: true }).click()]);
        assert.equal((await cashier.goto(importUrl)).status(), 403, 'Cashier must be denied');
        console.log('Forecast import browser checks passed (upload, preview, import, readiness, duplicates, authorization, stock preservation)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
