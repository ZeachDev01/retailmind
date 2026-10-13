const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Cashier UI: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const label = 'Cashier UI';
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-cashier-ui-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const screens = [
    'cashier/dashboard', 'cashier/pos', 'cashier/findProduct', 'cashier/findProduct?q=THEME',
    'cashier/shifts', 'cashier/stock_issues', 'cashier/refunds', 'cashier/refunds?sale_id=1',
    'cashier/refunds?refund_id=1',
    'cashier/history', 'cashier/history?type=refunds', 'cashier/history?type=movements',
    'cashier/history?type=shifts', 'cashier/history?type=sales&id=1',
    'invoice/sales', 'invoice/sales?sale_id=1', 'invoice/sales?tab=reversals&sale_id=1',
    'auth/user_info', 'auth/preferences', 'auth/change_password', 'auth/workspace',
    'notification/notifications',
];
const sizes = [[320, 740], [375, 812], [768, 1024], [900, 600], [945, 1177], [1024, 768], [1440, 900]];
const liveCdn = process.argv.includes('--live-cdn');
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/cashier_ui_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    const checks = [], errors = [], resourceErrors = [];
    try {
        const data = JSON.parse(fixture('create'));
        fs.writeFileSync(path.join(output, 'database.txt'), database);
        fixture('receipts');
        const before = JSON.parse(fixture('cashier_state'));
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
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({viewport: {width: 1440, height: 900}, hasTouch: true});
        if (!liveCdn) await context.route('https://**/*', route => route.fulfill({status: 200, body: '', contentType: route.request().resourceType() === 'stylesheet' ? 'text/css' : 'text/javascript'}));
        const page = await context.newPage();
        page.setDefaultTimeout(5000);
        page.on('pageerror', error => errors.push(error.message));
        page.on('requestfailed', request => {
            if (liveCdn && request.url().startsWith('https:')) resourceErrors.push(request.url() + ': ' + request.failure().errorText);
        });
        page.on('response', response => {
            if (liveCdn && response.url().startsWith('https:') && response.status() >= 400) resourceErrors.push(response.url() + ': HTTP ' + response.status());
        });
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_cashier');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const inspect = () => page.evaluate(() => {
            const width = document.documentElement.clientWidth;
            const main = document.querySelector('.checkout-modal.open, dialog[open]') || document.querySelector('.main-content, main');
            const issues = [];
            const visible = el => el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden';
            const name = el => el.tagName.toLowerCase() + '#' + el.id + '.' + el.className;
            const rgb = value => {
                const channels = value.match(/[\d.]+/g).map(Number);
                return value.startsWith('color(srgb') ? channels.map((channel, i) => i < 3 ? channel * 255 : channel) : channels;
            };
            const over = (a, b) => a.slice(0, 3).map((channel, i) => channel * (a[3] ?? 1) + b[i] * (1 - (a[3] ?? 1)));
            const luminance = color => color.map(channel => {
                const s = channel / 255;
                return s <= .04045 ? s / 12.92 : ((s + .055) / 1.055) ** 2.4;
            }).reduce((sum, channel, i) => sum + channel * [.2126, .7152, .0722][i], 0);
            if (document.documentElement.scrollWidth > width + 1) issues.push('Page overflow');
            if (document.querySelectorAll('main h1').length !== 1) issues.push('Expected one page h1');
            for (const el of [...main.querySelectorAll('input:not([type=hidden]), select, textarea'), document.querySelector('#commandSearch')].filter(Boolean)) {
                if (!el.labels?.length && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')) issues.push('Unlabelled field ' + name(el));
            }
            for (const el of [...main.querySelectorAll('button, a.btn, summary'), ...document.querySelectorAll('.admin-mobile-topbar button, .global-topbar button, .global-topbar a')]) {
                if (!visible(el)) continue;
                const box = el.getBoundingClientRect();
                if (!el.textContent.trim() && !el.getAttribute('aria-label') && !el.getAttribute('title')) issues.push('Unnamed control ' + name(el));
                if (box.width < 43.9 || box.height < 43.9) issues.push('Small target ' + name(el) + ' ' + Math.round(box.width) + 'x' + Math.round(box.height));
            }
            for (const el of main.querySelectorAll('input:not([type=hidden]),select,textarea,button,.page-heading,.topbar,.form-grid')) {
                if (!visible(el)) continue;
                const box = el.getBoundingClientRect();
                if (box.left >= -1 && box.right <= width + 1) continue;
                let parent = el.parentElement;
                while (parent && parent !== main && !/(auto|scroll)/.test(getComputedStyle(parent).overflowX)) parent = parent.parentElement;
                if (!parent || parent === main) issues.push('Outside viewport ' + name(el));
            }
            for (const el of main.querySelectorAll('*')) {
                if (!visible(el) || el.closest(':disabled, [aria-disabled="true"]') || ![...el.childNodes].some(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim())) continue;
                const style = getComputedStyle(el);
                const layers = [];
                let parent = el, unsupported = false;
                while (parent) {
                    const paint = getComputedStyle(parent);
                    if (paint.backgroundImage !== 'none' || Number(paint.opacity) < 1) { unsupported = true; break; }
                    layers.push(rgb(paint.backgroundColor));
                    parent = parent.parentElement;
                }
                if (unsupported) continue;
                const background = layers.reverse().reduce((base, layer) => over(layer, base), [255, 255, 255]);
                const ink = over(rgb(style.color), background);
                const a = luminance(ink), b = luminance(background);
                const ratio = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
                const large = parseFloat(style.fontSize) >= 24 || (parseFloat(style.fontSize) >= 18.66 && parseInt(style.fontWeight) >= 700);
                if (ratio < (large ? 3 : 4.5) - .01) issues.push('Text contrast ' + name(el) + ' ' + ratio.toFixed(2) + ': ' + el.textContent.trim().slice(0, 35));
            }
            const header = document.querySelector(innerWidth <= 900 ? '.admin-mobile-topbar' : '.global-topbar');
            if (header) {
                const controls = [...header.querySelectorAll('button,a')].filter(visible).map(el => ({el, rect: el.getBoundingClientRect()}));
                for (let i = 0; i < controls.length; i++) {
                    const a = controls[i].rect;
                    if (a.left < -1 || a.right > width + 1) issues.push('Header outside viewport ' + name(controls[i].el));
                    for (const {el, rect: b} of controls.slice(i + 1)) {
                        if (Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1) issues.push('Header overlap ' + name(el));
                    }
                }
            }
            return [...new Set(issues)];
        });

        for (const screen of screens) {
            console.log(label + ': ' + screen);
            const [route, query] = screen.split('?');
            const response = await page.goto(origin + '/components/' + route + '.php' + (query ? '?' + query : ''), {waitUntil: 'networkidle'});
            if (response.status() !== 200) {
                checks.push({screen, issues: ['HTTP ' + response.status()]});
                fs.writeFileSync(path.join(output, screen.replace(/[^a-z0-9_-]/gi, '-') + '-error.html'), await page.content());
                continue;
            }
            const stylesheet = await page.locator('link[href*="assets/css/style.css"]').first().getAttribute('href');
            if (!new URL(stylesheet, origin).searchParams.has('v')) checks.push({screen, issues: ['Stylesheet missing cache version']});
            for (const theme of ['light', 'dark']) {
                await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
                for (const [width, height] of sizes) {
                    await page.setViewportSize({width, height});
                    await page.waitForTimeout(250);
                    const issues = await inspect();
                    checks.push({screen, theme, width, height, issues});
                    if (!process.argv.includes('--no-screenshots') && [375, 768, 945, 1440].includes(width)) {
                        await page.screenshot({path: path.join(output, screen.replace(/[^a-z0-9_-]/gi, '-') + '-' + theme + '-' + width + '.png'), fullPage: true});
                    }
                }
            }
            const verify = async () => {
                await page.setViewportSize({width: 375, height: 812});
                if (await page.locator('dialog[open]').count()) {
                    await page.keyboard.press('Escape');
                    assert.equal(await page.locator('dialog[open]').count(), 0, 'Saved receipt closes with Escape');
                }
                const menu = page.locator('#menuToggle');
                if (await menu.count()) {
                    await menu.tap();
                    assert.equal(await page.locator('.main-content').evaluate(el => el.inert), true, 'Drawer isolates background keyboard focus');
                    await page.keyboard.press('Escape');
                    assert.equal(await page.locator('#appSidebar').evaluate(el => el.inert), true, 'Closed drawer is not keyboard-focusable');
                    assert.equal(await page.locator('.main-content').evaluate(el => el.inert), false, 'Closing drawer restores background');
                    await menu.tap();
                    await page.locator('#sidebarOverlay').tap({position: {x: 375 - 24, y: 150}});
                    assert.equal(await page.locator('.main-content').evaluate(el => el.inert), false, 'Touching the backdrop restores background focus');
                    await page.locator('.admin-mobile-avatar').tap();
                    assert.equal(await page.locator('#sidebarAccountMenu').getAttribute('aria-hidden'), 'false', 'Mobile account menu opens');
                    await page.keyboard.press('Escape');
                    await page.locator('.mobile-header-search').tap();
                    await page.locator('#commandSearch').waitFor({state: 'visible'});
                    await page.waitForFunction(() => document.activeElement.id === 'commandSearch');
                    assert.equal(await page.locator('#commandSearch').evaluate(el => el === document.activeElement), true, 'Search receives keyboard focus');
                    await page.keyboard.press('Escape');
                }
                if (screen === 'auth/change_password') {
                    const toggle = page.locator('[data-password-toggle]').first();
                    await toggle.tap();
                    assert.equal(await toggle.getAttribute('aria-pressed'), 'true', 'Password visibility responds to touch');
                    await toggle.tap();
                }
                if (screen === 'cashier/findProduct') {
                    await context.route('**/apiScanner/products.php*', route => route.fulfill({status: 503, body: '{}'}));
                    await page.locator('#product-search').fill('THEME');
                    await page.waitForFunction(() => document.querySelector('#search-results').textContent.includes('Type again to retry'));
                    await context.unroute('**/apiScanner/products.php*');
                    await page.locator('.pos-category-button').first().tap();
                    await page.locator('.product-result-card').first().waitFor();
                    assert.equal(await page.locator('.pos-category-button').first().getAttribute('aria-pressed'), 'true');
                    await page.locator('#product-search').fill('no-such-product');
                    await page.waitForFunction(() => document.querySelector('#search-count').textContent === '0 results');
                    assert.equal(await page.locator('.pos-category-button').first().getAttribute('aria-pressed'), 'false');
                    await page.locator('#product-search').fill('THEME');
                    await page.locator('.product-result-card').first().waitFor();
                    checks.push({screen, state: 'search results', issues: await inspect()});
                }
                if (screen === 'cashier/stock_issues') {
                    await page.getByRole('button', {name: 'Approved', exact: true}).tap();
                    assert.equal(await page.locator('#report-filter-status').isVisible(), true, 'Empty status explains recovery');
                    await page.getByRole('button', {name: 'All', exact: true}).tap();
                    assert.equal(await page.locator('#report-filter-status').isVisible(), false);
                    await page.locator('#product-search').fill('THEME');
                    await page.locator('#product-results button').first().tap();
                    assert.equal(await page.locator('#product_id').inputValue(), '1', 'Product selection responds to touch');
                    await page.locator('#clear-product').tap();
                    assert.equal(await page.locator('#product_id').inputValue(), '');
                }
                if (screen === 'cashier/pos') {
                    const catalog = page.locator('#quick-product-grid');
                    await catalog.scrollIntoViewIfNeeded();
                    const box = await catalog.boundingBox();
                    const cdp = await context.newCDPSession(page);
                    const x = box.x + box.width / 2, y = Math.min(box.y + box.height - 30, 760);
                    await cdp.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{x, y}]});
                    for (let step = 1; step <= 5; step++) {
                        await cdp.send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{x, y: y - step * 35}]});
                        await page.waitForTimeout(30);
                    }
                    await cdp.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
                    await page.waitForTimeout(150);
                    assert.ok(await catalog.evaluate(el => el.scrollTop) > 0, 'Synthesized touch scrolls the catalog');
                    await cdp.detach();
                    await page.locator('[data-quick-category="1"]').tap();
                    await page.locator('[data-quick-add="1"]').tap();
                    await page.waitForFunction(() => document.querySelector('#cart-line-count').textContent === '1');
                    await page.locator('[data-quick-add="1"]').tap();
                    await page.waitForFunction(() => document.querySelector('.qty-field').value === '2');
                    await page.locator('[data-quick-decrease="1"]').tap();
                    await page.waitForFunction(() => document.querySelector('.qty-field').value === '1');
                    for (const theme of ['light', 'dark']) {
                        await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                        for (const [width, height] of [[375, 812], [768, 1024], [1440, 900]]) {
                            await page.setViewportSize({width, height});
                            await page.waitForTimeout(250);
                            checks.push({screen, theme, width, state: 'populated cart', issues: await inspect()});
                            await page.locator('#checkout-button').tap();
                            await page.locator('#checkout-modal.open').waitFor();
                            await page.waitForTimeout(250);
                            await page.locator('#review-cash').fill('50');
                            assert.equal(await page.locator('#review-change').textContent(), '25.00');
                            checks.push({screen, theme, width, state: 'checkout dialog', issues: await inspect()});
                            await page.keyboard.press('Tab');
                            assert.equal(await page.evaluate(() => document.activeElement.closest('#checkout-modal') !== null), true, 'Checkout traps keyboard focus');
                            await page.screenshot({path: path.join(output, 'checkout-' + theme + '-' + width + '.png')});
                            await page.keyboard.press('Escape');
                            assert.equal(await page.locator('#checkout-modal').getAttribute('aria-hidden'), 'true');
                            await page.locator('#void-sale-btn').tap();
                            await page.locator('#discard-modal.open').waitFor();
                            await page.waitForTimeout(250);
                            checks.push({screen, theme, width, state: 'discard dialog', issues: await inspect()});
                            await page.keyboard.press('Escape');
                        }
                    }
                    await page.locator('.remove-item-btn').first().click();
                    await page.waitForFunction(() => document.querySelector('#cart-line-count').textContent === '0');
                }
            };
            try { await verify(); }
            catch (error) { checks.push({screen, issues: ['Interaction: ' + error.message]}); }
        }
        assert.deepEqual(JSON.parse(fixture('cashier_state')), before, 'UI checks preserve sales, stock, cash, refunds and held sales');
        fixture('gates');
        for (const screen of ['cashier/pos', 'cashier/shifts', 'cashier/refunds']) {
            await page.goto(origin + '/components/' + screen + '.php', {waitUntil: 'networkidle'});
            for (const theme of ['light', 'dark']) {
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                for (const [width, height] of [[320, 740], [768, 1024], [1440, 900]]) {
                    await page.setViewportSize({width, height});
                    await page.waitForTimeout(250);
                    checks.push({screen, theme, width, state: 'locked register', issues: await inspect()});
                }
            }
        }
        fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({liveCdn, checks, errors, resourceErrors}, null, 2));
        fs.writeFileSync(path.join(output, 'server.log'), logs);
        console.log(label + ' artifacts: ' + output);
        const failures = checks.filter(check => check.issues.length);
        console.log(JSON.stringify({checks: checks.length, failingChecks: failures.length, issues: [...new Set(failures.flatMap(check => check.issues))], errors, resourceErrors}, null, 2));
        if (!process.argv.includes('--inspect')) {
            assert.deepEqual(failures, [], label + ' pages must pass accessibility, target, header and layout checks');
            assert.deepEqual(errors, [], label + ' pages must not throw JavaScript errors');
            assert.deepEqual(resourceErrors, [], 'Live CDN assets must load successfully');
        }
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
