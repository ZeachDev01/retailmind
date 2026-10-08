const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
    const source = fs.readFileSync('src/frontend/components/sidebar.php', 'utf8');
    const script = source.split(/\r?\n/).find(line => line.includes("app_url('assets/js/ui.js')"));
    const rendered = spawnSync(process.env.PHP_BINARY || 'php', ['-r', 'function app_url($url){return "/".$url;} function sidebar_e($value){return htmlspecialchars($value);} ?>' + script], {cwd:path.resolve('src/frontend/components'), encoding:'utf8'});
    assert.equal(rendered.status, 0, rendered.stderr);
    const scriptUrl = rendered.stdout.match(/src="([^"]+)"/)[1];
    assert.match(scriptUrl, /\/assets\/js\/ui\.js\?v=\d+$/);
    const nav = source.slice(source.indexOf('<nav class="sidebar-account-menu"'), source.indexOf('<div class="global-topbar"')).replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '');
    const styles = ['global','forms','utilities','sidebar','dashboard','tables','modals','products','responsive','theme'].map(name => fs.readFileSync('src/frontend/assets/css/'+name+'.css','utf8')).join('');
    const ui = fs.readFileSync('src/frontend/assets/js/ui.js','utf8');
    const sidebarScript = source.slice(source.lastIndexOf('<script>'));
    const shell = '<div class="app-shell"><button id="menuToggle">Menu</button><div id="sidebarOverlay" class="sidebar-overlay"></div><aside id="appSidebar" class="sidebar"><a href="/next.php">Next page</a></aside><main class="main-content"><div style="height:1800px">Page content</div></main></div>';
    const browser = await chromium.launch({headless:true});
    try {
        const page = await browser.newPage();
        await page.route('http://fullscreen.test/assets/js/ui.js*', route => route.fulfill({contentType:'application/javascript',body:route.request().url().includes('?v=') ? ui : ui.replace('    initFullscreenToggle();','')}));
        await page.route(/http:\/\/fullscreen\.test\/(?:next\.php)?$/, route => route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+styles+'</style><button data-account-menu-open>Account</button>'+shell+nav+rendered.stdout+sidebarScript+'<script>window.retailmindTheme={mode:"system",authenticated:false};'+fs.readFileSync('src/frontend/assets/js/theme.js','utf8')+'</script>'}));
        const openPreferences = async () => {
            await page.locator('[data-account-menu-open]').click();
            await page.locator('.sidebar-account-preferences').click();
        };
        const checkState = async (enabled) => {
            assert.equal(await page.locator('[data-fullscreen-toggle]').getAttribute('aria-pressed'), String(enabled));
            assert.equal(await page.locator('[data-fullscreen-toggle] span').textContent(), enabled ? 'Exit Full Screen' : 'Full Screen');
            assert.equal(await page.locator('[data-fullscreen-toggle] i').getAttribute('class'), 'bi ' + (enabled ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen'));
            assert.equal(await page.evaluate(() => document.documentElement.classList.contains('rm-app-fullscreen')), enabled);
            assert.equal(await page.evaluate(() => localStorage.getItem('retailmind_fullscreen')), enabled ? '1' : '0');
        };
        // No native API is needed, including on iOS or browsers that reject fullscreen.
        await page.addInitScript(() => {
            Element.prototype.requestFullscreen = () => { throw new Error('Native fullscreen must not be requested'); };
            Element.prototype.webkitRequestFullscreen = Element.prototype.requestFullscreen;
        });
        for (const width of [320,390,768,900,1024,1280]) {
            await page.setViewportSize({width,height:844});
            await page.goto('http://fullscreen.test/');
            await openPreferences();
            const button = page.locator('[data-fullscreen-toggle]');
            await button.click();
            await checkState(true);
            assert.equal(await page.evaluate(() => document.fullscreenElement), null);
            await page.locator('[data-account-menu-open]').click();
            if (width <= 900) {
                await page.locator('#menuToggle').click();
                assert.equal(await page.evaluate(() => getComputedStyle(document.body).overflowY), 'hidden');
            }
            await page.locator('#appSidebar a').click();
            await page.waitForURL('http://fullscreen.test/next.php');
            await checkState(true);
            await page.reload();
            await checkState(true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.equal(await page.evaluate(() => Math.round(document.body.getBoundingClientRect().height) === innerHeight), true);
            await page.evaluate(() => { document.body.scrollTop = 400; });
            assert.ok(await page.evaluate(() => document.body.scrollTop > 0), 'App content remains scrollable');
            await page.evaluate(() => { document.body.scrollTop = 0; });
            await page.setViewportSize({width:844,height:width});
            await checkState(true);
            await page.setViewportSize({width,height:844});
            await openPreferences();
            await button.click();
            await checkState(false);
            await page.reload();
            await checkState(false);
            console.log('PASS: Persistent app fullscreen, sidebar navigation, reload, resize and manual exit at '+width+'px');
        }
        await page.addInitScript(() => {
            Storage.prototype.getItem = () => { throw new Error('Storage unavailable'); };
            Storage.prototype.setItem = () => { throw new Error('Storage unavailable'); };
        });
        await page.reload();
        await openPreferences();
        await page.locator('[data-fullscreen-toggle]').click();
        assert.equal(await page.locator('[data-fullscreen-toggle]').getAttribute('aria-pressed'), 'true');
        await page.locator('[data-fullscreen-toggle]').click();
        assert.equal(await page.locator('[data-fullscreen-toggle]').getAttribute('aria-pressed'), 'false');
        console.log('PASS: In-page fullscreen still toggles when storage is unavailable');
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});

