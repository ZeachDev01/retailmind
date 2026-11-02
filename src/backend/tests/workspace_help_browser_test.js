const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Workspace Help: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-help-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
const fixture = action => {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
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
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        fs.mkdirSync('.impeccable/review', {recursive: true});
        const before = JSON.parse(fixture('inventory_state'));
        for (const role of ['admin', 'inventory_manager']) {
            await page.goto(origin + '/components/auth/logout.php');
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            const workspaceUrl = page.url();
            await page.locator('#workspace-guidance').getByRole('button', {name: 'Not now', exact: true}).click();
            await page.locator('.workspace-guidance-saved').waitFor();
            assert.equal(await page.locator('#workspace-guidance').count(), 0, 'Workspace overview disappears in place');
            assert.equal(page.url(), workspaceUrl);
            await page.goto(origin + '/components/auth/help.php');
            assert.equal(await page.locator('.workspace-guidance-links h3').count(), role === 'admin' ? 5 : 6);
            assert.equal(await page.locator('.help-workflow-details').count(), role === 'admin' ? 5 : 6);
            assert.equal(await page.locator('.help-function-icon img').count(), role === 'admin' ? 5 : 6);
            assert.equal(await page.locator('.help-task-icon img').count(), 2);
            assert.equal(await page.locator('.workspace-guidance-links > li > p, .help-setup .setup-task-desc').count(), 0, 'Task actions have no redundant subtext');
            for (const icon of await page.locator('.help-function-icon img, .help-task-icon img').all()) {
                await icon.scrollIntoViewIfNeeded();
                await icon.evaluate(image => image.decode());
                assert.equal(await icon.getAttribute('alt'), '', 'Visible labels identify decorative icons');
            }
            for (const details of await page.locator('.help-workflow-details').all()) {
                assert.equal(await details.locator('p').isVisible(), false, 'Long guidance starts collapsed');
                await details.locator('summary').focus();
                await page.keyboard.press('Enter');
                assert.equal(await details.locator('p').isVisible(), true, 'Keyboard reveals full guidance');
                assert.ok((await details.locator('p').innerText()).length > 100);
                await page.keyboard.press('Enter');
            }
            assert.equal(await page.locator('.setup-task-confirm-form').count(), 1, 'Only the permitted setup action is rendered');
            for (const theme of ['light', 'dark']) {
                for (const width of [1440, 1024, 390, 320]) {
                    await page.setViewportSize({width, height: 900});
                    await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                    await page.evaluate(() => Promise.all(document.getAnimations().map(animation => animation.finished.catch(() => {}))));
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Help fits ' + width);
                    const guide = await page.locator('.help-workflows').boundingBox();
                    const setup = await page.locator('.help-setup').boundingBox();
                    assert.ok(width >= 1024 ? setup.x >= guide.x + guide.width : setup.y >= guide.y + guide.height, 'Responsive reading layout at ' + width);
                    const contrast = await page.locator('.workspace-guidance-links a, .help-workflow-details summary, .store-setup-checklist-header h3, .setup-progress-badge, .setup-task-links a').evaluateAll(elements => {
                        const rgb = value => value.match(/[\d.]+/g).map(Number);
                        const luminance = color => rgb(color).slice(0, 3).map(v => {
                            v /= 255;
                            return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;
                        }).reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
                        return elements.map(element => {
                            let parent = element;
                            while (parent.parentElement && (rgb(getComputedStyle(parent).backgroundColor)[3] ?? 1) === 0) parent = parent.parentElement;
                            const foreground = luminance(getComputedStyle(element).color);
                            const background = luminance(getComputedStyle(parent).backgroundColor);
                            return (Math.max(foreground, background) + .05) / (Math.min(foreground, background) + .05);
                        });
                    });
                    assert.ok(contrast.every(ratio => ratio >= 4.5), 'Help text contrast in ' + theme + ': ' + Math.min(...contrast));
                    await page.keyboard.press('Tab');
                    for (const action of await page.locator('.help-setup button, .workspace-guidance-actions button, .help-heading a, .help-workflow-details summary, .workspace-guidance-links a').all()) {
                        await action.focus();
                        assert.notEqual(await action.evaluate(el => getComputedStyle(el).outlineStyle), 'none');
                        const box = await action.boundingBox();
                        assert.ok(box.x >= 0 && box.x + box.width <= width && box.height >= 44, 'Action fits and has a comfortable target');
                    }
                    await page.evaluate(() => scrollTo(0, 0));
                    await page.screenshot({fullPage: true, animations: 'disabled', path: `.impeccable/review/${role}-${theme}-${width}.png`});
                    if (width === 390) {
                        const details = page.locator('.help-workflow-details').first();
                        await details.locator('summary').click();
                        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Expanded guidance fits mobile');
                        await page.screenshot({fullPage: true, animations: 'disabled', path: `.impeccable/review/${role}-${theme}-expanded.png`});
                        await details.locator('summary').click();
                    }
                }
            }
            await page.route('**/components/auth/help.php', route => route.request().method() === 'POST'
                ? route.fulfill({status: 500, contentType: 'application/json', body: '{"saved":false}'}) : route.continue());
            await page.getByRole('button', {name: 'Not now', exact: true}).click();
            await page.getByRole('alert').filter({hasText: 'Unable to save your guidance choice'}).waitFor();
            assert.equal(await page.locator('.help-preferences').isVisible(), true, 'Failed save keeps the controls');
            assert.equal(await page.getByRole('button', {name: 'Not now', exact: true}).isEnabled(), true, 'Failed save allows retry');
            await page.unroute('**/components/auth/help.php');
            for (const [label, status] of [['Not now', 'deferred'], ['Skip guidance', 'skipped'], ['Finish overview', 'completed']]) {
                await page.goto(origin + '/components/auth/help.php');
                await page.emulateMedia({reducedMotion: status === 'completed' ? 'reduce' : 'no-preference'});
                await page.getByRole('button', {name: label, exact: true}).focus();
                await page.keyboard.press('Enter');
                const message = page.locator('.workspace-guidance-saved');
                await message.waitFor();
                assert.ok(JSON.parse(fixture('guidance_state')).some(row => row.workspace === role && row.status === status), label + ' saves the selected preference');
                assert.equal(page.url(), origin + '/components/auth/help.php', 'Choice saves without navigating');
                assert.equal(await page.locator('.help-preferences').count(), 0, label + ' removes the controls');
                assert.match(await message.innerText(), /profile menu and choose Help/);
                assert.equal(await message.evaluate(el => document.activeElement === el), true, 'Focus moves to confirmation');
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
                if (status === 'skipped') {
                    await page.setViewportSize({width: 1440, height: 900});
                    await page.screenshot({fullPage: true, animations: 'disabled', path: `.impeccable/review/${role}-guidance-saved-1440.png`});
                    await page.setViewportSize({width: 320, height: 900});
                }
                if (status === 'completed') await page.screenshot({fullPage: true, animations: 'disabled', path: `.impeccable/review/${role}-guidance-saved-320.png`});
            }
            const fallbackContext = await browser.newContext({javaScriptEnabled: false, storageState: await page.context().storageState()});
            await fallbackContext.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
            const fallback = await fallbackContext.newPage();
            await fallback.goto(origin + '/components/auth/help.php');
            await fallback.getByRole('button', {name: 'Finish overview', exact: true}).click();
            await fallback.waitForURL(workspaceUrl);
            await fallbackContext.close();
        }
        assert.deepEqual(JSON.parse(fixture('inventory_state')), before, 'Help does not change inventory');
        console.log('Workspace Help: both roles, themes, responsive layout, keyboard targets and overview actions passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
