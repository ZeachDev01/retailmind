const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-recovery-email-'));
    const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
    const env = {...process.env, BACKUP_STORAGE_PATH: temp, RM_RECOVERY_EMAIL_TEST_DIR: temp, RM_THEME_TEST_DATABASE: database};
    const php = (file, args = []) => {
        const result = spawnSync(process.env.PHP_BINARY || 'php', [file, ...args], {env, encoding: 'utf8'});
        assert.equal(result.status, 0, result.stderr || result.stdout);
        return result.stdout;
    };
    let server, browser, logs = '';
    try {
        const data = JSON.parse(php('src/backend/tests/support/theme_fixture.php', ['create', database]));
        console.log(php('src/backend/tests/superadmin_recovery_email_test.php', [database]).trim());
        server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:0', '-t', 'src/frontend', 'src/backend/tests/support/recovery_email_router.php'], {env});
        server.stderr.on('data', chunk => { logs += chunk; });
        const origin = await new Promise((resolve, reject) => {
            server.on('error', reject);
            server.on('exit', code => reject(new Error('PHP server exited ' + code + ': ' + logs)));
            server.stderr.on('data', chunk => {
                const match = String(chunk).match(/http:\/\/127\.0\.0\.1:\d+/);
                if (match) resolve(match[0]);
            });
        });
        if (process.argv.includes('--serve')) {
            console.log(JSON.stringify({origin, username: 'theme_super_admin', password: data.password}));
            await new Promise(resolve => { process.once('SIGINT', resolve); process.once('SIGTERM', resolve); });
            return;
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({viewport: {width: 1280, height: 900}});
        await context.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        const page = await context.newPage();
        page.setDefaultTimeout(7000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        async function login(role) {
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        }
        const accountPath = '/components/user_manager/user_manager.php';
        await login('super_admin');
        await page.goto(origin + accountPath);
        assert(await page.locator('.open-user-drawer[data-user-id="2"]').count(), (await page.locator('body').innerText()).slice(-2500));
        await page.locator('.open-user-drawer[data-user-id="2"]').click();
        assert.equal(await page.locator('#drawerEmail').getAttribute('readonly'), null);
        await page.locator('#drawerEmail').fill('owner@example.test');
        await page.locator('#drawerEmailCurrentPassword').fill(data.password);
        await page.getByRole('button', {name: 'Send verification code', exact: true}).click();
        await page.locator('#drawerEmailCodeGroup').waitFor({state: 'visible'});
        const mail = JSON.parse(fs.readFileSync(path.join(temp, 'mail.json')));
        assert.equal(mail.email, 'owner@example.test');
        assert.doesNotMatch(mail.subject, /\d{6}/, 'Logged email subject excludes the code');
        const code = mail.message.match(/code is: (\d{6})/)[1];
        const setupToken = await page.locator('#userDrawerForm input[name="csrf_token"]').inputValue();
        const otherAccount = await context.request.post(origin + accountPath, {form: {csrf_token: setupToken, action: 'request_recovery_email', user_id: '1', email: 'other@example.test', current_password: data.password}});
        assert.equal(otherAccount.status(), 422, 'Super Administrator cannot bind another account email');
        await page.locator('#drawerEmailCode').fill('000000');
        await page.getByRole('button', {name: 'Verify and lock email', exact: true}).click();
        await page.getByText('Incorrect verification code.', {exact: false}).waitFor();
        for (const width of [1280, 390, 320]) {
            await page.setViewportSize({width, height: 844});
            await page.locator('[data-recovery-email-action="verify_recovery_email"]').scrollIntoViewIfNeeded();
            const bounds = await page.locator('#drawerRecoveryEmailSetup').evaluate(element => {
                const rect = element.getBoundingClientRect();
                return {left: rect.left, right: rect.right, width: innerWidth};
            });
            assert(bounds.left >= 0 && bounds.right <= bounds.width, 'Setup fits at ' + width + 'px');
            if (process.env.RECOVERY_EMAIL_OUTPUT) await page.screenshot({path: path.join(process.env.RECOVERY_EMAIL_OUTPUT, 'recovery-email-' + width + '.png')});
        }
        await page.locator('#drawerEmailCode').fill(code);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Verify and lock email', exact: true}).click()]);
        await page.locator('#rm-toast-stack').getByText('Recovery email verified and saved.', {exact: false}).waitFor();
        await page.locator('.open-user-drawer[data-user-id="2"]').click();
        assert.equal(await page.locator('#drawerEmail').inputValue(), 'owner@example.test');
        assert.notEqual(await page.locator('#drawerEmail').getAttribute('readonly'), null);
        assert.equal(await page.locator('#drawerRecoveryEmailSetup').isVisible(), false);
        const token = await page.locator('#userDrawerForm input[name="csrf_token"]').inputValue();
        const direct = await context.request.post(origin + accountPath, {form: {csrf_token: token, action: 'request_recovery_email', user_id: '2', email: 'replacement@example.test', current_password: data.password}});
        assert.equal(direct.status(), 422);
        assert.match((await direct.json()).error, /cannot be changed/);
        const noCsrf = await context.request.post(origin + accountPath, {form: {action: 'verify_recovery_email', user_id: '2', code}});
        assert.equal(noCsrf.status(), 403);
        // Log out before exercising the public recovery route.
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        const publicToken = await page.locator('input[name="csrf_token"]').first().inputValue();
        const reset = await context.request.post(origin + '/components/auth/forgot_password.php', {form: {csrf_token: publicToken, action: 'request_code', identity: 'owner@example.test'}, maxRedirects: 0});
        assert.equal(reset.status(), 303);
        const resetMail = JSON.parse(fs.readFileSync(path.join(temp, 'mail.json')));
        assert.equal(resetMail.email, 'owner@example.test');
        assert.match(resetMail.subject, /password reset/);
        await page.goto(origin + '/components/auth/forgot_password.php?action=cancel');
        await login('admin');
        await page.goto(origin + accountPath);
        await page.locator('.open-user-drawer[data-user-id="1"]').click();
        assert.notEqual(await page.locator('#drawerEmail').getAttribute('readonly'), null);
        assert.equal(await page.locator('#drawerRecoveryEmailSetup').count(), 0);
        const adminToken = await page.locator('#userDrawerForm input[name="csrf_token"]').inputValue();
        const adminRequest = await context.request.post(origin + accountPath, {form: {csrf_token: adminToken, action: 'request_recovery_email', user_id: '1', email: 'admin@example.test', current_password: data.password}});
        assert.equal(adminRequest.status(), 422, 'Ordinary Administrators cannot call the registration endpoint');
        assert.deepEqual(errors, []);
        console.log('Super Administrator recovery email browser: passed');
    } catch (error) { console.error(logs.slice(-4000)); throw error; }
    finally {
        await browser?.close();
        if (server) { const stopped = new Promise(resolve => server.once('exit', resolve)); server.kill(); await stopped; }
        php('src/backend/tests/support/theme_fixture.php', ['cleanup', database]);
        fs.rmSync(temp, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
