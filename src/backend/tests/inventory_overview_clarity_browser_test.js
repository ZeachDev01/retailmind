const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/inventory_overview.php'), 'utf8');
const template = source.slice(source.indexOf('<body')).replace(/<\?php include[^?]+\?>/g, '');
const html = execFileSync(process.env.PHP_BINARY || 'php', [], {encoding: 'utf8', input: `<?php
    function app_url($path) { return $path; }
    $products = [];
    foreach ([20, 2, 0] as $id => $quantity) {
        $products[] = ['product_id' => $id + 1, 'product_name' => ['Rice', 'Coffee', 'Milk'][$id],
            'sku' => 'SKU-' . $id, 'barcode' => '123456789' . $id, 'brand' => 'Shalom',
            'category_name' => 'Food', 'quantity_on_hand' => $quantity, 'reorder_level' => 5,
            'unit_price' => 30, 'cost_price' => 20, 'next_expiration' => null,
            'has_missing_expiration' => $id === 1, 'undated_quantity' => $id === 1 ? 2 : 0];
    }
    $total_products = 3; $total_units = 22; $out_of_stock = 1; $missing_expiration_count = 1;
    $low_stock = [$products[1], $products[2]]; $lowStockProductIds = [2 => true, 3 => true];
    $expiring_batches = $expired_batches = $expiredProductIds = $expiringProductIds = [];
    $forecastProducts = $topForecastProducts = $recent_movements = $fefo_recommendations = [];
    $productCategories = ['Food']; $chartDataJson = '{}';
?>${template}`});

(async () => {
    const browser = await chromium.launch({headless: true});
    const output = path.resolve('output/playwright/inventory-overview');
    fs.mkdirSync(output, {recursive: true});
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.setContent('<!doctype html><html><head></head>' + html);
            for (const file of ['theme.css', 'style.css', 'inventory.css']) {
                await page.addStyleTag({path: path.join(frontend, 'assets/css', file)});
            }
            // The shared shell normally loads this before the page script.
            await page.evaluate(() => { window.RetailMindUI = {}; });
            await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
            assert.equal(await page.locator('[data-overview-product-row]:visible').count(), 3);
            const search = page.getByLabel('Search products', {exact: true});
            assert.equal(await page.locator('button.overview-metric').count(), 7, 'Original summary cards remain');
            assert.equal(await page.locator('.overview-metric-icon').count(), 7, 'Original card icons remain');
            assert.equal(await page.locator('.page-subtitle, .overview-chart-header p, .overview-section-header p, .overview-chart-badge').count(), 0, 'Redundant subtext and counts are removed');
            assert.ok((await page.locator('.overview-charts-grid').boundingBox()).y < (await search.boundingBox()).y, 'Original chart-first layout remains');
            await search.fill('Coffee');
            assert.equal(await page.locator('[data-overview-product-row]:visible').count(), 1);
            await search.fill('No matching barcode');
            assert.equal(await page.locator('#overview-product-empty').isVisible(), true);
            await search.fill('');
            await page.locator('.overview-metric[data-overview-view="out"]').click();
            assert.equal(await page.locator('[data-overview-product-row]:visible').innerText().then(text => text.includes('Milk')), true);
            await page.locator('#overview-filter-select').selectOption('missing-exp');
            assert.equal(await page.locator('.overview-col-undated').first().isVisible(), true);
            assert.equal(await page.locator('[data-overview-product-row]:visible').count(), 1);
            await page.getByRole('button', {name: 'View stock movements', exact: true}).click();
            assert.equal(await page.locator('#overview-default-view').isVisible(), true);
            assert.equal(await page.evaluate(() => document.activeElement.id), 'overview-tab-movements');
            await page.getByRole('tab', {name: 'Recent Stock Movements', exact: true}).press('ArrowRight');
            assert.equal(await page.locator('#overview-panel-fefo').isVisible(), true);
            await page.locator('.overview-metric[data-overview-view="all"]').click();
            assert.equal(await page.locator('#overview-product-view').isVisible(), true);
            assert.equal(await page.locator('#overview-filter-select').inputValue(), 'all');
            for (const theme of ['light', 'dark']) {
                await page.locator('html').evaluate((el, theme) => { el.dataset.theme = theme; }, theme);
                await page.evaluate(() => window.scrollTo(0, 0));
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px: no page overflow`);
                await page.screenshot({path: path.join(output, `${theme}-${width}.png`), fullPage: true, animations: 'disabled'});
            }
            assert.equal(await page.locator('#overviewStatusChart').count(), 0, 'Offline charts present their fallback');
            assert.equal(await page.locator('.overview-chart-fallback').first().isVisible(), true, 'Charts remain visible');
            const returnRefreshes = await page.evaluate(() => {
                let calls = 0;
                window.RetailMindNavigation = {navigate(url, options) {
                    if (url === location.href && options.history === 'none') calls++;
                }};
                window.dispatchEvent(new Event('blur'));
                window.dispatchEvent(new Event('focus'));
                window.dispatchEvent(new Event('focus'));
                return calls;
            });
            assert.equal(returnRefreshes, 1, 'Returning to inventory fetches a fresh view once without adding history');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Inventory overview clarity: passed');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
