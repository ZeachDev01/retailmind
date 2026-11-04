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
            await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
        };
        await login('super_admin');
        await page.goto(origin + '/components/system_administrator/ml_settings.php');
        await page.getByText('Training eligibility & product history', {exact: true}).click();
        const eligibilityPolicy = await page.locator('[aria-label="Training Eligibility policy"]').innerText();
        assert.match(eligibilityPolicy, /140 accumulated usable days/);
        for (const key of ['minimum_history_days', 'preferred_history_days', 'minimum_nonzero_sales_days', 'history_window_days']) {
            assert.equal(await page.locator(`[name="${key}"]`).count(), 0, key + ' is read-only policy');
        }
        await page.goto(origin + '/components/report/data_readiness.php');
        await page.getByText('How training eligibility works', {exact: true}).click();
        assert.equal(await page.locator('[aria-label="Training Eligibility policy"]').innerText(), eligibilityPolicy,
            'Model Training and Data Readiness explain the same effective policy');
        await page.goto(origin + '/components/system_administrator/ml_settings.php');
        for (const key of ['forecast_period_days', 'n_estimators', 'max_depth', 'min_samples_leaf',
            'min_samples_split', 'prediction_interval_lower', 'prediction_interval_upper', 'accuracy_threshold_wape']) {
            assert.equal(await page.locator(`[name="${key}"]`).count(), 0, key + ' cannot imply ineffective changes');
        }
        await page.getByText('Fixed model recipe & approval requirements', {exact: true}).click();
        assert.match(await page.locator('main').innerText(), /15 trees, maximum depth 8, random seed 42/);
        await page.locator('#settings-heading').click();
        const values = await page.locator('#model-quality-settings').evaluate(form => Object.fromEntries(new FormData(form)));
        const settingsUrl = page.url();
        for (const value of ['', '-1', '201', 'NaN', '1e999']) {
            const response = await context.request.post(settingsUrl, { form: { ...values, ml_max_wape_7d: value } });
            assert.match(await response.text(), /Settings were not saved/, value + ' must be rejected');
        }
        const saved = await context.request.post(settingsUrl, { form: { ...values,
            ml_max_wape_7d: '21.5', ml_max_wape_14d: '27', ml_max_wape_28d: '35' } });
        assert.match(await saved.text(), /Forecast settings saved/);
        await page.reload();
        assert.equal(await page.locator('#ml_max_wape_7d').inputValue(), '21.5');
        assert.equal(await page.locator('#ml_max_wape_14d').inputValue(), '27');
        assert.equal(await page.locator('#ml_max_wape_28d').inputValue(), '35');
        assert.equal((await context.request.post(settingsUrl, { form: { action: 'save_model_quality' } })).status(), 403);
        await page.getByRole('link', { name: 'Import sales history', exact: true }).click();
        await page.waitForURL('**/system_administrator/forecast_import.php');
        const importUrl = origin + '/components/system_administrator/forecast_import.php';
        const legacyImportUrl = origin + '/components/report/forecast_import.php';
        assert.equal((await page.goto(legacyImportUrl)).status(), 200, 'Existing bookmarks remain usable');
        assert.equal((await page.goto(importUrl)).status(), 200);
        const forbidden = await context.request.post(importUrl, { form: { confirm_import: '1' } });
        assert.equal(forbidden.status(), 403, 'Missing CSRF must be denied');
        const upload = async csv => {
            await page.locator('#forecast-csv').setInputFiles({ name: 'history.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
            await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Preview and validate' }).click()]);
        };
        await upload('sale_date,sku,quantity\n2026-01-01,UNKNOWN,5\n');
        assert.match(await page.locator('main .alert[role="alert"]').innerText(), /not an active product/);
        const csv = 'sale_date,sku,quantity\n' + Array.from({ length: 35 }, (_, day) => {
            const date = new Date(Date.UTC(2026, 0, 1 + day)).toISOString().slice(0, 10);
            return `${date},THEME-ITEM,5`;
        }).join('\n');
        await upload(csv);
        assert.equal(await page.getByRole('table', { name: 'CSV Sales History Preview' }).locator('tbody tr').count(), 20, 'Preview must be limited to 20 rows');
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Import 35 daily totals', exact: true }).click()]);
        assert.match(await page.locator('main .alert[role="status"]').innerText(), /Imported 35 daily totals/);
        await upload(csv);
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Import 35 daily totals', exact: true }).click()]);
        assert.match(await page.locator('main .alert[role="status"]').innerText(), /Imported 0 daily totals; skipped 35/);
        await page.goto(origin + '/components/report/data_readiness.php');
        await page.getByText('How training eligibility works', {exact: true}).click();
        assert.match(await page.locator('.main-content').innerText(), /Closures, unknown observations, and Stockout Days do not add usable days/);
        assert.equal(await page.getByRole('link', { name: 'Import sales history', exact: true }).count(), 0, 'Data imports belong in Model Training');
        const after = JSON.parse(fixture('state'));
        assert.equal(after.sales, before.sales, 'History imports must not create receipts');
        assert.equal(after.stock, before.stock, 'History imports must not change stock');
        await context.close();
        for (const role of ['admin', 'inventory_manager', 'cashier']) {
            const deniedContext = await browser.newContext();
            const denied = await deniedContext.newPage();
            await denied.goto(origin + '/?login=1');
            await denied.locator('#landing-login-username').fill('theme_' + role);
            await denied.locator('#landing-login-password').fill(data.password);
            await Promise.all([denied.waitForNavigation({ waitUntil: 'domcontentloaded' }), denied.getByRole('button', { name: 'Log in', exact: true }).click()]);
            assert.equal((await deniedContext.request.post(settingsUrl, { form: values })).status(), 403, role + ' cannot save model limits');
            assert.equal((await denied.goto(importUrl)).status(), 403, role + ' must be denied');
            assert.equal((await denied.goto(legacyImportUrl)).status(), 403, role + ' must be denied at the former route');
            await deniedContext.close();
        }
        console.log('Forecast import browser checks passed (upload, preview, import, readiness, duplicates, authorization, stock preservation)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
