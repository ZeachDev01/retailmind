'use strict';
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') throw new Error('Set RUN_DB_TESTS=1 for disposable database fixtures.');
const root = path.resolve(__dirname, '../../..');
const token = 'rh' + Date.now().toString(36);
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const artifacts = path.join(root, 'output/playwright/receiving-history');
const history = '/components/report/receiving_history.php';
const entryRows = '.receiving-history-container tbody tr';
const itemRows = '.history-table tbody tr';
function fixture(action) {
    const bootstrap = "require 'src/backend/bootstrap/app.php'; $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = getenv('RM_THEME_TEST_DATABASE'); require 'src/backend/tests/support/scan_workflow_fixture.php';";
    const run = spawnSync(process.env.PHP_BINARY || 'php', ['-r', bootstrap, '--', action, '--token', token],
        {cwd:root, encoding:'utf8', env:{...process.env, RM_THEME_TEST_DATABASE:database}});
    assert.equal(run.status, 0, run.stderr);
    return JSON.parse(run.stdout.trim().split('\n').at(-1));
}
function databaseFixture(action) {
    const run = spawnSync(process.env.PHP_BINARY || 'php', ['src/backend/tests/support/theme_fixture.php', action, database], {cwd:root, encoding:'utf8'});
    assert.equal(run.status, 0, run.stderr || run.stdout);
}
async function main() {
    let server, browser, data;
    try {
        databaseFixture('create');
        data = fixture('--create');
        fixture('--seed-batches');
        const port = await new Promise(resolve => {
            const probe = net.createServer().listen(0, '127.0.0.1', () => {
                const port = probe.address().port;
                probe.close(() => resolve(port));
            });
        });
        const baseURL = 'http://127.0.0.1:' + port;
        server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', path.join(root, 'src/frontend'), 'src/backend/tests/support/theme_router.php'],
            {cwd:root, stdio:'ignore', env:{...process.env, RM_THEME_TEST_DATABASE:database}});
        for (let i = 0; i < 100; i++) {
            try { if ((await fetch(baseURL)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        browser = await chromium.launch({headless:true});
        const context = await browser.newContext({baseURL, hasTouch:true, isMobile:true});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        await page.goto('/?login=1');
        await page.locator('#landing-login-username').waitFor({state:'visible'});
        await page.fill('#landing-login-username', data.staff.manager.username);
        await page.fill('#landing-login-password', data.staff.manager.password);
        await Promise.all([page.waitForURL('**/inventory_management/**'), page.getByRole('button', {name:'Log in', exact:true}).click()]);
        await page.goto(history);
        const dismissGuidance = page.getByRole('button', {name:'Not now', exact:true});
        if (await dismissGuidance.isVisible()) {
            await dismissGuidance.click();
            await dismissGuidance.waitFor({state:'hidden'});
        }
        const follow = async link => {
            const target = new URL(await link.getAttribute('href'), page.url()).href;
            await Promise.all([page.waitForURL(target), link.click()]);
        };
        assert.equal(await page.locator('main > header').getByRole('link', {
            name: /^(Record bought stock|Check stock|Review recommendations)$/
        }).count(), 0, 'receiving history header has no stock workflow shortcuts');
        assert.equal(await page.locator(entryRows).count(), 1, 'fixture contains one legacy receipt');
        assert.deepEqual(await page.locator('.history-list-table thead th').allTextContents(),
            ['Entry', 'Date & Time', 'Store', 'Receipt', 'Recorded by', 'Products', 'Receive']);
        for (let i = 0; i < 22; i++) {
            await page.goto('/components/report/stock_receiving.php', {waitUntil:'domcontentloaded'});
            const form = {
                csrf_token: await page.inputValue('[name="csrf_token"]'),
                receiving_nonce: await page.inputValue('[name="receiving_nonce"]'),
                entry_mode:'shopping_trip', supplier:'Long store ' + 'X'.repeat(100), invoice_number:token + '-' + i,
                'lines[0][product_id]':String(data.products.bulk_units.product_id),
                'lines[0][quantity_mode]':'units', 'lines[0][received_qty]':'2', 'lines[0][damaged_qty]':'1',
                'lines[0][cost_price]':'7.50',
                'lines[1][product_id]':String(data.products.bulk_pack.product_id),
                'lines[1][quantity_mode]':'units', 'lines[1][received_qty]':'3', 'lines[1][damaged_qty]':'0',
                receiving_complete:'1'
            };
            const response = await context.request.post('/components/report/stock_receiving.php', {form});
            assert((await response.text()).includes('Receiving entry saved.'), 'receipt persisted');
        }
        const quantities = spawnSync(process.env.PHP_BINARY || 'php', ['src/backend/tests/support/receiving_history_quantities.php', database, token], {cwd:root, encoding:'utf8'});
        assert.equal(quantities.status, 0, quantities.stderr);
        const large = fixture('--seed-large-history');
        const filteredHistory = history + '?length=10&q=' + token;
        fs.mkdirSync(artifacts, {recursive:true});
        await page.goto(filteredHistory);
        await page.setViewportSize({width:1280, height:900});
        assert(await page.locator(entryRows).filter({hasText:'Long store'}).first().locator('td').nth(1).evaluate(cell =>
            cell.scrollWidth > cell.clientWidth && getComputedStyle(cell).textOverflow === 'ellipsis'
        ), 'long store names stay on a single history row');
        await page.screenshot({path:path.join(artifacts, 'history-1280.png')});
        await page.setViewportSize({width:390, height:900});
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.screenshot({path:path.join(artifacts, 'history-390.png')});
        await page.setViewportSize({width:1280, height:900});
        assert.equal(await page.locator(entryRows).count(), 10);
        const firstIds = await page.locator(entryRows).evaluateAll(rows => rows.map(row => row.id));
        assert.equal(await page.locator(itemRows).count(), 0, 'list renders no item rows, even for a large entry');
        await page.locator('#entry_' + large.entry + ' a').first().focus();
        await page.keyboard.press('Enter');
        await page.waitForURL(url => url.searchParams.get('entry') === large.entry);
        assert.equal(await page.locator(itemRows).count(), 15, 'the detail starts at 15 items');
        assert.equal(await page.locator('.history-table th[aria-sort="ascending"]').innerText(), 'Product');
        assert.deepEqual((await page.locator('.history-table thead th').allTextContents()).map(text => text.replace(/\s+/g, ' ').trim()),
            ['Product', 'SKU', 'Receive', 'Purchase cost', 'Batch / Expiry']);
        assert(await page.locator('.history-table').evaluate(table => {
            const panel = table.closest('.u-table-scroll');
            return table.getBoundingClientRect().width <= panel.getBoundingClientRect().width + 1;
        }), 'five columns fit the desktop panel');
        await follow(page.getByRole('link', {name:'Receive', exact:true}));
        assert.equal((await page.locator(itemRows).first().locator('td').nth(2).innerText()).trim(), '1 unit');
        await follow(page.getByRole('link', {name:'Receive', exact:true}));
        assert.equal((await page.locator(itemRows).first().locator('td').nth(2).innerText()).trim(), '100 units', 'sort includes rows from later pages');
        await follow(page.getByRole('link', {name:'Next', exact:true}));
        assert.equal((await page.locator(itemRows).first().locator('td').nth(2).innerText()).trim(), '85 units');
        await Promise.all([
            page.waitForURL(url => url.searchParams.get('item_length') === '50'),
            page.getByLabel('Entries per page', {exact:true}).selectOption('50')
        ]);
        assert.equal(await page.locator(itemRows).count(), 50);
        await Promise.all([
            page.waitForURL(url => url.searchParams.get('item_length') === '100'),
            page.getByLabel('Entries per page', {exact:true}).selectOption('100')
        ]);
        assert.equal(await page.locator(itemRows).count(), 100);
        await follow(page.getByRole('link', {name:'Expiry', exact:true}));
        assert.match(await page.locator(itemRows).first().innerText(), /2030-01-01/);
        await follow(page.getByRole('link', {name:'Expiry', exact:true}));
        assert.match(await page.locator(itemRows).first().innerText(), /2030-01-02/);
        assert.match(await page.locator(itemRows).last().locator('td').nth(4).innerText(), /Not recorded/);
        await follow(page.getByRole('link', {name:'Back to History'}));

        await follow(page.getByRole('link', {name:/^Next/}));
        await page.waitForURL(url => url.searchParams.get('page') === '2');
        const secondIds = await page.locator(entryRows).evaluateAll(rows => rows.map(row => row.id));
        assert.equal(secondIds.length, 10);
        await follow(page.locator(entryRows).first().getByRole('link'));
        await follow(page.getByRole('link', {name:'Previous Entry'}));
        assert.equal(new URL(page.url()).searchParams.get('entry'), firstIds.at(-1).replace('entry_', ''));
        await follow(page.getByRole('link', {name:'Next Entry'}));
        assert.equal(new URL(page.url()).searchParams.get('entry'), secondIds[0].replace('entry_', ''));
        await follow(page.getByRole('link', {name:'Back to History'}));
        assert.equal(new URL(page.url()).searchParams.get('page'), '2');
        assert.equal(new URL(page.url()).searchParams.get('q'), token);
        await follow(page.getByRole('link', {name:/^Next/}));
        const thirdIds = await page.locator(entryRows).evaluateAll(rows => rows.map(row => row.id));
        assert.equal(thirdIds.length, 4);
        assert.equal(new Set([...firstIds, ...secondIds, ...thirdIds]).size, 24);
        assert.match(thirdIds.at(-1), /^entry_r_/, 'legacy lines are still listed');
        await follow(page.locator(entryRows).last().getByRole('link'));
        assert.equal(await page.locator(itemRows).count(), 1, 'legacy entries still open');
        assert.match(await page.locator(itemRows).first().locator('td').nth(2).innerText(), /14 units\s+2 packs × 6 \+ 2 loose/,
            'sales pack conversion uses good units, excluding historical damage');
        assert.equal(await page.getByRole('link', {name:'Next Entry'}).count(), 0);

        await page.goto(history + '?q=' + token + '-21');
        assert.equal(await page.locator(entryRows).count(), 1);
        await follow(page.locator(entryRows).getByRole('link'));
        assert.equal(await page.locator(itemRows).count(), 2);
        assert.match(await page.locator(itemRows).first().locator('td').first().innerText(), /Shopping Cases/);
        assert.match(await page.locator(itemRows).first().locator('td').nth(2).innerText(), /47 units\s+1 package × 24 \+ 23 loose/,
            'package conversion agrees with Receive when part of a historical carton was damaged');
        assert.match(await page.locator(itemRows).last().locator('td').nth(3).innerText(), /₱7\.50/);
        assert.equal(await page.getByRole('link', {name:'Next Entry'}).count(), 0, 'entry links respect the search');
        assert.equal((await context.request.get(history + '?q=absent-' + token + '&entry=' + large.entry)).status(), 404);
        assert(await page.evaluate(() => {
            const ids = [...document.querySelectorAll('[id]')].map(el => el.id);
            return new Set(ids).size === ids.length;
        }));
        for (const theme of ['light', 'dark']) {
            await page.locator('[data-account-menu-open]:visible').last().click();
            await page.locator('.sidebar-preferences-menu > summary').click();
            await page.locator('.sidebar-theme-control > summary').click();
            await page.locator('.sidebar-theme-control [data-theme-mode="' + theme + '"]').click();
            await page.waitForFunction(theme => document.documentElement.dataset.theme === theme, theme);
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => document.querySelector('#sidebarAccountMenu').getAttribute('aria-hidden') === 'true');
            await page.waitForFunction(() => document.getAnimations().every(a => a.playState !== 'running' || a.effect.getTiming().iterations === Infinity));
            const contrastFailures = await page.evaluate(() => {
                const rgb = color => (color.match(/[\d.]+/g) || []).map(Number);
                const luminance = color => rgb(color).slice(0, 3).map(c => c / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4)
                    .reduce((sum, c, i) => sum + c * [.2126, .7152, .0722][i], 0);
                const failures = [];
                for (const el of document.querySelectorAll('main *')) {
                    if (!el.getClientRects().length || ![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) continue;
                    const style = getComputedStyle(el);
                    let background = el;
                    while (background.parentElement && (rgb(getComputedStyle(background).backgroundColor)[3] ?? 1) === 0) background = background.parentElement;
                    const a = luminance(style.color), b = luminance(getComputedStyle(background).backgroundColor);
                    const ratio = (Math.max(a,b) + .05) / (Math.min(a,b) + .05);
                    const large = parseFloat(style.fontSize) >= 24 || (parseFloat(style.fontSize) >= 18.66 && Number(style.fontWeight) >= 700);
                    if (ratio < (large ? 3 : 4.5)) failures.push({text:el.textContent.slice(0,60), ratio});
                }
                return failures;
            });
            assert.deepEqual(contrastFailures, [], theme + ' main text contrast');
            for (const width of [1280, 390, 320]) {
                await page.setViewportSize({width, height:900});
                await page.evaluate(() => { window.scrollTo({top:0, behavior:'instant'}); document.querySelector('main').scrollTop = 0; });
                await page.waitForFunction(() => !document.querySelector('#sidebarAccountMenu').getAnimations().some(a => a.playState === 'running'));
                assert(await page.evaluate(() => {
                    const main = document.querySelector('main').getBoundingClientRect();
                    return document.documentElement.scrollWidth <= innerWidth && main.right <= innerWidth
                        && [...document.querySelectorAll('main .btn, #history_search')].filter(el => el.getClientRects().length).every(el => {
                            const b = el.getBoundingClientRect();
                            return b.height >= 44 && b.left >= 0 && b.right <= innerWidth;
                        });
                }), theme + ' layout and targets at ' + width);
                if (width !== 320) await page.screenshot({path:path.join(artifacts, theme + '-' + width + '.png')});
            }
            const scrollRegion = page.locator('.u-table-scroll');
            await scrollRegion.scrollIntoViewIfNeeded();
            const bounds = await scrollRegion.boundingBox();
            const cdp = await context.newCDPSession(page);
            const start = bounds.x + bounds.width - 20, y = bounds.y + 50;
            await cdp.send('Input.dispatchTouchEvent', {type:'touchStart', touchPoints:[{x:start, y}]});
            // Chromium needs separate rendered frames to recognise a native touch scroll.
            for (let i = 1; i <= 6; i++) {
                await new Promise(resolve => setTimeout(resolve, 40));
                await cdp.send('Input.dispatchTouchEvent', {type:'touchMove', touchPoints:[{x:start - i * 25, y}]});
            }
            await cdp.send('Input.dispatchTouchEvent', {type:'touchEnd', touchPoints:[]});
            await page.waitForFunction(() => document.querySelector('.u-table-scroll').scrollLeft > 0);
            await cdp.detach();
            await page.setViewportSize({width:1280, height:900});
            console.log(theme + ' contrast, responsive layouts and screenshots passed.');
        }
        await follow(page.getByRole('link', {name:'Back to History'}));
        await page.fill('#history_search', 'absent-' + token);
        await page.getByRole('button', {name:'Search', exact:true}).click();
        await page.getByRole('heading', {name:'No matching receipts'}).waitFor();
        await follow(page.getByRole('link', {name:'Clear search'}));
        await page.waitForURL('**/receiving_history.php');
        assert.equal(await page.locator(entryRows).count(), 15);
        await page.goto(history + '?q=' + 'x'.repeat(201));
        await page.waitForFunction(() => [...document.querySelectorAll('.message.error, .swal2-html-container')]
            .some(el => el.getClientRects().length && el.textContent.includes('200 characters')));
        await page.goto(history + '?q=' + encodeURIComponent('商'.repeat(100)));
        assert(await page.getByRole('heading', {name:'No matching receipts'}).isVisible());
        await page.goto(history);
        await page.locator('.history-skip-link').focus();
        await page.keyboard.press('Enter');
        assert(await page.locator('main').evaluate(el => el === document.activeElement));
        await page.evaluate(() => document.documentElement.style.fontSize = '200%');
        assert(await page.evaluate(() => document.querySelector('main').getBoundingClientRect().right <= innerWidth));
        await page.evaluate(() => document.documentElement.style.fontSize = '');
        console.log('Receiving history: 100-item sort and paging, filtered entry navigation, legacy entries, keyboard, themes and responsive layouts passed.');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        if (data) fixture('--cleanup');
        databaseFixture('cleanup');
    }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
