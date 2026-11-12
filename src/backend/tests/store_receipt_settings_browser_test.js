const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Store receipt settings: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-store-settings-'));
const output = process.env.STORE_SETTINGS_OUTPUT || directory;
fs.mkdirSync(output, {recursive: true});
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const route = '/components/administrator/store_settings.php';
const values = {
    store_name: 'Updated Store <test>', business_identifier: 'TIN-385', store_email: 'store@example.test',
    store_phone: '09123456789', store_address: '385 Store Street', currency_symbol: '₱',
    timezone: 'Asia/Manila', receipt_footer: 'Thank you <customer> & welcome.',
};
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({baseURL: origin});
        const page = await context.newPage();
        page.setDefaultTimeout(5000);
        const login = async role => {
            await page.goto('/components/auth/logout.php');
            await page.goto('/?login=1');
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => url.pathname.includes('/components/')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        };
        await login('admin');
        const platform = '/components/system_administrator/system_settings.php';
        assert.equal((await context.request.get(platform)).status(), 403);
        assert.equal((await context.request.post(platform, {form: {action: 'save_dormancy', disable_days: '1'}})).status(), 403);
        await page.goto(route);
        assert.equal(await page.locator('[name="expiry_alert_days"]').count(), 0, 'Expiry lead time belongs to Add/Edit Product');
        for (const key of Object.keys(values)) assert.equal(await page.locator('[name="' + key + '"]').count(), 1, key + ' is editable');
        const token = await page.locator('[name="csrf_token"]').first().inputValue();
        const response = await context.request.post(route, {form: {action: 'save_receipts', csrf_token: token, ...values}});
        assert.match(await response.text(), /Store and receipt settings saved/);
        await page.goto(route);
        for (const [key, value] of Object.entries(values)) assert.equal(await page.locator('[name="' + key + '"]').inputValue(), value);
        const state = () => JSON.parse(fixture('store_settings_state'));
        const saved = state();
        assert.equal(saved.audit.length, 1);
        assert.equal(saved.audit[0].user_id, 1, 'Save is attributed to the Administrator');
        assert.deepEqual(JSON.parse(saved.audit[0].new_value), values);
        assert(saved.store.every(row => row.updated_by === 1));
        const post = async form => context.request.post(route, {form: {csrf_token: token, ...form}});
        const thresholds = {cash_variance_amount: '75', inventory_overdue_hours: '12', inventory_high_value_amount: '2500', fiscal_period_days_to_close: '2'};
        assert.match(await (await post({action: 'save_attention', ...thresholds, store_name: 'Forged threshold Store'})).text(), /Store attention thresholds saved/);
        assert.deepEqual(state().store, saved.store, 'Threshold save preserves receipt fields');
        const attention = state().attention;
        assert.match(await (await post({action: 'save_receipts', ...values, expiry_alert_days: '90,60,30', cash_variance_amount: '999', dormancy_disable_days: '1', arbitrary_key: 'injected'})).text(), /Store and receipt settings saved/);
        assert.deepEqual(state().attention, attention, 'Receipt save preserves thresholds');
        assert.deepEqual(state().platform, saved.platform, 'Receipt save cannot change platform settings');
        assert.deepEqual(state().store, saved.store, 'Extra keys are not persisted');

        let stable = state();
        for (const [invalid, feedback] of [
            [{store_name: '   '}, /Store name is required/],
            [{store_email: 'invalid'}, /valid store email/],
            [{timezone: 'Unknown/Timezone'}, /valid IANA timezone/],
            [{'store_name[]': 'array', store_name: undefined}, /Check the Store and receipt fields/],
        ]) {
            const form = {action: 'save_receipts', ...values, ...invalid};
            if (form.store_name === undefined) delete form.store_name;
            assert.match(await (await post(form)).text(), feedback);
            assert.deepEqual(state(), stable, 'Invalid input does not mutate settings or audit');
        }
        for (const action of ['save_dormancy', 'test_email', 'save', 'unknown']) {
            assert.match(await (await post({action, ...values, disable_days: '1'})).text(), /Choose a Store Settings section/);
            assert.deepEqual(state(), stable);
        }
        const logoFile = {name: 'logo.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAAAgCAIAAAAt/+nTAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAU0lEQVRYhe3YsQkAIAwFUb+4/8pxArEQPAL3WpscFoGkqkZnkx7glQE0A2gG0NbpIcnPOa5O+6r9DxhAM4BmAM0AmgE0A2gG0OJdCGYAzQCaAbQNoAsJO3lArLgAAAAASUVORK5CYII=', 'base64')};
        await page.goto(route);
        await page.locator('#receipt_logo').setInputFiles(logoFile);
        await page.getByRole('button', {name: 'Save receipt logo', exact: true}).click();
        await page.locator('.alert').filter({hasText: 'Receipt logo saved'}).waitFor({state: 'attached'});
        assert.match(await page.locator('.alert').textContent(), /Receipt logo saved/);
        const originalLogo = await page.locator('#receipt-logo-preview').getAttribute('src');
        assert.match(originalLogo, /^data:image\/png;base64,/);
        assert(await page.locator('#receipt-logo-preview').evaluate(image => image.complete && image.naturalWidth > 0));
        assert.deepEqual(await page.locator('#receipt-logo-preview').evaluate(image => [image.naturalWidth, image.naturalHeight]), [48, 16], 'Blank logo margins are trimmed');
        for (const width of [375, 1440]) {
            await page.setViewportSize({width, height: 1000});
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.locator('#receipt_logo').scrollIntoViewIfNeeded();
            await page.screenshot({path: path.join(output, 'logo-' + width + '.png')});
        }
        const logoPost = file => context.request.post(route, {multipart: {csrf_token: token, action: 'save_receipt_logo', receipt_logo: file}});
        const invalidLogo = await logoPost({name: 'fake.png', mimeType: 'image/png', buffer: Buffer.from('<svg onload="alert(1)"></svg>')});
        assert.match(await invalidLogo.text(), /Choose a valid JPG or PNG/);
        await page.goto(route);
        assert.equal(await page.locator('#receipt-logo-preview').getAttribute('src'), originalLogo, 'Rejected upload keeps the saved logo');
        const jpeg = spawnSync(php, ['-r', '$im=imagecreatetruecolor(1000,500); imagejpeg($im);'], {env});
        assert.equal(jpeg.status, 0);
        const replacement = {name: 'replacement.jpg', mimeType: 'image/jpeg', buffer: jpeg.stdout};
        fixture('store_settings_failure');
        assert.doesNotMatch(await (await logoPost(replacement)).text(), /Receipt logo saved/);
        await page.goto(route);
        assert.equal(await page.locator('#receipt-logo-preview').getAttribute('src'), originalLogo, 'Failed save keeps the previous logo');
        fixture('store_settings_allow');
        assert.match(await (await logoPost(replacement)).text(), /Receipt logo saved/);
        await page.goto(route);
        assert.notEqual(await page.locator('#receipt-logo-preview').getAttribute('src'), originalLogo, 'JPG replaces the saved PNG');
        assert.deepEqual(await page.locator('#receipt-logo-preview').evaluate(image => [image.naturalWidth, image.naturalHeight]), [384, 192], 'Large logos fit the thermal bounds without changing aspect ratio');
        assert.match(await (await post({action: 'save_receipts', ...values, store_name: ''})).text(), /Store and receipt settings saved/);
        await page.goto(route);
        assert.equal(await page.locator('#store_name').inputValue(), '');
        assert.equal(await page.locator('#store_name').getAttribute('required'), null, 'Store name is optional when a logo is saved');
        assert.equal(await page.locator('#receipt-logo-preview').count(), 1);
        assert.equal(state().store.find(row => row.setting_key === 'store_name').setting_value, '');
        assert.match(await (await post({action: 'remove_receipt_logo'})).text(), /Save a Store name before removing/);
        assert.equal(await page.locator('#receipt-logo-preview').count(), 1, 'Logo removal cannot leave the receipt without an identity');
        assert.match(await (await post({action: 'save_receipts', ...values})).text(), /Store and receipt settings saved/);
        assert.match(await (await logoPost({...logoFile, buffer: Buffer.alloc(2 * 1024 * 1024 + 1)})).text(), /Choose a valid JPG or PNG/);
        assert.equal((await context.request.post(route, {multipart: {action: 'save_receipt_logo', receipt_logo: logoFile}})).status(), 403);
        assert.match(await (await post({action: 'remove_receipt_logo'})).text(), /Receipt logo removed/);
        await page.goto(route);
        assert.equal(await page.locator('#receipt-logo-preview').count(), 0);
        stable = state();

        for (const csrf_token of ['', 'invalid']) {
            assert.equal((await post({action: 'save_receipts', ...values, csrf_token})).status(), 403);
            assert.deepEqual(state(), stable);
        }
        assert.equal((await context.request.post(route, {form: {action: 'save_receipts', ...values}})).status(), 403);
        assert.deepEqual(state(), stable);
        assert.equal((await context.request.post(route, {headers: {'CF-Ray': 'test-remote'}, form: {csrf_token: token, action: 'save_receipts', ...values}})).status(), 403);
        assert.deepEqual(state(), stable, 'Remote monitoring cannot save');
        fixture('store_settings_failure');
        const failed = await post({action: 'save_receipts', ...values, store_name: 'Must roll back'});
        assert.doesNotMatch(await failed.text(), /Store and receipt settings saved/);
        assert.deepEqual(state(), stable, 'Persistence failure rolls back fields and audit');
        fixture('store_settings_allow');

        for (const width of [375, 1440]) {
            await page.setViewportSize({width, height: 1000});
            await page.goto(route);
            assert(await page.locator('#store_name').isVisible());
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.screenshot({path: path.join(output, 'settings-' + width + '.png'), fullPage: true});
            await page.getByRole('link', {name: 'Attention thresholds', exact: true}).click();
            await page.locator('#cash_variance_amount').waitFor({state: 'visible'});
        }
        for (const role of ['cashier', 'inventory_manager', 'super_admin']) {
            await login(role);
            assert.equal((await context.request.get(route)).status(), 403);
            assert.equal((await context.request.post(route, {form: {action: 'save_receipts', ...values}})).status(), 403);
            assert.equal((await context.request.post(route, {form: {action: 'remove_receipt_logo'}})).status(), 403);
            assert.deepEqual(state(), stable, role + ' cannot write Store settings');
        }
        await page.goto(platform);
        assert.equal(await page.locator('[name="store_name"]').count(), 0, 'Platform Store editing form is removed');
        const platformToken = await page.locator('[name="csrf_token"]').first().inputValue();
        const platformPost = async form => context.request.post(platform, {form: {csrf_token: platformToken, ...form}});
        assert.match(await (await platformPost({action: 'save', ...values, store_name: 'Forbidden platform Store'})).text(), /Administrator Workspace/);
        assert.deepEqual(state(), stable, 'Former platform save cannot write Store settings');
        assert.match(await (await platformPost({action: 'save_dormancy', disable_days: '60', warn_days: '30', ...values, store_name: 'Forged dormancy Store'})).text(), /Dormancy Policy thresholds saved/);
        assert.deepEqual(state().store, stable.store);
        assert.match(await (await platformPost({action: 'test_brevo', brevo_api_key: 'fixture-key', brevo_sender_email: 'sender@example.test', test_recipient: 'invalid'})).text(), /valid test recipient email/);
        stable = state();
        await page.goto('/components/auth/workspace.php');
        const workspaceToken = await page.locator('[name="csrf_token"]').first().inputValue();
        assert.equal((await context.request.post('/components/auth/workspace.php', {form: {csrf_token: workspaceToken, workspace: 'admin'}, maxRedirects: 0})).status(), 403);
        assert.deepEqual(state(), stable, 'Super Administrator has only its technical Workspace');
        console.log('Store receipt settings: passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
