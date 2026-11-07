// Run with RUN_DB_TESTS=1 and PHP_BINARY pointing to PHP.
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Stock issue alert browser: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-stock-alert-'));
const env = {...process.env, BACKUP_STORAGE_PATH: recovery};
const php = process.env.PHP_BINARY || 'php';
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        server = spawn(php, ['-S', '127.0.0.1:0', '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}});
        const origin = await new Promise((resolve, reject) => {
            server.on('error', reject);
            server.stderr.on('data', chunk => {
                const match = chunk.toString().match(/http:\/\/127\.0\.0\.1:\d+/);
                if (match) resolve(match[0]);
            });
        });
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({colorScheme: 'dark'});
        await page.emulateMedia({reducedMotion: 'reduce'});
        page.setDefaultTimeout(10000);
        page.setDefaultNavigationTimeout(15000);
        if (process.env.SWAL_TEST_SCRIPT) {
            await page.route('**/assets/vendor/sweetalert2/sweetalert2.all.min.js', route => route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(process.env.SWAL_TEST_SCRIPT, 'utf8')}));
        }
        if (process.env.SWAL_TEST_CSS) {
            await page.route('**/assets/vendor/sweetalert2/sweetalert2.min.css', route => route.fulfill({contentType: 'text/css', body: fs.readFileSync(process.env.SWAL_TEST_CSS, 'utf8')}));
        }
        for (const username of ['theme_admin', 'theme_super_admin']) {
            const context = await browser.newContext();
            const oversight = await context.newPage();
            await oversight.goto(origin + '/?login=1');
            await oversight.locator('#landing-login-username').fill(username);
            await oversight.locator('#landing-login-password').fill(data.password);
            await Promise.all([oversight.waitForNavigation(), oversight.getByRole('button', {name: 'Log in', exact: true}).click()]);
            if (username === 'theme_super_admin') {
                await oversight.goto(origin + '/components/auth/workspace.php');
                await oversight.locator('form.workspace-option').filter({has: oversight.locator('input[name="workspace"][value="admin"]')})
                    .getByRole('button', {name: 'Open', exact: true}).click();
                await oversight.waitForURL('**/administrator/dashboard.php');
            }
            for (const sidebar of [false, true]) {
                if (sidebar) {
                    await oversight.goto(origin + '/components/administrator/dashboard.php');
                    await oversight.getByRole('link', {name: 'Stock Issue Oversight', exact: true}).click();
                    await oversight.waitForURL('**/administrator/stock_issues.php');
                } else await oversight.goto(origin + '/components/administrator/stock_issues.php');
                assert.equal(await oversight.getByRole('alert').count(), 0,
                    `${username}: authorized navigation must not show an access warning: ${await oversight.locator('main').innerText()}`);
                await oversight.getByRole('table', {name: 'Stock issue reports', exact: true}).getByText('Theme Test Item', {exact: true}).waitFor();
                await oversight.getByLabel('Product', {exact: true}).fill('THEME-ITEM');
                await oversight.getByLabel('Status', {exact: true}).selectOption('pending');
                await oversight.getByRole('button', {name: 'Apply filters', exact: true}).click();
                await oversight.waitForURL('**/stock_issues.php?*product=THEME-ITEM*');
                await oversight.getByRole('table', {name: 'Stock issue reports', exact: true}).getByText('Theme Test Item', {exact: true}).waitFor();
                assert.equal(await oversight.getByRole('alert').count(), 0, `${username}: filters remain authorized`);
                await oversight.getByRole('link', {name: 'Open report #1', exact: true}).click();
                await oversight.waitForURL('**/stock_issues.php?*detail=1');
                await oversight.locator('.detail-reason-box').filter({hasText: 'Disposable theme Stock Issue'}).waitFor();
                assert.equal(await oversight.getByRole('alert').count(), 0, `${username}: report details remain authorized`);
                assert.equal(await oversight.locator('.history-section form[method="POST" i]').count(), 0, 'Administrator oversight stays read-only');
                assert.equal(new URL(oversight.url()).searchParams.has('error'), false, 'Report navigation carries no stale error feedback');
            }
            const denied = await oversight.request.post(origin + '/components/administrator/stock_issues.php', {form: {action: 'approve', adjustment_id: '1'}});
            assert.equal(denied.status(), 403, 'Administrator POSTs remain forbidden');
            await context.close();
        }
        await page.goto(origin + '/?login=1');
        await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        for (const partial of [false, true]) {
            const destination = origin + '/components/administrator/stock_issues.php?error=' + encodeURIComponent('Feedback presentation test.');
            if (partial) await page.evaluate(url => RetailMindNavigation.navigate(url), destination);
            else await page.goto(destination);
            await page.locator('.rm-toast.error').waitFor();
            assert.ok((await page.getByRole('alert').innerText()).includes('Feedback presentation test.'), 'Unrelated URL feedback remains visible');
            assert.equal(await page.locator('.swal2-container').count(), 0, 'URL feedback uses a nonblocking toast');
            await page.locator('.rm-toast.error .rm-toast-close').click();
            await page.locator('.rm-toast.error').waitFor({state: 'detached'});
            await page.goto(origin + '/components/administrator/dashboard.php');
            await page.addStyleTag({content: '@keyframes delayed-alert-close { from { opacity: 0; } to { opacity: 0; } } .swal2-hide { animation: delayed-alert-close 60s !important; }'});
            for (const kind of ['success', 'info', 'warning', 'error']) {
                await page.evaluate(kind => { RetailMindNavigation.initializePage(() => { RetailMindUI.alert('Check page interaction', kind); }); }, kind);
                await page.getByRole('button', {name: 'OK', exact: true}).click();
                await page.locator('.swal2-container').waitFor({state: 'detached', timeout: 5000});
                assert.equal(await page.locator('body').evaluate(node => node.classList.contains('swal2-shown')), false, 'Dismissing every alert releases the page');
                await page.locator('.global-topbar [data-command-open]').click();
                assert.equal(await page.locator('#commandPalette').evaluate(node => node.classList.contains('open')), true, 'Page controls still respond after OK');
                await page.keyboard.press('Escape');
            }
        }
        await page.evaluate(() => RetailMindNavigation.initializePage(() => { RetailMindUI.alert('Old page error', 'error'); }));
        await page.getByRole('heading', {name: 'Unable to continue'}).waitFor();
        await page.evaluate(() => RetailMindNavigation.navigate('/components/administrator/stock_issues.php'));
        await page.locator('.swal2-container').waitFor({state: 'detached', timeout: 5000});
        assert.equal(await page.locator('.swal2-container').count(), 0, 'Navigation removes alerts before their page handlers are discarded');
        await page.evaluate(() => { RetailMindUI.queueAlert('New page error', 'error'); });
        await page.locator('.rm-toast.error .rm-toast-close').click();
        await page.locator('.rm-toast.error').waitFor({state: 'detached', timeout: 5000});
        for (const partial of [false, true]) {
            await page.setViewportSize(partial ? {width: 390, height: 844} : {width: 1280, height: 800});
            const destination = origin + '/components/administrator/stock_issues.php?detail=2147483647';
            if (partial) await page.evaluate(url => RetailMindNavigation.navigate(url), destination);
            else await page.goto(destination);
            await page.locator('.message.error').waitFor();
            assert.ok((await page.locator('.message.error').textContent()).includes('was not found'), 'Actual stock issue error appears in inline banner');
            assert.equal(await page.locator('.swal2-container').count(), 0, 'No blocking modal is raised on read-only oversight page');
            await page.goto(origin + '/components/administrator/dashboard.php');
        }
        fixture('stock_issue_loading_failure');
        try {
            await page.goto(origin + '/components/administrator/stock_issues.php');
            await page.getByRole('alert').filter({hasText: 'Stock issue reports could not be loaded.'}).waitFor();
        } finally {
            fixture('stock_issue_loading_allow');
        }
        await page.goto(origin + '/components/administrator/dashboard.php');
        await page.evaluate(() => {
            const dialog = document.createElement('dialog');
            dialog.id = 'alert-parent-dialog';
            dialog.innerHTML = '<p>Open modal</p>';
            document.body.append(dialog);
            dialog.showModal();
            RetailMindUI.alert('Error while a modal is open', 'error');
        });
        await page.getByRole('button', {name: 'OK', exact: true}).click({timeout: 3000});
        await page.locator('.swal2-container').waitFor({state: 'detached', timeout: 3000});
        assert.equal(await page.locator('#alert-parent-dialog').evaluate(node => node.open), true, 'Dismissing alert preserves the underlying modal');
        await page.evaluate(() => { RetailMindUI.alert('Another modal error', 'error'); });
        await page.locator('.swal2-close').click({timeout: 3000});
        await page.locator('.swal2-container').waitFor({state: 'detached', timeout: 3000});
        console.log('Stock issue alert browser: passed (full page, sidebar navigation, OK and close inside a native modal)');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
