const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Dormancy policy browser: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-dormancy-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const fixture = action => {
    const result = spawnSync('php', ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend'], {
            env: {...env, DB_NAME: database}, stdio: ['ignore', 'ignore', 'pipe'],
        });
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        const origin = 'http://127.0.0.1:' + port;
        for (let i = 0; i < 100; i++) {
            if (server.exitCode !== null) throw new Error(logs);
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (i === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_super_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const url = origin + '/components/system_administrator/system_settings.php?section=dormancy-policy';
        await page.goto(url);
        assert.equal(await page.locator('#disable_days').inputValue(), '45');
        const warning = page.getByLabel('Warn before disable (days)');
        assert.equal(await warning.inputValue(), '15', 'Existing 45/30 policy displays a 15-day lead time');
        await page.locator('#disable_days').fill('30');
        assert.equal(await warning.getAttribute('max'), '29');
        for (const invalid of ['0', '30', '1.5']) {
            await warning.fill(invalid);
            assert.equal(await warning.evaluate(input => input.checkValidity()), false);
        }
        await warning.fill('3');
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Save policy', exact: true}).click()]);
        assert.match(await page.locator('body').innerText(), /Dormancy Policy thresholds saved/);
        await page.reload();
        assert.equal(await warning.inputValue(), '3', 'Saved lead time survives reload');
        for (const theme of ['light', 'dark']) {
            for (const width of [390, 768, 1440]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                const section = page.locator('[data-platform-section="dormancy-policy"]');
                assert.ok(await section.evaluate(element => element.getBoundingClientRect().right <= innerWidth), 'Policy stays inside viewport');
                const help = page.locator('#dormancy-warning-help');
                const trigger = page.getByRole('button', {name: 'About warning before disable'});
                await page.locator('#disable_days').focus();
                await page.mouse.move(0, 0);
                assert.equal(await help.isVisible(), false, 'Description stays hidden until requested');
                await trigger.focus();
                assert.equal(await help.isVisible(), true, 'Keyboard focus opens tooltip');
                await trigger.press('Escape');
                assert.equal(await help.isVisible(), false, 'Escape dismisses tooltip');
                await trigger.click();
                assert.equal(await help.isVisible(), true, 'Click or tap opens tooltip');
                assert.ok(await help.evaluate(element => { const rect = element.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth; }), 'Tooltip stays inside viewport');
                await section.screenshot({path: path.join(output, `${theme}-${width}.png`)});
            }
        }
        // Direct submission also enforces the range when browser validation is bypassed.
        const token = await page.locator('[data-platform-section="dormancy-policy"] [name="csrf_token"]').inputValue();
        const rejected = await page.request.post(url, {form: {csrf_token: token, action: 'save_dormancy', disable_days: '30', warn_before_days: '0'}});
        assert.match(await rejected.text(), /Warn before disable must be at least 1 day/);
        await page.reload();
        assert.equal(await warning.inputValue(), '3', 'Rejected save leaves policy unchanged');
        const noScript = await browser.newContext({javaScriptEnabled: false, storageState: await page.context().storageState()});
        const plainPage = await noScript.newPage();
        await plainPage.goto(url);
        for (const [disable, lead] of [['60', '50'], ['30', '3']]) {
            await plainPage.locator('#disable_days').fill(disable);
            await plainPage.locator('#warn_before_days').fill(lead);
            await Promise.all([plainPage.waitForNavigation(), plainPage.getByRole('button', {name: 'Save policy', exact: true}).click()]);
            assert.match(await plainPage.locator('body').innerText(), /Dormancy Policy thresholds saved/);
            assert.equal(await plainPage.locator('#warn_before_days').inputValue(), lead);
        }
        await noScript.close();
        if (process.env.TESTSPRITE_TEST_ID) {
            const result = spawnSync('testsprite.cmd', ['test', 'run', process.env.TESTSPRITE_TEST_ID, '--local', String(port), '--timeout', '600', '--output', 'json'], {stdio: 'inherit', shell: true});
            assert.equal(result.status, 0, 'TestSprite must pass');
        }
        console.log('Dormancy policy browser: passed. Screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.exitCode !== null ? resolve() : server.once('exit', resolve)); }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exit(1); });
