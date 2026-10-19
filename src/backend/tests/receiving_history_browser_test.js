'use strict';
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const net = require('node:net');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') throw new Error('Set RUN_DB_TESTS=1 for disposable database fixtures.');
const root = path.resolve(__dirname, '../../..');
const token = 'rh' + Date.now().toString(36);
const fixturePath = path.join(__dirname, 'support/scan_workflow_fixture.php');
const artifacts = path.join(root, 'output/playwright/receiving-history');
const history = '/components/report/receiving_history.php';
function fixture(action) {
    const run = spawnSync(process.env.PHP_BINARY || 'php', [fixturePath, action, '--token', token], {cwd:root, encoding:'utf8'});
    assert.equal(run.status, 0, run.stderr);
    return JSON.parse(run.stdout.trim().split('\n').at(-1));
}
async function main() {
    let server, browser, data;
    try {
        data = fixture('--create');
        const port = await new Promise(resolve => {
            const probe = net.createServer().listen(0, '127.0.0.1', () => {
                const port = probe.address().port;
                probe.close(() => resolve(port));
            });
        });
        const baseURL = 'http://127.0.0.1:' + port;
        server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', path.join(root, 'src/frontend')], {cwd:root, stdio:'ignore'});
        for (let i = 0; i < 100; i++) {
            try { if ((await fetch(baseURL)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        browser = await chromium.launch({headless:true});
        const context = await browser.newContext({baseURL, hasTouch:true, isMobile:true});
        const page = await context.newPage();
        await page.goto('/?login=1');
        await page.waitForFunction(() => document.activeElement?.id === 'landing-login-username');
        await page.fill('#landing-login-username', data.staff.manager.username);
        await page.fill('#landing-login-password', data.staff.manager.password);
        await Promise.all([page.waitForURL('**/inventory_management/**'), page.getByRole('button', {name:'Log in', exact:true}).click()]);
        await page.goto(history);
        assert.equal(await page.locator('.receiving-entry-card').count(), 1, 'fixture contains one legacy receipt');
        for (let i = 0; i < 22; i++) {
            await page.goto('/components/report/stock_receiving.php');
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
            assert((await response.text()).includes('Shopping entry saved.'), 'receipt persisted');
        }
        await page.goto(history);
        assert.equal(await page.locator('.receiving-entry-card').count(), 10);
        console.log('Bounded history and fixture creation passed.');
        const firstIds = await page.locator('.receiving-entry-card').evaluateAll(cards => cards.map(card => card.id));
        await page.getByRole('link', {name:'Next page'}).click();
        await page.waitForURL('**page=2');
        assert.equal(await page.locator('.receiving-entry-card').count(), 10);
        assert((await page.locator('.receiving-entry-card').evaluateAll(cards => cards.map(card => card.id))).every(id => !firstIds.includes(id)));
        const secondIds = await page.locator('.receiving-entry-card').evaluateAll(cards => cards.map(card => card.id));
        await page.getByRole('link', {name:'Next page'}).click();
        await page.waitForURL('**page=3');
        assert.equal(await page.locator('.receiving-entry-card').count(), 3);
        const thirdIds = await page.locator('.receiving-entry-card').evaluateAll(cards => cards.map(card => card.id));
        assert.equal(new Set([...firstIds, ...secondIds, ...thirdIds]).size, 23, 'all receipts appear once across three pages');
        assert.equal(await page.getByRole('link', {name:'Next page'}).count(), 0);
        assert.equal(await page.locator('.history-pagination [aria-current="page"]').textContent(), 'Page 3 · 10 per page');
        await page.getByRole('link', {name:'Previous page'}).click();
        await page.waitForURL('**page=2');
        await page.fill('#history_search', token + '-21');
        await page.getByRole('button', {name:'Search', exact:true}).click();
        await page.waitForURL('**?q=' + token + '-21');
        assert.equal(await page.locator('.receiving-entry-card').count(), 1);
        assert.equal(await page.locator('.history-table tbody tr').count(), 2);
        const summary = page.locator('.receiving-entry-header');
        assert.equal(await summary.getAttribute('aria-expanded'), 'false', 'receipts start collapsed');
        assert.equal(await page.locator('.history-toggle-label').textContent(), 'View Details');
        await summary.first().focus();
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.querySelector('.receiving-entry-header').getAttribute('aria-expanded') === 'true');
        await page.waitForFunction(() => document.getAnimations().every(a => a.playState !== 'running'));
        assert.equal(await page.locator('.history-toggle-label').textContent(), 'Hide Details');
        assert.equal((await page.locator('.history-table tbody tr').first().locator('td').nth(6).textContent()).trim(), '₱7.50');
        await page.keyboard.press('Space');
        await page.waitForFunction(() => !document.querySelector('.receiving-entry-card').open);
        await summary.click();
        await page.waitForFunction(() => document.getAnimations().every(a => a.playState !== 'running'));
        await summary.focus();
        await page.keyboard.press('Shift');
        assert(await summary.evaluate(el => getComputedStyle(el).outlineStyle !== 'none'));
        await page.emulateMedia({reducedMotion:'reduce'});
        await summary.click();
        assert.equal(await page.locator('.receiving-entry-card').getAttribute('open'), null, 'reduced motion closes immediately');
        await summary.click();
        assert.equal(await summary.getAttribute('aria-expanded'), 'true');
        await page.emulateMedia({reducedMotion:'no-preference'});
        assert(await page.evaluate(() => {
            const ids = [...document.querySelectorAll('[id]')].map(el => el.id);
            return new Set(ids).size === ids.length;
        }));
        fs.mkdirSync(artifacts, {recursive:true});
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
                        && [...document.querySelectorAll('main .btn, #history_search')].every(el => {
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
        await page.fill('#history_search', 'absent-' + token);
        await page.getByRole('button', {name:'Search', exact:true}).click();
        await page.getByRole('heading', {name:'No matching receipts'}).waitFor();
        await page.getByRole('link', {name:'Clear search'}).click();
        await page.waitForURL('**/receiving_history.php');
        assert.equal(await page.locator('.receiving-entry-card').count(), 10);
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
        await context.setOffline(true);
        await summary.first().focus();
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.querySelector('.receiving-entry-card').open);
        assert.equal(await summary.first().getAttribute('aria-expanded'), 'true');
        console.log('Receiving history: empty search, 22 new receipts plus legacy receipt, pagination, keyboard, themes, and 320/390/1280px layouts passed.');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        if (data) fixture('--cleanup');
    }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
