// Run with: PHP_BINARY=C:/xampp/php/php.exe node src/backend/tests/theme_navigation_browser_test.js
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');

(async () => {
    const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-theme-navigation-'));
    const themePhp = path.resolve(__dirname, '../includes/theme.php').replaceAll('\\', '/');
    const heads = {};
    for (const [name, user, mode] of [['account', 1, 'light'], ['other', 2, 'light'], ['guest', 0, 'system']]) {
        const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
            function app_url($path) { return '/' . $path; }
            require ${JSON.stringify(themePhp)};
            App\\Core\\Session::start();
            $_SESSION = ${user ? `['user_id' => ${user}, 'theme_preference' => '${mode}']` : '[]'};
            retailmind_theme_head();
        `], { env: { ...process.env, BACKUP_STORAGE_PATH: recovery }, encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        assert.ok(result.stdout.includes('root.dataset.theme'), result.stderr || result.stdout);
        assert.ok(result.stdout.indexOf('root.dataset.theme') < result.stdout.indexOf('<script src='), 'Inline theme precedes external assets');
        heads[name] = result.stdout;
    }
    const saves = [];
    const server = http.createServer((request, response) => {
        response.setHeader('Cache-Control', 'no-store');
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/components/auth/theme.php') {
            let body = '';
            request.on('data', chunk => body += chunk);
            request.on('end', () => {
                saves.push(new URLSearchParams(body).get('mode'));
                response.setHeader('Content-Type', 'application/json');
                setTimeout(() => response.end('{"success":true}'), 300);
            });
        } else if (/\.(js|css)$/.test(url.pathname)) {
            response.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css');
            response.end(fs.readFileSync(path.join(__dirname, '../../frontend', url.pathname)));
        } else if (url.pathname.startsWith('/assets/')) {
            response.end();
        } else {
            response.setHeader('Content-Type', 'text/html');
            response.end(`<!doctype html><html><head>${heads[url.searchParams.get('account') || 'account']}
                <link rel="stylesheet" href="/assets/css/style.css"></head><body>
                <div data-sidebar-theme-target></div><main class="main-content">${url.pathname}</main></body></html>`);
        }
    });
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = 'http://127.0.0.1:' + server.address().port;
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({ colorScheme: 'light' });
        const page = await context.newPage();
        await page.goto(origin);
        await page.evaluate(() => {
            localStorage.setItem('retailmind.theme-user', '1');
            localStorage.setItem('retailmind.theme', 'dark');
        });
        // Hold the external script to prove the inline head alone sets the theme.
        let release;
        const blocked = new Promise(resolve => release = resolve);
        await page.route('**/assets/js/theme.js*', async route => { await blocked; await route.continue(); });
        const navigation = page.reload();
        await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark' && !document.body);
        assert.equal(await page.evaluate(() => document.documentElement.style.backgroundColor), 'rgb(11, 18, 32)');
        release();
        await navigation;
        await page.unroute('**/assets/js/theme.js*');
        for (const mode of ['dark', 'light']) {
            await page.locator('[data-theme-current]').click();
            await page.locator(`[data-theme-mode="${mode}"]`).click();
            assert.equal(await page.evaluate(() => localStorage.getItem('retailmind.theme')), mode, 'Selection persists immediately');
            for (const destination of ['dashboard', 'products', 'user-manager']) {
                await page.goto(origin + '/' + destination);
                assert.equal(await page.getAttribute('html', 'data-theme'), mode, 'Stale server preference cannot reset a local selection');
            }
            await page.reload();
            assert.equal(await page.getAttribute('html', 'data-theme'), mode);
        }
        await page.evaluate(() => {
            document.querySelector('main').innerHTML = '<img data-brand-base="/brand/" data-brand-variant="logos/STYLE/logo-header.png"><span data-theme-current></span><button data-theme-mode="light"></button>';
            window.dispatchEvent(new Event('retailmind:pagechange'));
        });
        assert.ok((await page.locator('main img').getAttribute('src')).includes('/light/'), 'PJAX assets follow active theme');
        assert.equal(await page.locator('main [data-theme-current]').getAttribute('aria-label'), 'Appearance: light', 'Incomplete incoming control is safe');
        assert.equal(await page.locator('main button').getAttribute('aria-pressed'), 'true');
        const second = await context.newPage();
        await second.goto(origin);
        await page.locator('.theme-trigger').click();
        await page.locator('.theme-options [data-theme-mode="dark"]').click();
        await second.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
        await second.goto(origin + '/?account=other');
        assert.equal(await second.getAttribute('html', 'data-theme'), 'light', 'Different account starts with its own server preference');
        await second.goto(origin + '/?account=guest');
        await second.locator('.theme-trigger').click();
        await second.locator('[data-theme-mode="dark"]').click();
        await second.reload();
        assert.equal(await second.getAttribute('html', 'data-theme'), 'dark', 'Guest choice persists');
        await second.goto(origin);
        assert.equal(await second.getAttribute('html', 'data-theme'), 'light', 'Guest choice cannot overwrite account preference');
        const privateContext = await browser.newContext({ colorScheme: 'dark' });
        await privateContext.addInitScript(() => {
            Object.defineProperty(window, 'localStorage', { get() { throw new Error('Storage blocked'); } });
        });
        const privatePage = await privateContext.newPage();
        await privatePage.goto(origin);
        assert.equal(await privatePage.getAttribute('html', 'data-theme'), 'light', 'Blocked storage falls back to account preference');
        await privatePage.goto(origin + '/?account=guest');
        assert.equal(await privatePage.getAttribute('html', 'data-theme'), 'dark', 'Blocked guest storage follows device');
        await privatePage.emulateMedia({ colorScheme: 'light' });
        await privatePage.waitForFunction(() => document.documentElement.dataset.theme === 'light');
        assert.ok(saves.includes('dark') && saves.includes('light'), 'Account choices still POST to server');
        console.log('Theme navigation browser: passed (early head, navigation, reload, PJAX assets, tabs, account isolation, blocked storage, system mode)');
    } finally {
        await browser?.close();
        await new Promise(resolve => server.close(resolve));
        fs.rmSync(recovery, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
