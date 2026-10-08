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
    const browser = await chromium.launch({headless:true});
    try {
        const page = await browser.newPage();
        await page.route('http://fullscreen.test/assets/js/ui.js*', route => route.fulfill({contentType:'application/javascript',body:route.request().url().includes('?v=') ? ui : ui.replace('    initFullscreenToggle();','')}));
        await page.route('http://fullscreen.test/', route => route.fulfill({contentType:'text/html',body:'<style>'+styles+'</style><button data-account-menu-open>Account</button>'+nav+rendered.stdout+'<script>window.retailmindTheme={mode:"system",authenticated:false};'+fs.readFileSync('src/frontend/assets/js/theme.js','utf8')+'</script>'}));
        for (const width of [320,390,1280]) {
            await page.setViewportSize({width,height:844});
            await page.goto('http://fullscreen.test/');
            await page.locator('[data-account-menu-open]').click();
            await page.locator('.sidebar-account-preferences').click();
            const button = page.locator('[data-fullscreen-toggle]');
            await button.click();
            await page.waitForFunction(() => !!document.fullscreenElement);
            assert.equal(await button.getAttribute('aria-pressed'),'true');
            await button.click();
            await page.waitForFunction(() => !document.fullscreenElement);
            assert.equal(await button.getAttribute('aria-pressed'),'false');
            console.log('PASS: Preferences fullscreen enter/exit at '+width+'px with stale unversioned script simulated');
        }
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});

