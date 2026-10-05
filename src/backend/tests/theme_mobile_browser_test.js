const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({headless:true});
    try {
        const page = await browser.newPage({colorScheme:'dark'});
        page.setDefaultTimeout(5000);
        const css = ['global','sidebar','dashboard','modals','responsive','theme']
            .map(name => fs.readFileSync('src/frontend/assets/css/'+name+'.css','utf8')).join('\n');
        const sidebar = fs.readFileSync('src/frontend/components/sidebar.php','utf8');
        const fragment = (start, end) => sidebar.slice(sidebar.indexOf(start), sidebar.indexOf(end))
            .replace(/<\?php[\s\S]*?\?>/g,'').replace(/<\?=[\s\S]*?\?>/g,'#');
        const mobile = fragment('<div class="admin-mobile-topbar"','<div class="sidebar-overlay"');
        const desktop = fragment('<div class="global-topbar"','<div class="command-overlay"').replace('<strong>#</strong>','<strong>99+</strong>');
        const logo = 'data:image/png;base64,'+fs.readFileSync('src/frontend/assets/img/retailmind-icon-512.png').toString('base64');
        const html = '<style>'+css+'</style>'+mobile.replace('src="#"','src="'+logo+'"')+desktop+
            '<main class="main-content"><h1>Control Center</h1><p>Open a platform control directly.</p></main>';
        await page.route('http://retailmind.test/', route => route.fulfill({contentType:'text/html',body:html}));
        const saves = [];
        await page.route('**/theme', route => {
            saves.push(new URLSearchParams(route.request().postData()).get('mode'));
            return route.fulfill({contentType:'application/json',body:'{"success":true}'});
        });
        await page.goto('http://retailmind.test/');
        await page.evaluate(() => { window.retailmindTheme = {mode:'system',authenticated:true,endpoint:'/theme',csrf:'test'}; });
        await page.addScriptTag({content:fs.readFileSync('src/frontend/assets/js/theme.js','utf8')});
        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
        const menu = page.locator('.admin-mobile-topbar .theme-mobile-menu');
        const trigger = menu.locator('summary');
        for (const width of [320,390,800]) {
            await page.setViewportSize({width,height:844});
            const header = page.locator('.admin-mobile-topbar');
            const bounds = await header.boundingBox();
            assert(bounds.width<=width);
            const notification = await header.locator('.global-notification-button').boundingBox();
            const theme = await trigger.boundingBox();
            assert(notification.x+notification.width<=theme.x && theme.x+theme.width<=width);
            assert(Math.abs(notification.y-theme.y)<1, 'Notification and theme share first row');
            const search = await header.locator('.mobile-header-search').boundingBox();
            assert(search.y>=theme.y+theme.height, 'Search occupies second row');
            assert.equal(await menu.locator('[data-theme-mode]').filter({visible:true}).count(),0);
            await trigger.click();
            assert.equal(await menu.locator('[data-theme-mode]').filter({visible:true}).count(),3);
            const panel = await menu.locator('.theme-menu-panel').boundingBox();
            assert(panel.x>=0 && panel.x+panel.width<=width);
            if (process.env.THEME_MOBILE_OUTPUT && width===390) {
                await page.screenshot({path:process.env.THEME_MOBILE_OUTPUT+'/open.png'});
            }
            for (const mode of ['light','dark','system']) {
                if (await menu.getAttribute('open') === null) await trigger.click();
                const response = page.waitForResponse('**/theme');
                await menu.locator('[data-theme-mode="'+mode+'"]').click();
                await response;
                assert.equal(await trigger.getAttribute('data-theme-current'),mode);
                assert.equal(await menu.getAttribute('open'),null);
                assert.equal(await trigger.evaluate(el => el===document.activeElement),true);
            }
            await trigger.press('Space');
            assert.notEqual(await menu.getAttribute('open'),null);
            await page.keyboard.press('Escape');
            assert.equal(await menu.getAttribute('open'),null);
            await trigger.click();
            await page.mouse.click(16,400);
            assert.equal(await menu.getAttribute('open'),null, 'Outside click closes menu');
            if (process.env.THEME_MOBILE_OUTPUT && width===390) {
                await page.screenshot({path:process.env.THEME_MOBILE_OUTPUT+'/closed.png'});
            }
        }
        assert.deepEqual(saves, ['light','dark','system','light','dark','system','light','dark','system']);
        console.log('Mobile theme browser: passed (layout, selection, saves, keyboard, dismissal)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
