const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const {chromium} = require('playwright');
const ui = fs.readFileSync('src/frontend/assets/js/ui.js', 'utf8');
const sidebar = fs.readFileSync('src/frontend/components/sidebar.php', 'utf8');
const drawer = sidebar.slice(sidebar.lastIndexOf('<script>') + 8, sidebar.lastIndexOf('</script>'));
const css = ['global', 'sidebar', 'responsive'].map(name => fs.readFileSync(`src/frontend/assets/css/${name}.css`, 'utf8')).join('\n');
(async () => {
    const server = http.createServer((req, res) => {
        res.setHeader('Content-Type', 'text/html');
        res.end(`<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1">
            <style>${css}</style>
            <div class="admin-mobile-topbar"><button id="menuToggle" aria-label="Open menu">Menu</button></div>
            <div class="sidebar-overlay" id="sidebarOverlay"></div><div class="app-shell">
            <aside class="sidebar" id="appSidebar"><nav class="sidebar-nav">
                <a id="first" href="/first"><span>First page</span></a>
                <a id="second" href="/second"><span>Second page</span></a>
                <a id="slow" href="/slow"><span>Slow page</span></a>
            </nav></aside><main class="main-content"><h1>${req.url}</h1>
                <button data-fullscreen-toggle><i></i><span>Full Screen</span></button>
                <button id="control" onclick="this.textContent='Working'">Check control</button>
            </main></div><script>${ui}</script><script>${drawer}</script>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({headless:true});
    try {
        for (const {width, motion} of [{width:390, motion:'no-preference'}, {width:1280, motion:'no-preference'}, {width:390, motion:'reduce'}]) {
            const page = await browser.newPage({viewport:{width,height:844}, isMobile:width<900, hasTouch:true, reducedMotion:motion});
            page.setDefaultTimeout(5000);
            const origin = `http://127.0.0.1:${server.address().port}`;
            await page.goto(origin);
            await page.locator('[data-fullscreen-toggle]').tap();
            await page.waitForFunction(() => !!document.fullscreenElement);
            await page.evaluate(() => {
                const animate = Element.prototype.animate;
                Element.prototype.animate = function (...args) {
                    const animation = animate.apply(this, args);
                    if (this.tagName === 'IFRAME') animation.pause();
                    return animation;
                };
            });
            if (width < 900) await page.locator('#menuToggle').tap();
            await page.locator('#first').tap();
            await page.waitForFunction(() => document.querySelector('#rm-fullscreen-page')?.style.pointerEvents === 'auto');
            const workspace = page.frameLocator('#rm-fullscreen-page');
            assert(await page.locator('#rm-fullscreen-page').evaluate(frame => Number(getComputedStyle(frame).opacity) >= 0.95), 'Interrupted fade keeps page and sidebar visible');
            assert.equal(await page.locator('iframe').count(), 1, 'Old page released without waiting for animation');
            if (width < 900) await workspace.locator('#menuToggle').tap();
            assert(await workspace.locator('#second').isVisible(), 'Sidebar link visible after page change');
            await page.waitForFunction(() => {
                const frame = document.querySelector('#rm-fullscreen-page');
                const bounds = frame.contentDocument.querySelector('#second').getBoundingClientRect();
                return bounds.left >= 0 && bounds.right <= frame.contentWindow.innerWidth;
            });
            if (process.env.FULLSCREEN_TRANSITION_OUTPUT) await page.screenshot({path:`${process.env.FULLSCREEN_TRANSITION_OUTPUT}/sidebar-${width}-${motion}.png`});
            await workspace.locator('#second').tap();
            await page.waitForFunction(() => document.querySelector('#rm-fullscreen-page')?.contentWindow.location.pathname === '/second');
            await workspace.locator('#control').tap();
            assert.equal(await workspace.locator('#control').textContent(), 'Working');
            // A later destination must replace a slow load instead of being ignored.
            let slowRoute;
            await page.route('**/slow', route => { slowRoute = route; });
            if (width < 900) await workspace.locator('#menuToggle').tap();
            const slowRequest = page.waitForRequest('**/slow');
            await workspace.locator('#slow').tap();
            await slowRequest;
            if (width < 900) await workspace.locator('#menuToggle').tap();
            await workspace.locator('#first').tap();
            await page.waitForFunction(() => document.querySelector('#rm-fullscreen-page')?.contentWindow.location.pathname === '/first');
            if (slowRoute) await slowRoute.abort().catch(() => {});
            assert.equal(await page.locator('iframe').count(), 1);
            assert(await page.evaluate(() => !!document.fullscreenElement), 'Transitions preserve fullscreen');
            assert.equal(await page.locator('#rm-fullscreen-page').evaluate(frame => frame.getAnimations().length), motion === 'reduce' ? 0 : 1);
            await page.close();
        }
        console.log('Fullscreen transition: passed (mobile/desktop sidebar, interrupted fade, controls, slow-load replacement, reduced motion)');
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => {console.error(error); process.exitCode=1;});
