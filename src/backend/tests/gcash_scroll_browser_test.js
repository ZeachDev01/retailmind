'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const source = fs.readFileSync('src/frontend/components/cashier/gcash.php', 'utf8');
    const markup = source.match(/<main[\s\S]*?<\/main>/)[0].replace(/<\?(?:php|=)[\s\S]*?\?>/g, '');
    const sidebarSource = fs.readFileSync('src/frontend/components/sidebar.php', 'utf8');
    const sidebarScript = [...sidebarSource.matchAll(/<script>([\s\S]*?)<\/script>/g)]
        .find(match => match[1].includes('function setSidebarOpen'))[1];
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 1366, height: 768}});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://gcash.test/**', route => route.fulfill({body: '<html></html>', contentType: 'text/html'}));
        await page.goto('http://gcash.test/');
        await page.setContent(`<body class="cashier-page"><aside id="appSidebar"></aside><div id="sidebarOverlay"></div>${markup}</body>`);
        for (const file of ['style.css', 'cashier.css', 'gcash.css']) {
            await page.addStyleTag({path: `src/frontend/assets/css/${file}`});
        }
        await page.evaluate(() => {
            document.getElementById('gcash-workspace').dataset.api = 'http://gcash.test/gcash_api.php';
            window.fetch = async () => ({ok: true, json: async () => ({success: true,
                session: null, last_closed_session: null, has_open_shift: true,
                transactions: [], needs_checking: [], has_next: false})});
        });
        for (const file of ['ui.js', 'gcash-reference.js', 'gcash.js']) {
            await page.addScriptTag({path: `src/frontend/assets/js/${file}`});
        }
        await page.addScriptTag({content: sidebarScript});
        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
        await page.getByRole('button', {name: 'Open GCash', exact: true}).click();
        // Resizing uses the real sidebar handler while the native dialog is open.
        await page.setViewportSize({width: 1280, height: 720});
        await page.waitForFunction(() => document.body.classList.contains('no-scroll'));
        await page.locator('#gcash-open-number').fill('09171234567');
        await page.locator('#gcash-open-cash').fill('100');
        await page.locator('#gcash-open-wallet').fill('200');
        await page.locator('#gcash-open-submit').click();
        await page.waitForFunction(() => !document.getElementById('gcash-open-dialog').open);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        assert.equal(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')), false,
            'Finishing a GCash action must release the page scroll lock');
        await page.mouse.move(1100, 500);
        await page.mouse.wheel(0, 600);
        await page.waitForFunction(() => window.scrollY > 0);
        for (const dismissal of ['cancel', 'escape']) {
            await page.getByRole('button', {name: 'Open GCash', exact: true}).click();
            await page.evaluate(() => window.dispatchEvent(new Event('resize')));
            await page.waitForFunction(() => document.body.classList.contains('no-scroll'));
            if (dismissal === 'cancel') await page.locator('#gcash-open-cancel').click();
            else await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.body.classList.contains('no-scroll'));
        }
        await page.getByRole('button', {name: 'Open GCash', exact: true}).click();
        await page.evaluate(() => {
            const overlay = document.createElement('div');
            overlay.id = 'scroll-test-overlay';
            overlay.className = 'rm-modal-overlay';
            document.body.append(overlay);
            RetailMindUI.openOverlay(overlay);
        });
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => !document.getElementById('gcash-open-dialog').open);
        assert.equal(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')), true,
            'Closing a GCash dialog keeps scrolling locked when another overlay remains open');
        await page.evaluate(() => RetailMindUI.closeOverlay(document.getElementById('scroll-test-overlay')));
        assert.equal(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')), false);
        assert.deepEqual(errors, [], 'GCash scroll flow has no browser errors');
        console.log('GCash scroll: passed (submit, cancel, Escape, wheel scrolling, remaining overlay lock)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
