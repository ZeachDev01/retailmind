const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Operational reset browser: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-operational-reset-'));
const models = path.join(testDirectory, 'models');
fs.mkdirSync(models);
fs.writeFileSync(path.join(models, 'demand_model.joblib'), 'test model only');
fs.writeFileSync(path.join(models, 'model_metrics.json'), '{}');
const env = { ...process.env, BACKUP_STORAGE_PATH: path.join(testDirectory, 'recovery'), ML_MODEL_DIRECTORY: models };
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let browser, server;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...env, RM_THEME_TEST_DATABASE: database }, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({ headless: true });
        const login = async role => {
            const context = await browser.newContext();
            const page = await context.newPage();
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
            return { context, page };
        };
        const { context, page } = await login('admin');
        const manager = await login('inventory_manager');
        const resetUrl = origin + '/components/administrator/reset_data.php';
        assert.equal((await manager.page.goto(resetUrl)).status(), 403, 'Inventory Managers must not reset data');
        assert.equal((await page.goto(resetUrl)).status(), 200);
        assert.equal(await page.locator('#reset-password').count(), 1, await page.locator('body').innerText());
        assert.equal(await page.locator('form:has(#reset-password) button[type="submit"]').isDisabled(), true, 'An open shift must block reset');
        assert.equal((await context.request.post(resetUrl, { form: { confirmation: 'RESET ALL DATA', current_password: data.password } })).status(), 403, 'CSRF must be required');
        fixture('reset_ready');
        const singleBefore = JSON.parse(fixture('reset_state'));
        const deleteOne = async (productId, password = data.password) => {
            await page.goto(resetUrl);
            const form = page.locator('form:has(#delete-product)');
            const csrf = await form.locator('input[name="csrf_token"]').inputValue();
            const nonce = await form.locator('input[name="reset_nonce"]').inputValue();
            return context.request.post(resetUrl, { form: { action: 'delete_product', product_id: String(productId), current_password: password, confirmation: 'DELETE PRODUCT', csrf_token: csrf, reset_nonce: nonce } });
        };
        assert.match(await (await deleteOne(90000, 'wrong')).text(), /current password is incorrect/);
        assert.deepEqual(JSON.parse(fixture('reset_state')), singleBefore);
        assert.match(await (await deleteOne(90000)).text(), /Product deleted\. Other products/);
        const singleAfter = JSON.parse(fixture('reset_state'));
        for (const key of Object.keys(singleBefore)) {
            assert.equal(singleAfter[key], singleBefore[key] - (['products', 'inventory', 'supplier_products'].includes(key) ? 1 : 0), key + ' after single deletion');
        }
        await page.goto(resetUrl);
        assert.equal(await page.locator('#delete-product option[value="90000"]').count(), 0);
        assert.match(await (await deleteOne(1)).text(), /Product made inactive/);
        assert.deepEqual(JSON.parse(fixture('reset_state')), singleAfter, 'Historical product removal must preserve stock and history');
        await page.goto(resetUrl);
        assert.match(await page.locator('#delete-product option[value="1"]').innerText(), /inactive/);
        const before = JSON.parse(fixture('reset_state'));
        assert(before.sales > 1 && before.product_batches > 0 && before.inventory_units > 0, 'Fixture must contain linked sales and stock data');
        const submit = async (password, confirmation = 'RESET ALL DATA') => {
            await page.goto(resetUrl);
            const token = await page.locator('input[name="csrf_token"]').first().inputValue();
            const nonce = await page.locator('form:has(#reset-password) input[name="reset_nonce"]').inputValue();
            return context.request.post(resetUrl, { form: { csrf_token: token, reset_nonce: nonce, confirmation, current_password: password } });
        };
        let response = await submit('wrong password');
        assert.match(await response.text(), /current password is incorrect/);
        assert.deepEqual(JSON.parse(fixture('reset_state')), before, 'Wrong password must preserve all data');
        response = await submit(data.password, 'RESET');
        assert.match(await response.text(), /Type RESET ALL DATA/);
        assert.deepEqual(JSON.parse(fixture('reset_state')), before, 'Wrong confirmation must preserve all data');
        fixture('reset_fiscal_block');
        response = await submit(data.password);
        assert.match(await response.text(), /Reopen and unlock fiscal periods/);
        assert.deepEqual(JSON.parse(fixture('reset_state')), before, 'Closed fiscal records must prevent reset');
        fixture('reset_fiscal_open');
        const training = spawn(php, ['src/backend/tests/support/theme_fixture.php', 'reset_forecast_lock', database], { env });
        try {
            await new Promise((resolve, reject) => {
                training.stdout.once('data', data => data.toString().includes('LOCKED') ? resolve() : reject(new Error(data.toString())));
                training.once('exit', code => reject(new Error('Forecast lock holder exited: ' + code)));
            });
            response = await submit(data.password);
            assert.match(await response.text(), /Forecast training is running/);
            assert.deepEqual(JSON.parse(fixture('reset_state')), before, 'Active training must prevent reset');
        } finally {
            training.stdin.end('\n');
            await new Promise(resolve => training.once('exit', resolve));
        }
        fixture('reset_failure');
        response = await submit(data.password);
        assert.match(await response.text(), /Database changes were rolled back/);
        assert.deepEqual(JSON.parse(fixture('reset_state')), before, 'A foreign-key failure must roll back all deletions');
        assert(fs.existsSync(path.join(models, 'demand_model.joblib')), 'Failure must preserve the model');
        fixture('reset_allow');
        await page.goto(resetUrl);
        const staleNonce = await page.locator('form:has(#reset-password) input[name="reset_nonce"]').inputValue();
        const csrf = await page.locator('input[name="csrf_token"]').first().inputValue();
        await page.locator('#reset-password').fill(data.password);
        await page.locator('#reset-confirmation').fill('RESET ALL DATA');
        await Promise.all([page.waitForNavigation(), page.locator('form:has(#reset-password) button[type="submit"]').click()]);
        assert.match(await page.locator('p[role="status"]').innerText(), /Products, sales, inventory, and forecasting data were deleted/);
        const after = JSON.parse(fixture('reset_state'));
        for (const [key, value] of Object.entries(after)) {
            if (['general_promotions', 'users', 'categories', 'suppliers', 'roles', 'registers', 'fiscal_periods', 'preserved_audit'].includes(key)) {
                assert.equal(value, before[key], key + ' must be preserved');
            } else if (key === 'reset_audits') {
                assert.equal(value, 1, 'The successful reset must leave a protected audit record');
            } else assert.equal(value, 0, key + ' must be reset');
        }
        assert(!fs.existsSync(path.join(models, 'demand_model.joblib')) && !fs.existsSync(path.join(models, 'model_metrics.json')), 'Only the isolated model artifacts must be removed');
        response = await context.request.post(resetUrl, { form: { csrf_token: csrf, reset_nonce: staleNonce, confirmation: 'RESET ALL DATA', current_password: data.password } });
        assert.match(await response.text(), /reset form has expired/, 'A successful reset confirmation must not be replayable');
        assert.deepEqual(JSON.parse(fixture('reset_state')), after, 'A replay must not reset again');
        await page.goto(resetUrl);
        const downloadEvent = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Download backup before reset' }).click();
        const download = await downloadEvent;
        const backup = fs.readFileSync(await download.path(), 'utf8');
        assert(backup.includes('INSERT INTO `products`'), 'The downloadable backup must preserve products');
        assert(backup.includes('INSERT INTO `sales`'), 'The downloadable backup must preserve sales');
        assert(backup.includes('INSERT INTO `stock_predictions`'), 'The downloadable backup must preserve forecasts');
        assert(backup.includes('-- SHA256:'), 'The backup must have its integrity checksum');
        await manager.page.goto(origin + '/components/report/forecast_import.php');
        assert(!manager.page.url().endsWith('/components/report/forecast_import.php'), 'Other sessions must be invalidated');
        console.log('Operational reset browser checks passed (authorization, confirmation, rollback, backup, full reset, preserved definitions/audit, sessions, isolated model cleanup)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
        const relative = path.relative(os.tmpdir(), testDirectory);
        assert(relative.startsWith('rm-operational-reset-') && !relative.includes(path.sep), 'Cleanup must stay inside the generated test directory');
        fs.rmSync(testDirectory, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
