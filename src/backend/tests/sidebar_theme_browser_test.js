const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Sidebar theme browser: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-sidebar-theme-'));
const env = {...process.env, BACKUP_STORAGE_PATH: recovery};
const screenshots = path.resolve('output/navbar-icons');
fs.mkdirSync(screenshots, {recursive: true});
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
            await page.goto('/?login=1');
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            for (const mode of ['dark', 'light', 'system']) {
                await page.locator('[data-account-menu-open]').filter({visible: true}).last().click();
                await page.locator('.sidebar-preferences-menu > summary').click();
                await page.locator('.sidebar-theme-control > summary').click();
                const saved = page.waitForResponse(response => response.url().endsWith('/auth/theme.php') && response.request().method() === 'POST');
                await page.locator('.sidebar-theme-control [data-theme-mode="' + mode + '"]').click();
                assert.equal((await saved).status(), 200);
                await page.reload();
                assert.equal(await page.locator('.sidebar-theme-control [data-theme-current]').getAttribute('data-theme-current'), mode);
                await page.waitForFunction(() => [...document.querySelectorAll('.navigation-icon, .sidebar-nav-icon')]
                    .filter(icon => icon.getClientRects().length > 0)
                    .every(icon => icon.complete && icon.naturalWidth > 0));
                assert.equal(await page.locator('.sidebar-nav a:not(:has(img)), .sidebar-nav .dropbtn:not(:has(img))').count(), 0);
                assert.equal(await page.locator('.sidebar-nav i, .sidebar-nav svg').count(), 0);
                assert(await page.locator('.navigation-icon').evaluateAll(icons => icons.every(icon =>
                    icon.getAttribute('alt') === '' && icon.getAttribute('aria-hidden') === 'true' && icon.src.endsWith('.svg'))));
                const link = await page.locator('.sidebar-nav a').first().evaluate(node => ({
                    label: node.textContent.trim(), src: node.querySelector('img').src,
                }));
                await page.locator('.global-topbar .global-search-trigger').click();
                await page.locator('#commandSearch').fill(link.label);
                const result = page.locator('.command-result').filter({hasText: link.label}).first();
                assert.equal(await result.locator('img').evaluate(icon => icon.src), link.src);
                await page.keyboard.press('Escape');
                if (role === 'admin' || role === 'cashier') {
                    await page.locator('.global-topbar .global-search-trigger').click();
                    await page.locator('#commandSearch').fill('GCash');
                    assert((await page.locator('.command-result img').first().getAttribute('src')).endsWith('/gcash-brandmark.svg'));
                    await page.keyboard.press('Escape');
                }
                if (mode !== 'system') {
                    await page.screenshot({path: path.join(screenshots, role + '-' + mode + '.png'), animations: 'disabled'});
                    if (role === 'cashier') {
                        await page.locator('[data-account-menu-open]').filter({visible: true}).last().click();
                        await page.locator('.sidebar-preferences-menu > summary').click();
                        await page.locator('.sidebar-theme-control > summary').click();
                        await page.screenshot({path: path.join(screenshots, role + '-' + mode + '-preferences.png'), animations: 'disabled'});
                        await page.keyboard.press('Escape');
                    }
                    await page.locator('#sidebarBrandToggle').click();
                    assert(await page.locator('body').evaluate(node => node.classList.contains('sidebar-collapsed')));
                    assert.equal(await page.locator('.sidebar-nav a img').first().evaluate(icon => icon.getBoundingClientRect().width), 22);
                    await page.locator('#sidebarBrandToggle').click();
                }
                const token = await page.evaluate(() => window.retailmindTheme.csrf);
                assert.equal((await context.request.post('/components/auth/theme.php', {form: {mode: 'blue', csrf_token: token}})).status(), 422);
                assert.equal((await context.request.post('/components/auth/theme.php', {form: {mode: 'light', csrf_token: 'bad'}})).status(), 403);
            }
            await page.locator('[data-account-menu-open]').filter({visible: true}).last().click();
            await page.locator('.sidebar-preferences-menu > summary').click();
            const fullscreen = page.locator('[data-fullscreen-toggle]');
            await fullscreen.click();
            await page.waitForFunction(() => document.querySelector('[data-fullscreen-toggle]').getAttribute('aria-pressed') === 'true');
            assert((await fullscreen.locator('img').getAttribute('src')).endsWith('/collapse.svg'));
            await fullscreen.click();
            await page.waitForFunction(() => document.querySelector('[data-fullscreen-toggle]').getAttribute('aria-pressed') === 'false');
            assert((await fullscreen.locator('img').getAttribute('src')).endsWith('/expand.svg'));
            await page.keyboard.press('Escape');
            await page.setViewportSize({width: 390, height: 844});
            await page.locator('#menuToggle').click();
            assert.equal(await page.locator('#menuToggle').getAttribute('aria-expanded'), 'true');
            assert((await page.locator('#menuToggle img').getAttribute('src')).endsWith('/close.svg'));
            await page.waitForFunction(() => [...document.querySelectorAll('.navigation-icon')]
                .filter(icon => icon.getClientRects().length > 0)
                .every(icon => icon.complete && icon.naturalWidth > 0));
            await page.waitForFunction(() => document.querySelector('#appSidebar').getBoundingClientRect().left >= -1);
            await page.screenshot({path: path.join(screenshots, role + '-mobile.png'), animations: 'disabled'});
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('#menuToggle').getAttribute('aria-expanded'), 'false');
            assert((await page.locator('#menuToggle img').getAttribute('src')).endsWith('/menu.svg'));
            await context.close();
        }
        console.log('Sidebar theme browser: passed (four roles, colored SVG loading and search, GCash, collapsed/mobile navigation, fullscreen, themes, invalid mode and CSRF rejection)');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
