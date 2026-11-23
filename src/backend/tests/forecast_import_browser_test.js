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
        fixture('forecast_import_setup');
        const before = JSON.parse(fixture('state'));
        const importBefore = JSON.parse(fixture('forecast_import_state'));
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
            await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
        };
        await login('super_admin');
        console.log('Forecast import: signed in as Super Administrator');
        const importUrl = origin + '/components/system_administrator/forecast_import.php';
        const legacyImportUrl = origin + '/components/report/forecast_import.php';
        assert.equal((await page.goto(importUrl)).status(), 200, 'Super Administrator retains import access');
        assert.equal((await context.request.get(importUrl + '?template=1')).status(), 200, 'Super Administrator retains template access');
        await context.clearCookies();
        await login('admin');
        console.log('Forecast import: verifying Administrator import workflow');
        await page.goto(origin + '/components/report/data_readiness.php');
        await page.locator('.forecast-studio-nav').getByRole('link', { name: 'Import history', exact: true }).click();
        await page.waitForURL('**/system_administrator/forecast_import.php');
        assert.equal((await page.goto(legacyImportUrl)).status(), 200, 'Existing bookmarks remain usable');
        assert.equal((await page.goto(importUrl)).status(), 200);
        const forbidden = await context.request.post(importUrl, { form: { confirm_import: '1' } });
        assert.equal(forbidden.status(), 403, 'Missing CSRF must be denied');
        const upload = async csv => {
            await page.locator('#forecast-csv').setInputFiles({ name: 'history.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
            await Promise.all([page.waitForResponse(response => response.url() === importUrl && response.request().method() === 'POST'), page.getByRole('button', { name: 'Preview and validate' }).click()]);
            await page.waitForFunction(() => document.querySelector('main')?.getAttribute('aria-busy') !== 'true');
        };
        await upload('sale_date,sku,quantity\n2026-01-01,UNKNOWN,5\n');
        assert.match(await page.locator('[aria-label="Invalid CSV rows"]').innerText(), /not an active product/);
        assert.equal(await page.locator('[name="confirm_import"]').count(), 0, 'Invalid rows block confirmation');
        const invalidToken = await page.locator('[name="csrf_token"]').first().inputValue();
        const invalidConfirm = await context.request.post(importUrl, { form: { confirm_import: '1', csrf_token: invalidToken } });
        assert.match(await invalidConfirm.text(), /preview expired/i, 'Forged confirmation cannot save invalid preview');
        const csv = 'sale_date,sku,quantity\n' + Array.from({ length: 35 }, (_, day) => {
            const date = new Date(Date.UTC(2026, 0, 1 + day)).toISOString().slice(0, 10);
            return `${date},THEME-ITEM,5`;
        }).join('\n') + '\n2025-12-31,THEME-ITEM,999\n';
        await upload(csv);
        assert.match(await page.locator('#preview-heading').innerText(), /35 accepted.*1 skipped.*0 invalid/);
        assert.equal(await page.getByRole('table', { name: 'CSV Sales History Preview' }).locator('tbody tr').count(), 20, 'Preview must be limited to 20 rows');
        await page.getByRole('button', { name: 'Import 36 Daily Totals', exact: true }).click();
        await page.locator('main .alert[role="status"]').waitFor();
        assert.match(await page.locator('main .alert[role="status"]').innerText(), /Imported 35 daily totals/);
        assert.match(await page.locator('[aria-label="Product Data Readiness after import"]').innerText(), /0 of 1 Products eligible.*More history needed/s);
        await upload(csv);
        assert.match(await page.locator('#preview-heading').innerText(), /0 accepted.*36 skipped.*0 invalid/);
        await page.getByRole('button', { name: 'Import 36 Daily Totals', exact: true }).click();
        await page.locator('main .alert[role="status"]').waitFor();
        assert.match(await page.locator('main .alert[role="status"]').innerText(), /Imported 0 daily totals; skipped 36/);
        await page.goto(origin + '/components/report/data_readiness.php');
        await page.getByRole('button', { name: 'About training eligibility', exact: true }).click();
        assert.match(await page.locator('.main-content').innerText(), /Closures, unknown observations, and Stockout Days do not add usable days/);
        assert.equal(await page.locator('.forecast-studio-nav a[href*="forecast_import.php"]').count(), 1, 'Readiness gives the Administrator a direct import action');
        const imported = JSON.parse(fixture('forecast_import_state'));
        assert.equal(imported.forecast_sales_imports.length, 35);
        assert(!imported.forecast_sales_imports.some(row => row.sale_date === '2025-12-31'), 'POS overlap is excluded from imported evidence');
        assert(imported.forecast_sales_imports.every(row => Number(row.imported_by) === 1), 'Administrator owns persisted import attribution');
        assert.deepEqual(imported.audits.map(Number), [1, 1], 'Import audit identifies acting Administrator');
        assert.deepEqual(imported.stock_movements, importBefore.stock_movements, 'Imports create no inventory movements');
        assert.deepEqual(imported.model_training_runs, importBefore.model_training_runs, 'Imports create no training run history');
        const after = JSON.parse(fixture('state'));
        assert.equal(after.sales, before.sales, 'History imports must not create receipts');
        assert.equal(after.stock, before.stock, 'History imports must not change stock');
        await context.close();
        for (const role of ['inventory_manager', 'cashier']) {
            const deniedContext = await browser.newContext();
            const denied = await deniedContext.newPage();
            await denied.goto(origin + '/?login=1');
            await denied.locator('#landing-login-username').fill('theme_' + role);
            await denied.locator('#landing-login-password').fill(data.password);
            await Promise.all([denied.waitForNavigation({ waitUntil: 'domcontentloaded' }), denied.getByRole('button', { name: 'Log in', exact: true }).click()]);
            assert.equal((await denied.goto(importUrl)).status(), 403, role + ' must be denied');
            assert.equal((await denied.goto(legacyImportUrl)).status(), 403, role + ' must be denied at the former route');
            assert.equal((await deniedContext.request.post(importUrl, { form: { confirm_import: '1', csrf_token: 'forged' } })).status(), 403, role + ' cannot confirm import directly');
            assert.equal((await deniedContext.request.get(importUrl + '?template=1')).status(), 403, role + ' cannot read the template');
            await deniedContext.close();
        }
        console.log('Forecast import browser checks passed (upload, preview, import, readiness, duplicates, authorization, stock preservation)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
