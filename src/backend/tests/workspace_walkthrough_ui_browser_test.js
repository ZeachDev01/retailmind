const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Walkthrough UI: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-walkthrough-ui-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};

const fixture = (action, extra) => {
    const args = ['src/backend/tests/support/theme_fixture.php', action, database];
    if (extra) args.push(extra);
    const result = spawnSync(process.env.PHP_BINARY || 'php', args, {env, encoding: 'utf8'});
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

        server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {
            env: {...env, RM_THEME_TEST_DATABASE: database},
            stdio: 'ignore'
        });

        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));

        const catalogResult = spawnSync(process.env.PHP_BINARY || 'php', ['-r', "require 'src/backend/app/Guidance/WorkspaceWalkthroughService.php'; echo json_encode(array_map(fn($role) => App\\Guidance\\WorkspaceWalkthroughService::paths($role), ['admin', 'inventory_manager']));"], {encoding: 'utf8'});
        assert.equal(catalogResult.status, 0, catalogResult.stderr);
        const catalogs = JSON.parse(catalogResult.stdout);
        const initialInventory = JSON.parse(fixture('inventory_state'));
        const initialSetup = JSON.parse(fixture('setup_state'));
        const initialSettings = JSON.parse(fixture('store_settings_state'));
        const failures = [];
        page.on('pageerror', error => failures.push('JavaScript: ' + error.message));
        const screenshots = path.join(process.cwd(), 'tmp/manual/walkthrough-ui');
        fs.mkdirSync(screenshots, {recursive: true});
        for (const [roleIndex, role] of ['admin', 'inventory_manager'].entries()) {
            await page.goto(origin + '/components/auth/logout.php');
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            await page.goto(origin + '/components/auth/help.php');
            const links = await page.locator('.walkthrough-topic-actions a[href*="guidance="]').evaluateAll(nodes => nodes.map(n => n.href));
            assert.equal(links.length, Object.values(catalogs[roleIndex]).flatMap(p => Object.values(p.topics)).length);
            for (const theme of ['light', 'dark']) {
                for (const width of [1440, 1024, 390, 320]) {
                    await page.setViewportSize({width, height: 900});
                    for (const link of links) {
                        const topicId = new URL(link).searchParams.get('guidance');
                        const topic = Object.values(catalogs[roleIndex]).flatMap(p => Object.values(p.topics)).find(t => t.id === topicId);
                        await page.goto(link + '&replay=1');
                        await page.locator('#guidance-dock').waitFor();
                        await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                        for (let index = 0; index < topic.steps.length; index++) {
                            await page.waitForTimeout(200);
                            for (const notice of await page.locator('.rm-toast-close').all()) await notice.click();
                            assert.equal(await page.locator('.guidance-step-title').innerText(), topic.steps[index].action);
                            const issues = await page.evaluate(({selector}) => {
                                const problems = [];
                                const dock = document.querySelector('#guidance-dock');
                                const target = document.querySelector('.guidance-spotlight-target') || document.querySelector(selector);
                                if (!document.querySelector('#guidance-completion-status').hidden) problems.push('completion shown before finishing');
                                const box = dock.getBoundingClientRect();
                                if (box.left < 0 || box.right > innerWidth || box.bottom > innerHeight || box.top < 0) problems.push('dock outside viewport');
                                if (dock.scrollWidth > dock.clientWidth) problems.push('dock text overflows');
                                const contentBottom = dock.querySelector('.guidance-dock-body').getBoundingClientRect().bottom;
                                const buttonTop = dock.querySelector('.guidance-dock-footer button').getBoundingClientRect().top;
                                if (buttonTop - contentBottom > parseFloat(getComputedStyle(dock).rowGap) + 1) problems.push('extra blank space before Back/Next');
                                const luminance = color => {
                                    const [r, g, b] = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => {
                                        v /= 255;
                                        return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;
                                    });
                                    return .2126 * r + .7152 * g + .0722 * b;
                                };
                                for (const text of dock.querySelectorAll('.guidance-path-badge, .guidance-step-desc, .guidance-topic-checklist summary')) {
                                    let background = text;
                                    while (getComputedStyle(background).backgroundColor === 'rgba(0, 0, 0, 0)') background = background.parentElement;
                                    const values = [luminance(getComputedStyle(text).color), luminance(getComputedStyle(background).backgroundColor)].sort((a, b) => a - b);
                                    if ((values[1] + .05) / (values[0] + .05) < 4.5) problems.push('low text contrast: ' + text.className);
                                }
                                if (!target || !target.getClientRects().length || getComputedStyle(target).visibility === 'hidden') problems.push('target hidden/missing: ' + selector);
                                else {
                                    const rect = target.getBoundingClientRect();
                                    const x = Math.max(0, Math.min(innerWidth - 1, rect.left + Math.min(rect.width / 2, 30)));
                                    const y = Math.max(0, Math.min(innerHeight - 1, rect.top + Math.min(rect.height / 2, 30)));
                                    const top = document.elementFromPoint(x, y);
                                    if (rect.bottom < 0 || rect.top >= innerHeight || !top || !(top === target || target.contains(top))) problems.push('target covered/offscreen: ' + selector);
                                }
                                for (const button of dock.querySelectorAll('button:not(:disabled)')) {
                                    if (!button.getClientRects().length) continue;
                                    const r = button.getBoundingClientRect();
                                    const top = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
                                    if (!button.contains(top)) problems.push('button covered: ' + button.textContent);
                                }
                                return problems;
                            }, {selector: topic.steps[index].target});
                            for (const issue of issues) failures.push(`${role}/${topicId}/${index + 1}/${theme}/${width}: ${issue}`);
                            if (issues.length || (width === 1440 && index === 0) || (width === 390 && ['products', 'stock_receiving', 'dashboard'].includes(topicId))) {
                                await page.screenshot({path: path.join(screenshots, `${role}-${topicId}-${index + 1}-${theme}-${width}.png`)});
                            }
                            if (index === 1) {
                                await page.locator('#guidance-btn-back').click();
                                assert.equal(await page.locator('.guidance-step-title').innerText(), topic.steps[0].action);
                                await page.locator('#guidance-btn-next').click();
                            }
                            if (topicId === 'products' && index === 3) {
                                await page.locator('#wizard-back').click({timeout: 3000});
                                assert.equal(await page.locator('.wizard-panel.active').getAttribute('data-panel'), '2', 'Product wizard state follows the guide');
                                await page.locator('#guidance-btn-back').click();
                                await page.locator('#guidance-btn-next').click();
                            }
                            await page.locator('#guidance-btn-next').click({timeout: 3000});
                        }
                        await page.locator('#guidance-completion-status').waitFor({state: 'visible'});
                        await page.locator('#guidance-btn-done').click();
                        assert.equal(await page.locator('#guidance-dock, .guidance-spotlight-target').count(), 0);
                        assert.equal(await page.locator('body').evaluate(el => el.classList.contains('has-guidance-dock')), false);
                        if (topicId === 'stock_receiving') assert.equal(await page.locator('.receiving-summary').evaluate(el => el.hidden), true);
                        if (topicId === 'products') assert.equal(await page.locator('#add-product-modal').evaluate(el => el.classList.contains('open')), false);
                        if (role === 'admin' && topicId === 'store_settings' && theme === 'light' && width === 1440) {
                            // Finishing consumes the ?guidance=&replay= link: refresh must stay closed.
                            assert.equal(new URL(page.url()).searchParams.has('replay'), false, 'Finishing guidance releases its replay link');
                            await page.reload();
                            await page.waitForLoadState('load');
                            assert.equal(await page.locator('#guidance-dock').count(), 0, 'Finished guidance stays closed after refresh');
                        }
                    }
                }
            }
            const keyboardTopic = role === 'admin' ? 'store_settings' : 'products';
            await page.goto(links.find(link => new URL(link).searchParams.get('guidance') === keyboardTopic) + '&replay=1');
            await page.emulateMedia({reducedMotion: 'reduce'});
            assert.equal(await page.locator('.guidance-spotlight-target').evaluate(el => getComputedStyle(el).animationName), 'none');
            await page.locator('.guidance-learn-more summary').click();
            assert.equal(await page.locator('.guidance-learn-more p').isVisible(), true);
            await page.locator('.guidance-learn-more summary').click();
            await page.locator('.guidance-topic-checklist summary').click();
            assert.equal(await page.locator('.guidance-checklist-items').isVisible(), true);
            await page.locator('.guidance-topic-checklist summary').click();
            await page.locator('#guidance-btn-next').focus();
            await page.keyboard.press('Enter');
            assert.equal(await page.locator('.guidance-step-title').evaluate(el => el === document.activeElement), true);
            if (role === 'inventory_manager') {
                await page.locator('#guidance-btn-next').click();
                await page.locator('#guidance-btn-next').focus();
                await page.keyboard.press('Tab');
                assert.equal(await page.locator('#add-product-modal').evaluate(el => el.contains(document.activeElement)), true, 'Modal focus includes the guide');
            }
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('#guidance-dock, .guidance-spotlight-target').count(), 0);
            assert.equal(await page.locator('body').evaluate(el => el.classList.contains('no-scroll') || el.classList.contains('has-guidance-dock')), false);
        }
        assert.deepEqual(JSON.parse(fixture('inventory_state')), initialInventory, 'Walkthroughs leave inventory unchanged');
        assert.deepEqual(JSON.parse(fixture('setup_state')), initialSetup, 'Walkthroughs do not confirm setup');
        assert.deepEqual(JSON.parse(fixture('store_settings_state')), initialSettings, 'Walkthroughs leave Store settings unchanged');
        console.log(failures.join('\n'));
        assert.equal(failures.length, 0, 'Every walkthrough step must remain visible and usable');
        console.log('Walkthrough UI: all topics and steps passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
