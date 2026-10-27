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
// Query the disposable DB through the same configured connection as the fixture.
const query = sql => {
    const script = "require 'src/backend/bootstrap/app.php'; $c=require 'src/backend/config/database.php';"
        + " $p=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname=" + database + "', $c['username'],$c['password']);"
        + " echo json_encode($p->query(base64_decode(getenv('RM_TEST_SQL')))->fetchAll(PDO::FETCH_ASSOC));";
    const result = spawnSync(php, ['-r', script], {encoding: 'utf8', env: {...env, RM_TEST_SQL: Buffer.from(sql).toString('base64')}});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout);
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
        const login = async username => {
            await page.goto(origin + '/?login=1');
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill(username);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        };
        const switchWorkspace = async role => {
            await page.goto(origin + '/components/auth/workspace.php');
            await Promise.all([page.waitForNavigation(), page.locator(`.workspace-option:has(input[value="${role}"])`).getByRole('button', {name: 'Open'}).click()]);
        };
        await page.goto(origin + '/components/administrator/dashboard.php');
        assert.equal(await page.getByText('Pending approvals', {exact: true}).count(), 0);
        assert.equal(await page.getByText('Stock issues awaiting an Administrator decision.').count(), 0);
        assert.equal(await page.locator('.dashboard-state-error').count(), 0);
        assert.equal(await page.locator('.attention-item').filter({hasText: 'Pending Stock Issue'}).count(), 0, 'Routine report is not an Administrator escalation');
        await page.goto(origin + '/components/administrator/stock_issues.php');
        await page.getByRole('link', {name: 'Open report', exact: true}).click();
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
        assert.equal(await page.locator('#low_stock_threshold').count(), 0, 'Low stock uses product reorder levels');
        await page.locator('#notify_low_stock').uncheck();
        await submit(page.getByRole('button', {name: /Save/}));
        assert.equal(await page.locator('#notify_low_stock').isChecked(), false, 'Low stock delivery preference persists');
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
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
        const productNotice = page.locator('.notification-item').first();
        assert.equal(new URL(await productNotice.locator('.notification-product-link').getAttribute('href'), origin).searchParams.get('q'), 'NOTICE-13');
        const categoryBox = await productNotice.locator('.notification-category').boundingBox();
        await Promise.all([page.waitForNavigation(), page.mouse.click(categoryBox.x + categoryBox.width / 2, categoryBox.y + categoryBox.height / 2)]);
        assert.equal(new URL(page.url()).pathname, '/components/inventory_management/products.php');
        await page.waitForFunction(() => document.getElementById('product-search')?.value === 'NOTICE-13');
        assert.equal(await page.locator('#product-search').inputValue(), 'NOTICE-13', 'Notification automatically searches the product SKU');
        assert.equal(await page.locator('tr[data-product-id="1013"]').isVisible(), true);
        assert.equal(await page.locator('tr[data-product-id="1012"]').isVisible(), false);
        await page.goto(url + '?category=low_stock');
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
        assert.equal(await active.count(), 0, 'Routine batch reminders stay in Inventory workspace');
        assert.equal(await active.getByRole('link', {name: 'Record Supplier Pullout'}).count(), 0, 'Administrator oversight offers no mutation shortcut');
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(url);
        assert.equal(await active.getByRole('heading', {name: 'Expired stock—waiting for supplier collection: Reminder Bread'}).count(), 1);
        assert.match(await active.getByRole('link', {name: 'Record Supplier Pullout'}).getAttribute('href'), /product_id=901&batch_id=901$/);
        await submit(active.locator('.notification-product-link'));
        await page.waitForFunction(() => document.getElementById('product-search')?.value === 'REMINDER-BREAD');
        assert.equal(await page.locator('#product-search').inputValue(), 'REMINDER-BREAD', 'Batch reminders open product search');
        await page.goto(url);
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

        // ---- #262: workspace-scoped lists, counts, read state, role and account eligibility ----
        await page.goto(origin + '/components/auth/logout.php');
        await login('theme_super_admin');
        fixture('workspace_seed');
        const readState = async () => Object.fromEntries(
            query('SELECT notification_id, is_read FROM notifications WHERE user_id = 2').map(row => [Number(row.notification_id), Number(row.is_read)]));
        const bell = () => page.locator('.global-topbar .global-notification-button').first();

        // 1. A multi-role user sees only the signed-in Workspace's rows, category counts, and bell count.
        await page.goto(url);
        assert.deepEqual(await page.locator('.notification-content h2').allTextContents(), ['Super admin notice'], 'Super Administrator Workspace only lists its own rows');
        assert.deepEqual(await page.locator('#notification-category option').allTextContents(), ['All', 'System'], 'Category options follow the active Workspace');
        assert.equal(await bell().locator('strong').textContent(), '1', 'Bell count follows the active Workspace');
        assert.equal(await bell().getAttribute('aria-label'), 'Notifications: 1 unread');
        await page.goto(url + '?category=system');
        assert.match(await page.locator('.notification-more').innerText(), /Showing 1 of 1/, 'Category counts follow the active Workspace');

        await switchWorkspace('inventory_manager');
        await page.goto(url);
        assert.deepEqual(await page.locator('.notification-content h2').allTextContents(), ['Inventory notice two', 'Inventory notice one'], 'Secondary-role recipient sees the secondary Workspace rows');
        assert.deepEqual(await page.locator('#notification-category option').allTextContents(), ['All', 'System'], 'Secondary Workspace exposes only its own categories');
        assert.equal(await page.locator('.notification-view-count').textContent(), '2', 'Unread count follows the active Workspace');
        assert.equal(await bell().locator('strong').textContent(), '2', 'Bell count changes with the Workspace');

        await switchWorkspace('admin');
        await page.goto(url);
        assert.equal(await page.locator('.notification-item').count(), 2, 'Administrator Workspace lists only its own rows');
        assert.equal(await page.locator('.notification-item.unread').count(), 1, 'Read state is preserved per Workspace');
        assert.deepEqual(await page.locator('#notification-category option').allTextContents(), ['All', 'Inventory adjustments', 'System']);
        assert.equal(await bell().locator('strong').textContent(), '1');
        await page.goto(url + '?category=adjustment');
        assert.deepEqual(await page.locator('.notification-content h2').allTextContents(), ['Admin adjustment notice'], 'Category list never widens across Workspaces');
        assert.match(await page.locator('.notification-more').innerText(), /Showing 1 of 1/);

        await switchWorkspace('cashier');
        await page.goto(url);
        assert.deepEqual(await page.locator('.notification-content h2').allTextContents(), ['Cashier notice'], 'Cashier Workspace lists only its own rows');
        assert.deepEqual(await page.locator('#notification-category option').allTextContents(), ['All', 'Inventory adjustments']);
        assert.equal(await bell().locator('strong').textContent(), '1');

        // 2. Role removal revokes that Workspace's visibility.
        await switchWorkspace('inventory_manager');
        await page.goto(url);
        assert.equal(await page.locator('.notification-item').count(), 2);
        assert.match(await page.locator('.sidebar-workspace-options').textContent(), /Inventory/);
        fixture('workspace_revoke_role');
        await page.goto(url);
        assert.equal(await page.getByText('Inventory notice', {exact: false}).count(), 0, 'Removing a secondary role revokes that Workspace rows');
        assert.deepEqual(await page.locator('.notification-content h2').allTextContents(), ['Super admin notice'], 'The session falls back to a Workspace the account still holds');
        assert.doesNotMatch(await page.locator('.sidebar-workspace-options').textContent(), /Inventory/, 'Revoked Workspace is no longer offered');
        const revokedCsrf = await page.locator('input[name="csrf_token"]').first().inputValue();
        const rejected = await page.request.post(origin + '/components/auth/workspace.php', {form: {workspace: 'inventory_manager', csrf_token: revokedCsrf}});
        assert.equal(rejected.status(), 403, 'Revoked Workspace rejects a switch attempt');
        fixture('workspace_restore_role');
        await switchWorkspace('inventory_manager');
        await page.goto(url);
        assert.equal(await page.locator('.notification-item').count(), 2, 'Restoring the role restores the Workspace rows');

        // 3. Reading one Workspace's updates does not clear another Workspace's unread work.
        await submit(page.getByRole('button', {name: 'Mark all as read', exact: true}));
        await page.goto(url);
        assert.equal(await page.locator('.notification-item.unread').count(), 0, 'Bulk read clears only the active Workspace');
        assert.equal(await bell().locator('strong').count(), 0, 'Bell count clears only for the active Workspace');
        assert.deepEqual(await readState(), {9300: 0, 9310: 0, 9311: 1, 9320: 1, 9321: 1, 9330: 0}, 'Reading Inventory leaves other Workspace rows unread');
        await switchWorkspace('admin');
        await page.goto(url);
        assert.equal(await page.locator('.notification-item.unread').count(), 1, 'Administrator unread work survives the Inventory bulk read');
        assert.equal(await page.locator('.notification-item.unread .notification-content h2').textContent(), 'Admin adjustment notice');
        assert.equal(await bell().locator('strong').textContent(), '1');
        await switchWorkspace('super_admin');
        await page.goto(url);
        assert.equal(await page.locator('.notification-item.unread').count(), 1, 'Super Administrator unread work survives the Inventory bulk read');

        // 4. A disabled account cannot see scoped rows.
        fixture('workspace_disable');
        await page.goto(url);
        assert.equal(new URL(page.url()).searchParams.get('login'), '1', 'Disabled account loses access to scoped Notifications');
        assert.equal(await page.locator('.notification-item').count(), 0, 'Disabled account sees no scoped rows');
        fixture('workspace_enable');
        await login('theme_super_admin');
        await page.goto(url);
        assert.deepEqual(await page.locator('.notification-content h2').allTextContents(), ['Super admin notice'], 'Re-enabled account regains only its scoped rows');
        assert.equal(await bell().locator('strong').textContent(), '1');

        // 5. One account's reads never touch another account's rows (extends the 9000 "Private notice" coverage).
        const csrfToken = await page.locator('input[name="csrf_token"]').first().inputValue();
        const user3Unread = async () => Number((await query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = 3 AND is_read = 0'))[0].c);
        const user3Before = await user3Unread();
        const foreignRead = Number((await query('SELECT is_read FROM notifications WHERE notification_id = 2'))[0].is_read);
        await page.request.post(url, {form: {action: 'mark_read', notification_id: '2', csrf_token: csrfToken}});
        assert.equal(Number((await query('SELECT is_read FROM notifications WHERE notification_id = 2'))[0].is_read), foreignRead, 'An account cannot mark another account\'s notification read');
        await submit(page.getByRole('button', {name: 'Mark all as read', exact: true}));
        assert.equal(await user3Unread(), user3Before, 'Bulk read never touches another account\'s unread rows');
        assert.equal(Number((await query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = 2 AND workspace = 'super_admin' AND is_read = 0"))[0].c), 0, 'Own Workspace unread work is still cleared');
        console.log('Notification list: passed; screenshots ' + output);
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

