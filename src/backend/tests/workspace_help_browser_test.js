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
        const before = JSON.parse(fixture('inventory_state'));
        for (const role of ['admin', 'inventory_manager']) {
            await page.goto(origin + '/components/auth/logout.php');
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            const workspaceUrl = page.url();
            const csrf = await page.locator('#workspace-guidance input[name="csrf_token"]').first().inputValue();
            await page.locator('#workspace-guidance').getByRole('button', {name: 'Not now', exact: true}).click();
            await page.locator('.workspace-guidance-saved').waitFor();
            assert.equal(await page.locator('#workspace-guidance').count(), 0, 'Workspace overview disappears in place');
            assert.equal(page.url(), workspaceUrl);
            await page.goto(origin + '/components/auth/help.php');
            assert.equal(await page.locator('.workspace-guidance-links, .help-setup, .help-preferences').count(), 0, 'Legacy Help sections are removed');
            assert.ok(await page.locator('.walkthrough-topic-card').count() > 0, 'Current walkthroughs remain available');
            if (role === 'inventory_manager') {
                assert.deepEqual(await page.locator('.walkthrough-topic-icon img').evaluateAll(images => images.map(img => img.src.split('/').pop())), ['workflow.svg', 'package.svg', 'shipped.svg'], 'Topics use their own function icons');
            }
            for (const theme of ['light', 'dark']) {
                for (const width of [1440, 1024, 390, 320]) {
                    await page.setViewportSize({width, height: 900});
                    await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                    await page.evaluate(() => Promise.all(document.getAnimations().map(animation => animation.finished.catch(() => {}))));
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Help fits ' + width);
                    for (const card of await page.locator('.walkthrough-topic-card').all()) {
                        assert.ok((await card.boundingBox()).height < 300, 'Topic rows do not stretch on mobile');
                    }
                    await page.keyboard.press('Tab');
                    for (const action of await page.locator('.help-heading a, .walkthrough-topic-actions a').all()) {
                        await action.focus();
                        assert.notEqual(await action.evaluate(el => getComputedStyle(el).outlineStyle), 'none');
                        if (await action.evaluate(el => el.classList.contains('btn-primary'))) {
                            const contrast = await action.evaluate(el => {
                                const luminance = color => {
                                    const [r, g, b] = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => {
                                        v /= 255;
                                        return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;
                                    });
                                    return .2126 * r + .7152 * g + .0722 * b;
                                };
                                const style = getComputedStyle(el);
                                const values = [luminance(style.color), luminance(style.backgroundColor)].sort((a, b) => a - b);
                                return (values[1] + .05) / (values[0] + .05);
                            });
                            assert.ok(contrast >= 4.5, 'Walkthrough button text remains readable in ' + theme);
                        }
                        const box = await action.boundingBox();
                        assert.ok(box.x >= 0 && box.x + box.width <= width && box.height >= 44, 'Action fits and has a comfortable target');
                    }
                    await page.evaluate(() => scrollTo(0, 0));
                    await page.screenshot({fullPage: true, animations: 'disabled', path: path.join(directory, `${role}-${theme}-${width}.png`)});
                }
            }
            const firstTopic = page.locator('.walkthrough-topic-card').first();
            const topic = new URL(await firstTopic.locator('a').getAttribute('href'), origin).searchParams.get('guidance');
            for (const [action, label] of [['start', 'Continue'], ['complete', 'Replay']]) {
                const response = await page.request.post(origin + '/components/auth/help.php', {
                    headers: {Accept: 'application/json'},
                    form: {csrf_token: csrf, walkthrough_action: action, walkthrough_topic: topic, walkthrough_step: '0'},
                });
                assert.equal(response.status(), 200, await response.text());
                await page.goto(origin + '/components/auth/help.php');
                assert.equal(await firstTopic.getByRole('link', {name: label, exact: true}).count(), 1);
                assert.equal(await page.locator('.guidance-continue-card').count(), action === 'start' ? 1 : 0);
                for (const width of [1440, 320]) {
                    await page.setViewportSize({width, height: 900});
                    for (const theme of ['light', 'dark']) {
                        await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), label + ' state fits');
                        const badge = firstTopic.locator('.guidance-progress-badge, .guidance-completion-badge');
                        const box = await badge.boundingBox();
                        assert.ok(box.x >= 0 && box.x + box.width <= width, label + ' badge fits');
                        await page.screenshot({fullPage: true, animations: 'disabled', path: path.join(directory, `${role}-${theme}-${width}-${action}.png`)});
                    }
                }
            }
        }
        assert.deepEqual(JSON.parse(fixture('inventory_state')), before, 'Help does not change inventory');
        console.log('Workspace Help: both roles, legacy sections removed, walkthroughs, themes, responsive layout and keyboard targets passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
