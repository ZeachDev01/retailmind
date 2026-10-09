const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const source = fs.readFileSync('src/frontend/components/sidebar.php', 'utf8');
    const menu = source.slice(source.indexOf('<nav class="sidebar-account-menu"'), source.indexOf('<div class="global-topbar"')).replace(/<\?[\s\S]*?\?>/g, '');
    const css = ['global', 'forms', 'utilities', 'sidebar', 'modals', 'responsive', 'theme'].map(name => fs.readFileSync(`src/frontend/assets/css/${name}.css`, 'utf8')).join('\n');
    const ui = fs.readFileSync('src/frontend/assets/js/ui.js', 'utf8');
    const theme = fs.readFileSync('src/frontend/assets/js/theme.js', 'utf8');
    const browser = await chromium.launch({headless: true});
    try {
        for (const viewport of [...[320, 375, 425, 768, 1024, 1440].map(width => ({width, height: 844})), {width: 320, height: 360}]) {
            const page = await browser.newPage({viewport});
            await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><button data-account-menu-open>Account</button>${menu}<script>${ui}</script><script>window.retailmindTheme={mode:'system',authenticated:false};${theme}</script>`);
            await page.evaluate(() => {
                document.querySelector('.sidebar-workspace-options').innerHTML = Array.from({length: 5}, (_, index) => `<button class="sidebar-workspace-option" type="button">Workspace ${index}</button>`).join('');
            });
            await page.locator('[data-account-menu-open]').click();
            await page.waitForFunction(() => getComputedStyle(document.getElementById('sidebarAccountMenu')).transform === 'matrix(1, 0, 0, 1, 0, 0)');
            for (const selector of ['.sidebar-workspace-switcher', '.sidebar-preferences-menu']) {
                await page.locator(selector + ' > summary').click();
                await page.waitForFunction(selector => document.querySelector(selector).open && document.querySelector(selector + ' > div').style.left, selector);
                const state = await page.locator(selector).evaluate(details => {
                    const summary = details.querySelector('summary');
                    const anchor = summary.getBoundingClientRect();
                    const panel = details.querySelector(':scope > div').getBoundingClientRect();
                    return {
                        triggerAccessible: summary.contains(document.elementFromPoint(anchor.left + anchor.width / 2, anchor.top + anchor.height / 2)),
                        panelWithinScreen: panel.left >= 11 && panel.right <= innerWidth - 11 && panel.top >= 11 && panel.bottom <= innerHeight - 11,
                    };
                });
                assert.deepEqual(state, {triggerAccessible: true, panelWithinScreen: true}, `${viewport.width}x${viewport.height} ${selector}`);
                await page.locator(selector + ' > summary').click();
                assert.equal(await page.locator(selector).evaluate(details => details.open), false, 'The same visible trigger closes its submenu');
            }
            await page.close();
        }
        console.log('Account menu positioning: passed (320/375/425/768/1024/1440px and short mobile viewport, workspace/preferences bounds, hit-testing and toggle close)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
