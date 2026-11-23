const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const net = require('node:net');
const { chromium } = require('playwright');
if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Administrator training settings: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const fixture = (action, settings = false) => {
    const result = spawnSync(php, ['src/backend/tests/support/' + (settings ? 'training_settings_fixture.php' : 'theme_fixture.php'), action, database], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const state = () => JSON.parse(fixture('state', true));
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
            { env: { ...process.env, RM_THEME_TEST_DATABASE: database }, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({ headless: true });
        for (const role of ['admin', 'super_admin', 'inventory_manager', 'cashier']) {
            const context = await browser.newContext();
            const page = await context.newPage();
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
            const url = origin + '/components/system_administrator/ml_settings.php';
            const response = await page.goto(url);
            if (['inventory_manager', 'cashier'].includes(role)) {
                assert.equal(response.status(), 403, role + ' cannot view training settings');
                assert.equal((await context.request.post(url, { form: { action: 'save_schedule', schedule_weekly_retrain_day: 'Monday' } })).status(), 403);
                await context.close();
                continue;
            }
            assert.equal(response.status(), 200, role + ' can view training settings');
            assert.equal(await page.locator('.forecast-studio-nav a[href*="ml_settings.php"]').count(), 1, role + ' can reach Model Training in workflow');
            assert.equal(await page.locator('nav[aria-label="Main navigation"] a[href*="ml_settings.php"]').count(), 1, role + ' can reach Model Training in sidebar');
            assert.match(await page.locator('#effective-training-schedule').innerText(), /Asia\/Manila/);
            const before = state();
            await page.locator('#schedule_weekly_retrain_day').selectOption('Tuesday');
            await page.locator('#schedule_hour').selectOption('12');
            await page.locator('#schedule_minute').selectOption('30');
            await page.locator('#schedule_period').selectOption('AM');
            await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Save Weekly Schedule', exact: true}).click()]);
            assert.match(await page.locator('main').innerText(), /Weekly schedule saved/);
            await page.reload();
            assert.match(await page.locator('#effective-training-schedule').innerText(), /Tuesday at 12:30 AM/);
            const schedule = await page.locator('form:has([name="action"][value="save_schedule"])').evaluate(form => Object.fromEntries(new FormData(form)));
            await page.locator('#settings-heading').click();
            await page.locator('#ml_max_wape_7d').fill('21.5');
            await page.locator('#ml_max_wape_14d').fill('27');
            await page.locator('#ml_max_wape_28d').fill('35');
            await Promise.all([page.waitForNavigation(), page.locator('#model-quality-settings button[type="submit"]').click()]);
            assert.match(await page.locator('main').innerText(), /Forecast settings saved/);
            await page.reload();
            assert.equal(await page.locator('#ml_max_wape_7d').inputValue(), '21.5');
            assert.equal(await page.locator('#ml_max_wape_14d').inputValue(), '27');
            assert.equal(await page.locator('#ml_max_wape_28d').inputValue(), '35');
            const quality = await page.locator('#model-quality-settings').evaluate(form => Object.fromEntries(new FormData(form)));
            const saved = state();
            const actor = role === 'admin' ? 1 : 2;
            assert.ok(saved.audit.slice(-2).every(row => Number(row.user_id) === actor), 'audit attributes both saves to acting account');
            const settings = Object.fromEntries(saved.platform_settings.map(row => [row.setting_key, row]));
            for (const key of ['schedule_weekly_retrain_day', 'schedule_weekly_retrain_time', 'ml_max_wape_7d', 'ml_max_wape_14d', 'ml_max_wape_28d']) {
                assert.equal(Number(settings[key].updated_by), actor, key + ' attributed to actor');
            }
            for (const table of ['ml_jobs', 'model_training_runs']) assert.deepEqual(saved[table], before[table], table + ' unaffected by saving');
            const editable = ['schedule_weekly_retrain_day', 'schedule_weekly_retrain_time', 'ml_max_wape_7d', 'ml_max_wape_14d', 'ml_max_wape_28d'];
            for (const table of ['platform_settings', 'ml_settings']) {
                assert.deepEqual(saved[table].filter(row => !editable.includes(row.setting_key)), before[table].filter(row => !editable.includes(row.setting_key)), 'daily schedule, approval, observation and technical settings stay unchanged');
            }
            for (const form of [
                {...schedule, schedule_weekly_retrain_day: 'Funday'}, {...schedule, schedule_hour: '13'},
                {...schedule, schedule_minute: '60'}, {...schedule, schedule_period: 'evening'},
                {...schedule, 'schedule_weekly_retrain_day[]': 'Tuesday'},
                {...quality, ml_max_wape_7d: '22', ml_max_wape_28d: '201'},
                {...quality, ml_max_wape_7d: ''}, {...quality, ml_max_wape_7d: '-1'},
                {...quality, 'ml_max_wape_7d[]': '21.5'},
                {...quality, ml_max_wape_14d: 'NaN'}, {...quality, ml_max_wape_14d: '1e999'},
            ]) {
                const invalid = await context.request.post(url, {form});
                assert.equal(invalid.status(), 200);
                assert.match(await invalid.text(), /(?:Settings were|Schedule was) not saved/);
                assert.deepEqual(state(), saved, 'invalid input cannot partially save or add audit');
            }
            for (const base of [schedule, quality]) {
                for (const key of ['schedule_daily_forecast_time', 'forecast_engine', 'ml_approved_model_version']) {
                    const crafted = await context.request.post(url, {form: {...base, [key]: '23:59'}});
                    assert.equal(crafted.status(), 403, key + ' rejected at request boundary');
                    assert.deepEqual(state(), saved, 'crafted input changes nothing');
                }
                assert.equal((await context.request.post(url, {form: {...base, csrf_token: ''}})).status(), 403);
            }
            fixture('fail_audit', true);
            for (const form of [{...schedule, schedule_weekly_retrain_day: 'Wednesday'}, {...quality, ml_max_wape_7d: '12'}]) {
                const failed = await context.request.post(url, {form});
                assert.equal(failed.status(), 200);
                assert.match(await failed.text(), /Could not save/);
                assert.deepEqual(state(), saved, 'audit failure rolls back every setting');
            }
            fixture('allow_audit', true);
            await context.close();
        }
        console.log('Administrator training settings passed (browser persistence, validation, role/CSRF/allowlist boundaries, atomic audit, no job or approval side effects)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
