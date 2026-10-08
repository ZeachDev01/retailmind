const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const {chromium} = require('playwright');
const ui = fs.readFileSync('src/frontend/assets/js/ui.js', 'utf8');
const sidebar = fs.readFileSync('src/frontend/components/sidebar.php', 'utf8');
const drawer = sidebar.slice(sidebar.lastIndexOf('<script>') + 8, sidebar.lastIndexOf('</script>'));
(async () => {
    const server = http.createServer((req, res) => {
        res.setHeader('Content-Type', 'text/html');
        res.end(`<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1">
            <button id="menuToggle">Menu</button><div id="sidebarOverlay"></div>
            <nav id="appSidebar"><a id="main" href="${req.url === '/products' ? '/reports' : '/products'}">Products</a></nav>
            <button data-account-menu-open>Account</button>
            <nav id="sidebarAccountMenu"><a id="profile" href="/profile">User</a>
                <button data-fullscreen-toggle><i></i><span>Full Screen</span></button>
            </nav><script>${ui}</script><script>${drawer}</script>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, reducedMotion: 'reduce'});
        page.setDefaultTimeout(5000);
        const origin = `http://127.0.0.1:${server.address().port}`;
        for (const fallback of [false, true]) {
            await page.goto(origin);
            if (fallback) await page.evaluate(() => Object.defineProperty(document.documentElement, 'requestFullscreen', {value: () => Promise.reject(new Error('Unsupported mobile API'))}));
            await page.locator('[data-fullscreen-toggle]').tap();
            await page.waitForFunction(() => !!document.fullscreenElement || !!window.rmFullscreenFallback);
            await page.locator('[data-account-menu-open]').tap();
            await page.locator('[data-fullscreen-toggle]').tap();
            await page.locator('[data-fullscreen-toggle]').tap();
            assert.equal(await page.locator('#sidebarAccountMenu').getAttribute('aria-hidden'), 'false', 'Inside clicks keep account menu open');
            await page.locator('#profile').tap();
            await page.waitForFunction(() => document.querySelector('#rm-fullscreen-page')?.contentWindow.location.pathname === '/profile');
            await page.waitForFunction(() => !!document.fullscreenElement || !!window.rmFullscreenFallback);
            assert.equal(page.url(), origin + '/', 'Account navbar preserves host document');
            await page.goto(origin);
            if (fallback) await page.evaluate(() => Object.defineProperty(document.documentElement, 'requestFullscreen', {value: () => Promise.reject(new Error('Unsupported mobile API'))}));
            await page.locator('[data-fullscreen-toggle]').tap();
            await page.waitForFunction(() => !!document.fullscreenElement || !!window.rmFullscreenFallback);
            await page.locator('#menuToggle').tap();
            await page.locator('#main').tap();
            await page.waitForFunction(() => document.querySelector('#rm-fullscreen-page')?.style.pointerEvents === 'auto');
            assert.equal(page.url(), origin + '/', 'Main navbar preserves host document');
            assert(await page.evaluate(() => !!document.fullscreenElement || !!window.rmFullscreenFallback), 'Main navbar preserves fullscreen');
            console.log('Main navbar: fullscreen retained', {fallback});
            await page.frameLocator('#rm-fullscreen-page').locator('#main').tap();
            await page.waitForFunction(() => document.querySelectorAll('iframe').length === 1 && document.querySelector('#rm-fullscreen-page')?.contentWindow.location.pathname === '/reports');
            assert(await page.evaluate(() => !!document.fullscreenElement || !!window.rmFullscreenFallback), 'Repeated navbar navigation preserves fullscreen');
            await page.frameLocator('#rm-fullscreen-page').locator('#profile').tap();
            // Account links must use the same host navigation as main navbar links.
            await page.waitForFunction(() => document.querySelector('#rm-fullscreen-page')?.contentWindow.location.pathname === '/profile');
            assert.equal(await page.frameLocator('#rm-fullscreen-page').locator('[data-fullscreen-toggle]').getAttribute('aria-pressed'), 'true', 'Account navbar navigation preserves fullscreen');
        }
        console.log('Mobile fullscreen navigation: passed');
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
