const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Notification list: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-notifications-'));
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/notification_fixture.php', action, database], {encoding: 'utf8', env});
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
        const page = await browser.newPage();
        page.setDefaultTimeout(5000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        await page.goto(origin + '/?login=1');
        await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const url = origin + '/components/notification/notifications.php';
        const submit = async button => Promise.all([page.waitForNavigation(), button.click()]);
        await page.goto(origin + '/components/administrator/dashboard.php');
        assert.equal(await page.getByText('Pending approvals', {exact: true}).count(), 0);
        assert.equal(await page.getByText('Stock issues awaiting an Administrator decision.').count(), 0);
        assert.equal(await page.locator('.dashboard-state-error').count(), 0);
        await page.locator('.attention-item').filter({hasText: 'Pending Stock Issue'}).getByRole('link', {name: 'Review', exact: true}).click();
        await page.getByRole('link', {name: 'View', exact: true}).click();
        await page.getByText('Disposable theme Stock Issue', {exact: false}).waitFor();
        assert.match(await page.locator('main').innerText(), /Disposable theme Stock Issue/);
        await page.goto(origin + '/components/report/data_readiness.php');
        assert.equal(await page.getByText('Theme Test Item', {exact: true}).count(), 1, 'Readiness remains populated without procurement tables');
        await page.goto(origin + '/components/report/report_generation.php?report=inventory_valuation');
        assert.equal(await page.locator('#report option[value="replenishment"]').count(), 0);
        assert.equal(await page.getByRole('cell', {name: 'Theme Test Item', exact: true}).count(), 1, 'Retained inventory report renders real stock');
        const reportCsrf = await page.locator('input[name="csrf_token"]').inputValue();
        const csv = await page.request.post(origin + '/components/report/report_generation.php', {
            form: {report: 'inventory_valuation', action: 'export_csv', csrf_token: reportCsrf}
        });
        assert.equal(csv.status(), 200);
        assert.match(csv.headers()['content-type'], /text\/csv/);
        assert.match(await csv.text(), /Theme Test Item/);
        await page.goto(origin + '/components/auth/preferences.php');
        assert.equal(await page.locator('[name="notify_replenishment"]').count(), 0);
        await page.locator('#low_stock_threshold').fill('7');
        await submit(page.getByRole('button', {name: /Save/}));
        assert.equal(await page.locator('#low_stock_threshold').inputValue(), '7', 'Retained notification preferences persist');
        await page.goto(url);
        assert.equal(await page.locator('.notification-item').count(), 10);
        assert.deepEqual(await page.locator('.notification-content h2').allTextContents(), Array.from({length: 10}, (_, i) => 'Notice ' + (27 - i)));
        assert.deepEqual(await page.locator('#notification-category option').allTextContents(), ['All', 'Low stock', 'System']);
        await submit(page.getByRole('link', {name: 'Show more', exact: true}));
        assert.equal(await page.locator('.notification-item').count(), 20);
        await submit(page.getByRole('button', {name: 'Mark as read', exact: true}).first());
        assert.equal(await page.locator('.notification-item').count(), 20);
        assert.equal(await page.locator('.notification-item').first().getAttribute('class'), 'notification-item ');
        await submit(page.locator('.notification-views a').filter({hasText: 'Unread'}));
        assert.equal(await page.locator('.notification-item').count(), 10, 'Changing view resets expansion');
        assert.equal(await page.locator('.notification-content h2').first().textContent(), 'Notice 26', 'Unread excludes read items');
        assert.equal(await page.locator('.notification-view-count').textContent(), '26', 'Viewing does not mark items read');
        assert.match(await page.locator('.notification-views [aria-current="page"]').textContent(), /^Unread\s+26$/);
        await submit(page.getByRole('link', {name: 'Show more', exact: true}));
        assert.equal(await page.locator('.notification-item').count(), 20, 'Unread expands matching items');
        assert.equal(new URL(page.url()).searchParams.get('view'), 'unread');
        await page.locator('#notification-category').selectOption('low_stock');
        await submit(page.getByRole('button', {name: 'Apply filter', exact: true}));
        assert.equal(await page.locator('.notification-item').count(), 10, 'Changing category resets Unread expansion');
        assert.equal(new URL(page.url()).searchParams.get('view'), 'unread');
        await submit(page.getByRole('link', {name: 'Show more', exact: true}));
        assert.equal(await page.locator('.notification-item').count(), 13);
        await submit(page.getByRole('button', {name: 'Mark as read', exact: true}).first());
        assert.equal(await page.locator('.notification-item').count(), 12, 'Marked item disappears from Unread');
        assert.equal(await page.locator('.notification-content h2').first().textContent(), 'Notice 12');
        assert.equal(await page.locator('#notification-category').inputValue(), 'low_stock');
        assert.equal(new URL(page.url()).searchParams.get('batch'), '2', 'Read preserves expansion');
        assert.match(await page.locator('.notification-more').innerText(), /Showing 12 of 12/);
        await submit(page.locator('.notification-views a').filter({hasText: /^All$/}));
        assert.equal(await page.locator('.notification-item').count(), 10);
        assert.equal(await page.locator('#notification-category').inputValue(), 'low_stock');
        await page.locator('#notification-category').selectOption('low_stock');
        await submit(page.getByRole('button', {name: 'Apply filter', exact: true}));
        assert.equal(await page.locator('.notification-item').count(), 10);
        assert.equal(await page.locator('.notification-content h2').first().textContent(), 'Notice 13');
        await submit(page.locator('.notification-views a').filter({hasText: 'Unread'}));
        await submit(page.getByRole('button', {name: 'Mark category as read', exact: true}));
        assert.equal(await page.locator('#notification-category').inputValue(), 'low_stock');
        assert.equal(new URL(page.url()).searchParams.get('view'), 'unread');
        assert.equal(await page.locator('.notification-item').count(), 0);
        assert.equal(await page.getByRole('heading', {name: "You're all caught up"}).count(), 1);
        assert.deepEqual(await page.locator('#notification-category option').allTextContents(), ['All', 'Low stock', 'System'], 'Read categories remain selectable');
        await submit(page.getByRole('link', {name: 'View all notifications', exact: true}));
        assert.equal(await page.locator('#notification-category').inputValue(), 'low_stock');
        await submit(page.getByRole('link', {name: 'Show more', exact: true}));
        assert.equal(await page.locator('.notification-item').count(), 13);
        assert.equal(await page.locator('.notification-item.unread').count(), 0, 'Bulk read includes undisplayed category items');
        assert.equal(await page.getByRole('link', {name: 'Show more', exact: true}).count(), 0);
        await page.goto(url);
        assert.equal(await page.locator('#notification-category').inputValue(), '');
        assert.equal(await page.locator('.notification-item').count(), 10);
        assert.equal(await page.locator('.notification-view-count').textContent(), '13', 'Other category remains unread');
        const csrf = await page.locator('input[name="csrf_token"]').first().inputValue();
        const invalid = await page.request.post(url + '?category=not_available', {form: {action: 'mark_all_read', csrf_token: csrf}});
        assert.equal(invalid.status(), 400, 'Invalid category cannot widen a bulk read');
        for (const query of ['view=invalid', 'view[]=unread']) {
            const response = await page.request.post(url + '?' + query, {form: {action: 'mark_all_read', csrf_token: csrf}});
            assert.equal(response.status(), 400, 'Malformed views reject mutations');
        }
        const noCsrf = await page.request.post(url + '?view=unread', {form: {action: 'mark_all_read'}});
        assert.equal(noCsrf.status(), 403, 'Read actions still require CSRF');
        await page.request.post(url, {form: {action: 'mark_read', notification_id: '9000', csrf_token: csrf}});
        // Query only the disposable DB through the same configured connection as the fixture.
        const state = spawnSync(php, ['-r', `require 'src/backend/bootstrap/app.php'; $c=require 'src/backend/config/database.php'; $p=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname=${database}', $c['username'],$c['password']); echo $p->query('SELECT is_read FROM notifications WHERE notification_id=9000')->fetchColumn();`], {encoding: 'utf8', env});
        assert.equal(state.status, 0, state.stderr);
        assert.equal(state.stdout, '0', 'Cannot mark another user notification read');
        for (const width of [320, 390, 768, 1440]) {
            await page.setViewportSize({width, height: 900});
            await page.goto(url);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No page overflow');
            for (const control of await page.locator('.notification-toolbar button, .notification-toolbar a, .notification-filter select, .btn-mark-read, .notification-more a').all()) {
                assert((await control.boundingBox()).height >= 44, 'Notification controls have 44px touch targets');
            }
            await page.screenshot({path: path.join(output, width + '.png'), fullPage: true});
        }
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
            for (const selector of ['.notification-time', '.notification-views [aria-current="page"]', '.notification-views a:not([aria-current])']) {
                const contrast = await page.locator(selector).first().evaluate(el => {
                    const luminance = color => {
                        const rgb = color.match(/[\d.]+/g).slice(0, 3).map(v => Number(v) / 255);
                        return rgb.map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4).reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
                    };
                    let background = el;
                    while (getComputedStyle(background).backgroundColor === 'rgba(0, 0, 0, 0)') background = background.parentElement;
                    const text = luminance(getComputedStyle(el).color);
                    const surface = luminance(getComputedStyle(background).backgroundColor);
                    return (Math.max(text, surface) + .05) / (Math.min(text, surface) + .05);
                });
                assert(contrast >= 4.5, `${theme} ${selector} contrast must meet WCAG AA (${contrast})`);
            }
            await page.locator('#notification-category').focus();
            assert(await page.locator('#notification-category').evaluate(el => getComputedStyle(el).outlineStyle !== 'none'), 'Keyboard focus stays visible');
            await page.screenshot({path: path.join(output, theme + '.png'), fullPage: true});
        }
        await page.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
        await submit(page.locator('.notification-views a').filter({hasText: 'Unread'}));
        await submit(page.getByRole('button', {name: 'Mark all as read', exact: true}));
        assert.equal(await page.locator('.notification-item.unread').count(), 0);
        assert.equal(await page.getByRole('heading', {name: "You're all caught up"}).count(), 1);
        await submit(page.getByRole('link', {name: 'View all notifications', exact: true}));
        await submit(page.getByRole('link', {name: 'Show more', exact: true}));
        await submit(page.getByRole('link', {name: 'Show more', exact: true}));
        assert.equal(await page.locator('.notification-item').count(), 27);
        assert.equal(await page.locator('.notification-item.unread').count(), 0);
        assert.equal(await page.getByRole('link', {name: 'Show more', exact: true}).count(), 0);
        await page.goto(url + '?category[]=system&batch[]=2&view[]=unread');
        assert.equal(await page.locator('.notification-item').count(), 10, 'Malformed filters fall back safely');
        await page.goto(url + '?batch=999999');
        assert.equal(await page.locator('.notification-item').count(), 27, 'Expansion is bounded by available items');
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
        await page.locator('#landing-login-username').fill('theme_super_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(url);
        assert.equal(await page.locator('.notification-item').count(), 1, 'Fewer than 10 shows every item');
        assert.equal(await page.locator('.notification-content h2').textContent(), 'Private notice');
        assert.equal(await page.locator('.notification-item.unread').count(), 1, 'Other account remains unread after bulk actions');
        assert.equal(await page.getByRole('link', {name: 'Show more', exact: true}).count(), 0);
        await page.goto(url + '?view=unread');
        await submit(page.getByRole('button', {name: 'Mark as read', exact: true}));
        assert.equal(await page.locator('.notification-item').count(), 0, 'Last individual unread item reaches caught-up state');
        assert.equal(await page.getByRole('heading', {name: "You're all caught up"}).count(), 1);
        fixture('bread_create');
        const active = page.getByRole('region', {name: 'Active batch reminders', exact: true});
        await page.goto(url);
        assert.match(await active.innerText(), /COLLECT-10.*Expiration: 2000-10-25\. Remaining: 10\./s);
        assert.equal(await active.getByRole('link', {name: 'Record Supplier Pullout'}).count(), 0, 'Administrator oversight offers no mutation shortcut');
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(url);
        assert.equal(await active.getByRole('heading', {name: 'Expired bread—waiting for supplier collection: Reminder Bread'}).count(), 1);
        assert.match(await active.getByRole('link', {name: 'Record Supplier Pullout'}).getAttribute('href'), /product_id=901&batch_id=901$/);
        await submit(page.getByRole('button', {name: 'Mark all as read', exact: true}));
        await page.goto(url + '?view=unread');
        assert.equal(await active.locator('article').count(), 1, 'Reading does not hide active work in Unread view');
        fixture('bread_partial');
        await page.reload();
        assert.match(await active.innerText(), /Remaining: 2\./);
        for (const width of [320, 1440]) {
            await page.setViewportSize({width, height: 900});
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Active reminders fit viewport');
            await page.screenshot({path: path.join(output, 'bread-' + width + '.png'), fullPage: true});
        }
        fixture('bread_final');
        await page.reload();
        assert.equal(await active.count(), 0, 'Final removal immediately clears active work');
        await page.goto(url);
        assert.match(await page.locator('.notification-list').innerText(), /Expiry alert history:.*Current remaining: 0\./s, 'Depleted notification remains clearly historical');
        console.log('Notification list: passed; screenshots ' + output);
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

