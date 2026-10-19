const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const file = path.join(frontend, 'components/inventory_management/supplier_pullouts.php');
const source = fs.readFileSync(file, 'utf8');
// Render the real template with display fixtures; no database or POST handler runs.
function render(canMutate) {
    const php = `
        function retailmind_theme_head() {}
        function app_url($url) { return '/' . $url; }
        function generate_csrf_token() { return 'test-token'; }
        $canMutate = ${canMutate ? 'true' : 'false'};
        $message = $error = '';
        $prefillProductId = $prefillBatchId = 0;
        $prefillBatch = null;
        $products = [['product_id' => 1, 'product_name' => 'Test product', 'sku' => 'SKU-1', 'pack_size' => 1, 'quantity_on_hand' => 40], ['product_id' => 2, 'product_name' => 'Pack product', 'sku' => 'SKU-2', 'pack_size' => 6, 'quantity_on_hand' => 27]];
        $pulloutHistory = array_fill(0, 30, [
            'pullout_id' => 1, 'product_id' => 1, 'batch_id' => 1,
            'pulled_at' => '2026-10-19 10:00:00', 'product_name' => 'Test product',
            'sku' => 'SKU-1', 'batch_number' => 'BATCH-1', 'quantity' => 10,
            'reason' => 'Expired stock', 'pulled_by_name' => 'Inventory Manager'
        ]);
        eval('?>' . file_get_contents('php://stdin'));
    `;
    const template = source.slice(source.indexOf('<!DOCTYPE html>'))
        .replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '')
        .replaceAll('__DIR__', JSON.stringify(path.dirname(file).replaceAll('\\', '/')));
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', php], {input: template, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stderr, '');
    return result.stdout;
}

