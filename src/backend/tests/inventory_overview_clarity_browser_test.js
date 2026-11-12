const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/inventory_overview.php'), 'utf8');
const riskView = fs.readFileSync(path.join(frontend, 'components/inventory_management/stock_risk.php'), 'utf8');
const template = source.slice(source.indexOf('<body'))
    .replace("<?php include __DIR__ . '/stock_risk.php'; ?>", riskView)
    .replace(/<\?php include[^?]+\?>/g, '')
    .replace(/^<script src=.*stock-risk\.js.*<\/script>\r?$/gm, '');
const html = execFileSync(process.env.PHP_BINARY || 'php', [], {encoding: 'utf8', input: `<?php
    function app_url($path) { return $path; }
    function format_display_datetime($value) { return $value; }
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
    foreach (['Rice', 'Coffee', 'Milk', 'Soap', 'Oil', 'Biscuits'] as $i => $name) {
        $recent_movements[] = ['product_name' => $name, 'sku' => 'SKU-' . $i, 'change_qty' => 3 - $i,
            'reason' => 'adjustment', 'moved_by_name' => 'Inventory Manager', 'moved_at' => sprintf('2026-11-%02d 10:00:00', 11 - $i)];
    }
    $productCategories = ['Food']; $chartDataJson = '{}';
    $riskRows = [];
    foreach (range(1, 13) as $i) {
        $riskRows[] = ['product_id' => 100 + $i, 'product_name' => 'Risk Product ' . $i, 'sku' => 'RISK-' . $i,
            'category_name' => $i % 2 ? 'Personal Care' : 'Beverages', 'quantity_on_hand' => $i * 10,
            'units30' => $i, 'units90' => $i * 3, 'days_of_stock' => $i === 2 ? null : 300,
            'saleable_stock' => $i * 10, 'flags' => [$i === 2 ? 'limited' : 'excess'],
            'risk_kind' => $i === 2 ? 'limited' : 'excess', 'risk_label' => $i === 2 ? 'Limited history' : 'Possible excess',
            'suggested_action' => 'Review stock before buying more.', 'history_note' => 'Recorded history.',
            'opening_balance' => $i * 10 + 2, 'stock_in90' => 8, 'stock_out90' => 10, 'expired_quantity' => 0, 'expiring_quantity' => 0];
    }
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
            for (const file of ['theme.css', 'style.css', 'inventory.css', 'stock-risk.css']) {
                await page.addStyleTag({path: path.join(frontend, 'assets/css', file)});
            }
            const iconCss = fs.readFileSync(path.join(frontend, 'assets/vendor/bootstrap-icons/bootstrap-icons.min.css'), 'utf8');
            const iconFont = fs.readFileSync(path.join(frontend, 'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2')).toString('base64');
            await page.addStyleTag({content: iconCss + `\n@font-face{font-family:bootstrap-icons;src:url(data:font/woff2;base64,${iconFont}) format('woff2')}`});
            await page.addScriptTag({path: path.join(frontend, 'assets/js/stock-risk.js')});
            // The shared shell normally loads this before the page script.
            await page.evaluate(() => { window.RetailMindUI = {}; });
            await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
            assert.equal(await page.locator('#overview-panel-movements').isVisible(), true, 'Movements are visible on first entry');
            assert.ok((await page.locator('#overview-default-view').boundingBox()).y > (await page.locator('#overview-charts').boundingBox()).y, 'Movements appear below the overview');
            await page.locator('[data-movement-sort="0"]').click();
            assert.match(await page.locator('#overview-panel-movements tbody tr:visible').first().innerText(), /Biscuits/);
            await page.locator('[data-movement-sort="1"]').click();
            assert.equal(await page.locator('#overview-panel-movements tbody tr:visible').first().locator('td').nth(1).innerText(), '-2', 'Quantity sorting is numeric');
            await page.locator('#movement-page-next').click();
            await page.locator('[data-movement-sort="1"]').click();
            assert.equal(await page.locator('#movement-page-status').innerText(), 'Page 1 of 2', 'Sorting resets pagination');
            assert.equal(await page.locator('[data-movement-sort="1"]').evaluate(button => button.closest('th').getAttribute('aria-sort')), 'descending');
            await page.locator('.overview-metric[data-overview-view="all"]').click();
            assert.equal(await page.locator('[data-overview-product-row]:visible').count(), 3);
            const search = page.getByLabel('Search products', {exact: true});
            assert.equal(await page.locator('button.overview-metric').count(), 7, 'Original summary cards remain');
            assert.equal(await page.locator('.overview-metric-icon').count(), 7, 'Original card icons remain');
            assert.equal(await page.locator('.page-subtitle, .overview-chart-header p, .overview-section-header p, .overview-chart-badge').count(), 0, 'Redundant subtext and counts are removed');
            await search.fill('Coffee');
            assert.equal(await page.locator('[data-overview-product-row]:visible').count(), 1);
            await search.fill('No matching barcode');
            assert.equal(await page.locator('#overview-product-empty').isVisible(), true);
            await search.fill('');
            await page.locator('#overview-filter-select').selectOption('out');
            assert.equal(await page.locator('[data-overview-product-row]:visible').innerText().then(text => text.includes('Milk')), true);
            await page.locator('#overview-filter-select').selectOption('missing-exp');
            assert.equal(await page.locator('.overview-col-undated').first().isVisible(), true);
            assert.equal(await page.locator('[data-overview-product-row]:visible').count(), 1);
            await page.getByRole('button', {name: 'Return to Stock Overview', exact: true}).click();
            assert.equal(await page.locator('#overview-default-view').isVisible(), true);
            assert.equal(await page.evaluate(() => document.activeElement.id), 'overview-tab-movements');
            await page.getByRole('tab', {name: 'Recent Stock Movements', exact: true}).press('ArrowRight');
            assert.equal(await page.locator('#overview-panel-fefo').isVisible(), true);
            await page.locator('.overview-metric[data-overview-view="all"]').click();
            assert.equal(await page.locator('#overview-product-view').isVisible(), true);
            assert.equal(await page.locator('#overview-filter-select').inputValue(), 'all');
            await page.locator('#overview-back').click();
            await page.getByRole('tab', {name: 'Recent Stock Movements', exact: true}).click();
            assert.equal(await page.locator('#overview-panel-movements').isVisible(), true, 'Summary returns with movements visible');
            await page.getByRole('tab', {name: 'Stock Risk', exact: true}).click();
            assert.equal(await page.locator('#overview-risk').isVisible(), true);
            for (const id of ['overview-metrics', 'overview-charts', 'overview-forecast', 'overview-default-view', 'overview-product-view']) {
                assert.equal(await page.locator('#' + id).isVisible(), false, id + ' hides while comparing Stock Risk');
            }
            assert.equal(await page.locator('[data-risk-row]:visible').count(), 10);
            await page.getByRole('button', {name:'Next Stock Risk page', exact:true}).click();
            assert.equal(await page.locator('[data-risk-row]:visible').count(), 3);
            await page.getByRole('button', {name:/Limited history/}).click();
            assert.equal(await page.locator('[data-risk-row]:visible').count(), 1);
            await page.getByRole('button', {name:'View details for Risk Product 2',exact:true}).click();
            assert.equal(await page.locator('#risk-detail-102').isVisible(), true);
            await page.getByLabel('Search Stock Risk products', {exact:true}).fill('missing');
            assert.equal(await page.locator('#risk-empty').isVisible(),true);
            await page.getByLabel('Search Stock Risk products', {exact:true}).fill('');
            await page.getByRole('button', {name:/All products/}).click();
            if (width < 640) {
                await page.getByLabel('Sort products',{exact:true}).selectOption('stock');
            } else {
                await page.getByRole('button', {name:'Recorded stock',exact:true}).click();
                await page.getByRole('button', {name:'Recorded stock',exact:true}).click();
            }
            assert.equal(await page.locator('[data-risk-row]:visible').first().getAttribute('data-stock'),'130');
            assert.equal(await page.locator('[data-risk-sort="stock"]').evaluate(el=>el.closest('th').getAttribute('aria-sort')),'descending');
            for (const theme of ['light','dark']) {
                await page.locator('html').evaluate((el, value)=>{el.dataset.theme=value;},theme);
                await page.evaluate(()=>window.scrollTo(0,0));
                await page.locator('.sr-table-scroll').evaluate(el=>{el.scrollLeft=0;});
                assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth),'Stock Risk has no page overflow');
                await page.screenshot({path:path.join(output, `stock-risk-${theme}-${width}.png`),fullPage:true});
            }
            await page.getByRole('tab', {name:'Stock Risk',exact:true}).focus();
            await page.keyboard.press('Home');
            assert.equal(await page.getByRole('tab',{name:'Stock Overview',exact:true}).getAttribute('aria-selected'),'true');
            assert.equal(await page.locator('#overview-risk').isVisible(),false);
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
