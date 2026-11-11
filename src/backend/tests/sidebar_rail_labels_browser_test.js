const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-rail-'));
const env = {...process.env, BACKUP_STORAGE_PATH: recovery};
const shots = path.resolve('output/rail-labels');
fs.mkdirSync(shots, {recursive: true});
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
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
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        for (const role of ['admin', 'super_admin', 'inventory_manager', 'cashier']) {
            const context = await browser.newContext({baseURL: origin, viewport: {width: 1440, height: 1000}});
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto('/?login=1');
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            const skipGuidance = page.getByRole('button', {name: 'Skip guidance'});
            if (await skipGuidance.count()) {
                await skipGuidance.click();
                await page.waitForTimeout(150);
            }
            await page.locator('#sidebarBrandToggle').click();
            await page.waitForTimeout(260);
            assert(await page.locator('body').evaluate(node => node.classList.contains('sidebar-collapsed')), role + ' collapsed');

            if (role === 'admin') {
                await page.goto('/components/administrator/store_settings.php');
                for (const section of ['remote-access', 'attention-thresholds', 'store-receipts']) {
                    await page.locator('.settings-sidebar a[href="?section=' + section + '"]').click();
                    await page.waitForURL('**/store_settings.php?section=' + section);
                    await page.waitForFunction(() => document.querySelector('.main-content')?.getAttribute('aria-busy') !== 'true');
                    assert.equal(await page.locator('.sidebar .dropdown.open').count(), 0,
                        'Switching Store Configuration sections must not open a collapsed sidebar flyout');
                    assert.equal(await page.locator('.sidebar .dropbtn[aria-expanded="true"]').count(), 0);
                }
                await page.reload();
                assert.equal(await page.locator('.sidebar .dropdown.open').count(), 0, 'Reload keeps collapsed flyouts closed');
                await page.screenshot({path: path.join(shots, 'admin-settings-closed.png')});
            }

            const firstLink = page.locator('.sidebar-nav a[href]').first();
            const expectedLabel = await firstLink.getAttribute('title');
            await firstLink.hover();
            await page.waitForTimeout(200);
            const tip = page.locator('#rmSidebarTip');
            assert.equal(await tip.evaluate(node => getComputedStyle(node).opacity), '1', role + ' tooltip visible');
            assert.equal((await tip.textContent()).trim(), expectedLabel, role + ' tooltip matches the link label');
            const tipLeft = await tip.evaluate(node => node.getBoundingClientRect().left);
            assert(tipLeft >= 84, role + ' tooltip clears the rail, got ' + tipLeft);
            assert.equal(await firstLink.getAttribute('title'), null, role + ' native title suppressed while the card shows');
            await page.screenshot({path: path.join(shots, role + '-label.png')});
            await page.mouse.move(700, 12);
            await page.waitForFunction(() => getComputedStyle(document.querySelector('#rmSidebarTip')).opacity === '0');
            assert.equal(await firstLink.getAttribute('title'), expectedLabel, role + ' title restored after hover');
            assert.equal(await tip.evaluate(node => getComputedStyle(node).opacity), '0', role + ' tooltip hides after hover');

            await firstLink.focus();
            await page.waitForTimeout(150);
            assert.equal(await tip.evaluate(node => getComputedStyle(node).opacity), '1', role + ' keyboard focus shows the card');
            assert.equal(await firstLink.getAttribute('title'), expectedLabel, role + ' focused link keeps its accessible name');
            await page.evaluate(() => document.activeElement.blur());
            await page.waitForTimeout(150);

            const groups = page.locator('.sidebar .dropdown .dropbtn');
            const groupCount = await groups.count();
            assert(role === 'cashier' ? groupCount === 0 : groupCount > 0, role + ' group count: ' + groupCount);
            if (groupCount) {
                const group = groups.first();
                await group.click();
                await page.waitForTimeout(150);
                const panel = page.locator('.sidebar .dropdown-content').first();
                assert(await panel.isVisible(), role + ' flyout visible');
                const box = await panel.boundingBox();
                const groupBox = await group.boundingBox();
                assert(box.x >= 84, role + ' flyout clears the rail, got ' + box.x);
                assert(Math.abs(box.y - groupBox.y) <= 10, role + ' flyout aligns to its group button');
                const labels = await panel.locator('a span:not(.sidebar-badge)').evaluateAll(nodes =>
                    nodes.filter(node => node.getClientRects().length > 0).map(node => node.textContent.trim()));
                assert(labels.length > 0, role + ' flyout shows child labels');
                const topmost = await panel.evaluate(node => {
                    const rect = node.getBoundingClientRect();
                    const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
                    return hit ? node.contains(hit) : false;
                });
                assert(topmost, role + ' flyout paints above page content');
                assert.equal(await page.locator('#rmSidebarTip').evaluate(node => getComputedStyle(node).opacity), '0', role + ' tooltip hides for flyout');
                await page.screenshot({path: path.join(shots, role + '-flyout.png')});
                await page.keyboard.press('Escape');
                await page.waitForTimeout(100);
                assert(!(await panel.isVisible()), role + ' Escape closes flyout');
                await group.click();
                await page.locator('.main-content h1').first().click();
                assert(!(await panel.isVisible()), role + ' outside click closes flyout');

                if (role === 'admin') {
                    await page.evaluate(() => {
                        window.railSnapshots = [];
                        const start = document.startViewTransition.bind(document);
                        document.startViewTransition = callback => {
                            const rail = document.querySelector('#appSidebar').getBoundingClientRect();
                            railSnapshots.push({open: document.querySelectorAll('.sidebar .dropdown.open').length, left: rail.left, width: rail.width});
                            return start(callback);
                        };
                    });
                    const railBefore = await page.locator('#appSidebar').boundingBox();
                    for (const index of [0, 1, 2]) {
                        await group.click();
                        const link = panel.locator('a[href]').nth(index);
                        const destination = await link.getAttribute('href');
                        let release;
                        const pending = new Promise(resolve => { release = resolve; });
                        await page.route(destination, async route => { await pending; await route.continue(); });
                        let closedAtStart;
                        try {
                            await link.click();
                            await page.waitForFunction(() => document.querySelector('.main-content').getAttribute('aria-busy') === 'true');
                            closedAtStart = !(await panel.isVisible());
                        } finally { release(); }
                        await page.waitForFunction(() => document.querySelector('.main-content').getAttribute('aria-busy') !== 'true');
                        await page.unroute(destination);
                        assert(closedAtStart, 'Flyout closes before loading and transition snapshots');
                        assert(!(await panel.isVisible()), 'Flyout stays closed after navigation');
                    }
                    const snapshots = await page.evaluate(() => railSnapshots);
                    assert.equal(snapshots.length, 3, 'All three page switches use the existing transition');
                    assert(snapshots.every(state => state.open === 0), 'No open flyout is captured in page-transition snapshots');
                    assert(snapshots.every(state => state.left === railBefore.x && state.width === railBefore.width), 'Rail stays in place through page transitions');
                    await page.screenshot({path: path.join(shots, 'admin-after-navigation.png'), animations: 'disabled'});
                }

                await page.setViewportSize({width: 390, height: 844});
                await page.locator('#menuToggle').click();
                await group.click();
                assert(await panel.isVisible(), role + ' mobile submenu opens');
                await page.setViewportSize({width: 1440, height: 1000});
                await page.waitForFunction(() => !document.querySelector('#appSidebar').classList.contains('open'));
                assert(!(await panel.isVisible()), role + ' mobile submenu must not become a desktop flyout on resize');
            }

            await page.locator('#sidebarBrandToggle').click();
            await page.waitForTimeout(260);
            assert(!(await page.locator('body').evaluate(node => node.classList.contains('sidebar-collapsed'))), role + ' expands');
            assert.equal(await page.locator('#rmSidebarTip').evaluate(node => getComputedStyle(node).opacity), '0', role + ' no tooltip when expanded');
            assert.equal(errors.length, 0, role + ' page errors: ' + errors.join('; '));
            await context.close();
        }
        console.log('Rail labels: PASS (4 roles)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        try { fixture('cleanup'); } catch { /* database may already be gone */ }
    }
})().catch(error => { console.error(error); process.exit(1); });
