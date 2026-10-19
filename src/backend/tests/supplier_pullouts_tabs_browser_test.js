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
        $products = [['product_id' => 1, 'product_name' => 'Test product', 'sku' => 'SKU-1', 'quantity_on_hand' => 40]];
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
                    const asset = path.join(frontend, url.pathname);
                    return route.fulfill({path: asset});
                });
                await page.goto('http://retailmind.test/');
                assert.equal(await page.locator('main#supplier-pullouts').count(), 1, 'Style scope travels with the main content during partial navigation');
                await page.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
                await page.evaluate(() => RetailMindUI.initPageContent());
                await page.locator('.rm-toast-close').evaluateAll(buttons => buttons.forEach(button => button.click()));
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
                    assert.equal(await page.locator('#reason').evaluate(el => getComputedStyle(el).boxShadow !== 'none'), true, 'Focused input has a theme focus ring');
                }
                await history.click();
                assert.equal(await history.getAttribute('aria-selected'), 'true');
                assert.equal(await history.evaluate(el => getComputedStyle(el).borderBottomColor), 'rgb(37, 99, 235)');
                assert.equal(await page.locator('#pullout-record-panel').isVisible(), false);
                await page.getByRole('button', {name: 'Page 2', exact: true}).click();
                const search = page.getByRole('searchbox', {name: 'Search table'});
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
