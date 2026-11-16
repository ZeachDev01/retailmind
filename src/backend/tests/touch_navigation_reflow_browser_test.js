const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Touch navigation: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-touch-nav-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

const VIEWPORTS = [
    {width: 640, height: 360},
    {width: 800, height: 480},
    {width: 900, height: 600},
    {width: 901, height: 600},
    {width: 960, height: 600},
    {width: 1024, height: 768},
    {width: 1280, height: 800},
    {width: 1366, height: 768},
];

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('reports');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 80));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});

        for (const role of ['admin', 'inventory_manager', 'cashier']) {
            const context = await browser.newContext({viewport: {width: 1280, height: 800}, hasTouch: true});
            const page = await context.newPage();
            page.setDefaultTimeout(5000);
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            const skip = page.getByRole('button', {name: 'Skip guidance'});
            if (await skip.isVisible()) await skip.click();
            await page.goto(origin + '/components/' + (role === 'admin' ? 'administrator/dashboard.php' : role === 'inventory_manager' ? 'inventory_management/inventory_overview.php' : 'cashier/dashboard.php'));
            for (const viewport of VIEWPORTS) {
                await page.setViewportSize(viewport);
                await page.waitForTimeout(300);
                const label = role + ' ' + viewport.width + 'x' + viewport.height;
                const overflow = await page.evaluate(() => ({width: innerWidth, content: document.documentElement.scrollWidth}));
                assert(overflow.content <= overflow.width + 1, label + ': no page horizontal overflow ' + JSON.stringify(overflow));
                console.log(label + ' geometry ' + JSON.stringify(overflow));
                if (viewport.width <= 900) {
                    await page.locator('#menuToggle').tap();
                    await page.waitForFunction(() => document.querySelector('#appSidebar').classList.contains('open'));
                    for (const group of await page.locator('#appSidebar .dropbtn').all()) {
                        if (await group.getAttribute('aria-expanded') !== 'true') await group.tap();
                        for (const link of await group.locator('..').locator('a[href]').all()) {
                            await link.scrollIntoViewIfNeeded();
                            const box = await link.boundingBox();
                            assert(box.y >= 57 && box.y + box.height <= viewport.height + 1, label + ': destination reachable below top bar ' + (await link.textContent()) + ' ' + JSON.stringify(box));
                            assert(box.height >= 44, label + ': destination has a touch target');
                        }
                    }
                    // Keyboard users must be able to reach every visible destination without losing the drawer.
                    const last = page.locator('#sidebarProfile');
                    await last.focus();
                    await page.keyboard.press('Tab');
                    assert(await page.locator('#appSidebar').evaluate(el => el.contains(document.activeElement)) ||
                        await page.locator('#menuToggle').evaluate(el => el === document.activeElement), label + ': focus stays with open navigation');
                    await page.keyboard.press('Escape');
                    assert.equal(await page.locator('#menuToggle').evaluate(el => el === document.activeElement), true, label + ': Escape restores focus');
                    await page.locator('.admin-mobile-avatar').tap();
                    await page.locator('.sidebar-workspace-switcher summary').tap();
                    const workspace = await page.locator('.sidebar-workspace-options').boundingBox();
                    assert(workspace.y >= 0 && workspace.y + workspace.height <= viewport.height + 1, label + ': Workspace options fit');
                    await page.keyboard.press('Escape');
                }
                await page.screenshot({path: path.join(output, role + '-' + viewport.width + '.png')});
            }
            // Search is an existing public input; resizing must preserve entered text.
            await page.locator('.global-topbar .global-search-trigger').click();
            await page.locator('#commandSearch').fill('Retained 466');
            for (const viewport of [{width: 640, height: 360}, {width: 1366, height: 768}]) {
                await page.setViewportSize(viewport);
                assert.equal(await page.locator('#commandSearch').inputValue(), 'Retained 466');
            }
            await page.keyboard.press('Escape');
            await context.close();
        }
        console.log('Touch navigation supplemental Chromium checks passed; artifacts ' + output);
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        try { fixture('cleanup'); } catch { /* database may already be gone */ }
    }
})().catch(error => { console.error(error); process.exit(1); });