(async () => {
    const browser = await chromium.launch({headless: true});
    const screenshots = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-pullout-tabs-'));
    try {
        for (const width of [1280, 390]) {
            for (const canMutate of [true, false]) {
                const page = await browser.newPage({viewport: {width, height: 900}});
                page.setDefaultTimeout(5000);
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('http://retailmind.test/**', route => {
                    const url = new URL(route.request().url());
                    if (url.pathname === '/') return route.fulfill({contentType: 'text/html', body: render(canMutate)});
                    if (url.searchParams.get('action') === 'get_batches') return route.fulfill({contentType: 'application/json', body: JSON.stringify([{batch_id: 20, batch_number: 'BATCH-PACK-20', remaining_quantity: 27, sealed_quantity: 4, loose_quantity: 3, expiration_date: '2026-12-01', supplier: 'PackCo'}])});
                    const asset = path.join(frontend, url.pathname);
                    return route.fulfill({path: asset});
                });
                await page.goto('http://retailmind.test/');
                assert.equal(await page.locator('main#supplier-pullouts').count(), 1, 'Style scope travels with the main content during partial navigation');
                await page.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
                await page.evaluate(() => RetailMindUI.initPageContent());
                await page.locator('.rm-toast-close').evaluateAll(buttons => buttons.forEach(button => button.click()));
                assert.equal(await page.locator('main [placeholder]').count(), 0, 'All fields, including generated search, have no placeholders');
                const record = page.getByRole('tab', {name: 'Record Pullout', exact: true});
                const history = page.getByRole('tab', {name: 'Pullout History', exact: true});
                assert.equal(await record.getAttribute('aria-selected'), 'true');
                assert.equal(await page.locator('#pullout-history-panel').isVisible(), false);
                assert.equal(await page.locator('#pullout-form').count(), canMutate ? 1 : 0);
                if (canMutate) {
                    await page.locator('#reason').fill('Supplier recall');
                    const columns = await page.locator('#pullout-form').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length);
                    assert.equal(columns, width === 390 ? 1 : 2);
                    assert.equal(await page.locator('#reason').evaluate(el => el.closest('form').contains(el)), true);
                    assert.equal(await page.locator('#pullout-form > div').count(), 7, 'Every field and submit action stays in the grid');
                    for (const theme of ['light', 'dark']) {
                        await page.addStyleTag({path: path.join(frontend, 'assets/css/theme.css')});
                        await page.locator('html').evaluate((el, theme) => { el.dataset.theme = theme; }, theme);
                        await page.locator('#reason').focus();
                        const focus = await page.locator('#reason').evaluate(el => {
                            const style = getComputedStyle(el);
                            return {outline: style.outlineStyle, width: style.outlineWidth, height: el.getBoundingClientRect().height};
                        });
                        assert.equal(focus.outline, 'solid', 'Focused fields have a visible outline');
                        assert.equal(focus.width, '2px');
                        assert.ok(focus.height >= 48, 'Fields have comfortable touch targets');
                        assert.equal(await page.locator('label[for="reason"]').evaluate(el => getComputedStyle(el).color), theme === 'dark' ? 'rgb(96, 165, 250)' : 'rgb(37, 99, 235)', 'Active label uses the theme action color');
                        await page.locator('#reason').blur();
                        const border = await page.locator('#quantity').evaluate(el => getComputedStyle(el).borderColor);
                        assert.equal(border, theme === 'dark' ? 'rgb(130, 149, 177)' : 'rgb(113, 128, 150)', 'Blank fields retain strong borders');
                        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                        await page.screenshot({path: path.join(screenshots, `${width}-${theme}-fields.png`)});
                    }
                    await page.locator('html').evaluate(el => { el.dataset.theme = 'light'; });
                }
                await history.click();
                assert.equal(await history.getAttribute('aria-selected'), 'true');
                assert.equal(await history.evaluate(el => getComputedStyle(el).borderBottomColor), 'rgb(37, 99, 235)');
                assert.equal(await page.locator('#pullout-record-panel').isVisible(), false);
                await page.getByRole('button', {name: 'Page 2', exact: true}).click();
                const search = page.getByRole('searchbox', {name: 'Search pullouts'});
                await search.fill('Test product');
                await page.waitForTimeout(200);
                await page.getByRole('button', {name: 'Page 2', exact: true}).click();
                const scroll = page.locator('#pullout-history-panel .table-wrap');
                await scroll.evaluate(el => { el.scrollLeft = 150; });
                const scrollLeft = await scroll.evaluate(el => el.scrollLeft);
                await record.click();
                if (canMutate) assert.equal(await page.locator('#reason').inputValue(), 'Supplier recall');
                await record.press('ArrowRight');
                assert.equal(await history.evaluate(el => el === document.activeElement), true);
                assert.equal(await page.getByRole('button', {name: 'Page 2', exact: true}).getAttribute('aria-current'), 'page');
                assert.equal(await search.inputValue(), 'Test product');
                assert.equal(await scroll.evaluate(el => el.scrollLeft), scrollLeft);
                assert.equal(await page.locator('th').nth(5).evaluate(el => getComputedStyle(el).textAlign), 'right');
                assert.equal(await page.locator('th').nth(5).evaluate(el => getComputedStyle(el).fontVariantNumeric), 'tabular-nums');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                if (width === 390) assert.equal(await scroll.evaluate(el => el.scrollWidth > el.clientWidth), true);
                await history.press('Home');
                assert.equal(await record.getAttribute('aria-selected'), 'true');
                await record.press('ArrowLeft');
                assert.equal(await history.getAttribute('aria-selected'), 'true');
                if (canMutate) {
                    await page.screenshot({path: path.join(screenshots, `${width}-history.png`)});
                    await history.press('Home');
                    await page.locator('#product_id').selectOption('2');
                    await page.waitForFunction(() => document.querySelectorAll('#batch_id option').length > 1);
                    await page.locator('#batch_id').selectOption('20');
                    assert.equal(await page.locator('#pullout-pack-group').isVisible(), true, 'Pack form controls become visible for pack-enabled product');
                    await page.locator('#pulled_packs').fill('2');
                    await page.locator('#pulled_loose').fill('3');
                    assert.equal(await page.locator('#quantity').inputValue(), '15', 'Total base units syncs to 2*6 + 3 = 15');
                    await page.screenshot({path: path.join(screenshots, `${width}-record.png`)});
                }
                assert.deepEqual(errors, []);
                await page.close();
            }
        }
        console.log(`Supplier pullout tabs: passed; screenshots: ${screenshots}`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
