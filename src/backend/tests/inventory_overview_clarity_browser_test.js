const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/inventory_overview.php'), 'utf8');
const statusHelp = fs.readFileSync(path.join(frontend, 'components/inventory_management/stock_movement_status_help.php'), 'utf8');
const riskView = fs.readFileSync(path.join(frontend, 'components/inventory_management/stock_risk.php'), 'utf8')
    .replaceAll("include __DIR__ . '/stock_movement_status_help.php';", '?>' + statusHelp + '<?php');
const template = source.slice(source.indexOf('<body'))
    .replace("<?php include __DIR__ . '/stock_risk.php'; ?>", riskView)
    .replace(/<\?php include[^?]+\?>/g, '')
    .replace(/^<script src=.*stock-risk\.js.*<\/script>\r?$/gm, '');
const html = execFileSync(process.env.PHP_BINARY || 'php', [], {encoding: 'utf8', input: `<?php
    require_once ${JSON.stringify(path.resolve(__dirname, '../bootstrap/app.php').replaceAll('\\', '/'))};
    function app_url($path) { return $path; }
    function format_display_datetime($value) { return $value; }
    $products = [];
    foreach ([20, 2, 0, 8] as $id => $quantity) {
        $products[] = ['product_id' => $id + 1, 'product_name' => ['Rice', 'Coffee', 'Milk', 'Oil'][$id],
            'sku' => 'SKU-' . $id, 'barcode' => '123456789' . $id, 'brand' => 'Shalom',
            'category_name' => 'Food', 'quantity_on_hand' => $quantity, 'reorder_level' => 5,
            'unit_price' => 30, 'cost_price' => 20, 'next_expiration' => null,
            'has_missing_expiration' => $id === 1, 'undated_quantity' => $id === 1 ? 2 : 0];
    }
    $total_products = 4; $total_units = 30; $out_of_stock = 1; $missing_expiration_count = 1;
    $low_stock = [$products[1], $products[2]]; $lowStockProductIds = [2 => true, 3 => true];
    $expiring_batches = $expired_batches = $expiredProductIds = $expiringProductIds = [];
    $recent_movements = $fefo_recommendations = [];
    foreach (['Rice', 'Coffee', 'Milk', 'Soap', 'Oil', 'Biscuits'] as $i => $name) {
        $recent_movements[] = ['product_name' => $name, 'sku' => 'SKU-' . $i, 'change_qty' => 3 - $i,
            'reason' => 'adjustment', 'moved_by_name' => 'Inventory Manager', 'moved_at' => sprintf('2026-11-%02d 10:00:00', 11 - $i)];
    }
    $productCategories = ['Food']; $chartDataJson = '{}';
    $_SESSION['display_name'] = 'Inventory Reviewer <test>';
    $movementPeriod = \\App\\Inventory\\StockMovementReader::period('2026-11-12');
    $riskRows = [];
    foreach (range(1, 13) as $i) {
        $kind = $i === 1 ? 'dead' : ($i === 2 ? 'limited' : ($i === 3 || $i === 13 ? 'stockout' : ($i >= 10 ? 'fast' : 'slow')));
        $days = $i === 1 ? 0 : $i;
        $riskRows[] = ['product_id' => 100 + $i, 'product_name' => 'Movement Product ' . $i, 'sku' => 'MOVE-' . $i,
            'category_name' => $i % 2 ? 'Personal Care' : 'Beverages', 'quantity_on_hand' => $i === 13 ? 0 : $i * 10,
            'created_at' => $i === 2 ? '2026-10-29' : '2026-01-01',
            'period_sales' => [$i === 1 ? 0 : $i * 3, $i, $i], 'period_days' => [$days, $i === 1 ? 0 : 1, $i === 1 ? 0 : 2],
            'recent_sales' => $i === 1 ? 0 : $i * 3, 'selling_days' => $days, 'total_sales' => $i === 1 ? 0 : $i * 5,
            'trend' => in_array($kind, ['limited','stockout']) ? null : ($i === 1 ? 0 : 1), 'previous_average' => $i === 1 ? 0 : 1.5,
            'movement_kind' => $kind, 'movement_label' => ['dead'=>'Dead Stock','limited'=>'Limited history','stockout'=>'Stockout affected','fast'=>'Fast-Moving','slow'=>'Slow-Moving'][$kind],
            'history_note' => 'Recorded history.', 'balance' => $i * 10 + 2, 'remaining' => $i === 13 ? 0 : $i * 10,
            'received' => 8, 'sale_out' => -10, 'returns' => 2, 'other' => -2];
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
            assert.equal(await page.locator('#overview-forecast').count(), 0, 'Stock Movement replaces the forecast comparison');
            for (const file of ['theme.css', 'style.css', 'inventory.css', 'stock-risk.css']) {
                await page.addStyleTag({path: path.join(frontend, 'assets/css', file)});
            }
            const iconCss = fs.readFileSync(path.join(frontend, 'assets/vendor/bootstrap-icons/bootstrap-icons.min.css'), 'utf8');
            const iconFont = fs.readFileSync(path.join(frontend, 'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2')).toString('base64');
            await page.addStyleTag({content: iconCss + `\n@font-face{font-family:bootstrap-icons;src:url(data:font/woff2;base64,${iconFont}) format('woff2')}`});
            await page.addScriptTag({path: path.join(frontend, 'assets/js/stock-risk.js')});
            await page.addScriptTag({path: path.join(frontend, 'assets/js/info-tooltip.js')});
            // The shared shell normally loads this before the page script.
            await page.evaluate(() => { window.RetailMindUI = {}; });
            await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
            assert.equal(await page.getByRole('heading', {name: 'Demand Forecast vs Current Stock', exact: true}).count(), 0, 'Stock Overview omits the forecast comparison card');
            assert.equal(await page.getByText('No forecasts available. Check Demand Forecasting.', {exact: true}).count(), 0, 'Stock Overview omits the forecast empty state');
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
            assert.equal(await page.locator('[data-overview-product-row]:visible').count(), 4);
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
            await page.getByRole('tab', {name: 'Stock Movement', exact: true}).click();
            assert.equal(await page.locator('#overview-risk').isVisible(), true);
            for (const id of ['overview-metrics', 'overview-charts', 'overview-default-view', 'overview-product-view']) {
                assert.equal(await page.locator('#' + id).isVisible(), false, id + ' hides while comparing Stock Movement');
            }
            assert.equal(await page.locator('[data-risk-row]:visible').count(), 10);
            const statusGuide = page.getByRole('button', {name:'About Stock Movement statuses',exact:true}).filter({visible:true});
            await statusGuide.focus();
            const statusHelpBox = page.locator('#' + await statusGuide.getAttribute('aria-describedby'));
            assert.equal(await statusHelpBox.isVisible(),true);
            assert.equal(await statusHelpBox.locator('.sr-badge').count(),5,'Status guide explains all five classifications');
            assert.match(await statusHelpBox.innerText(),/Fast-Moving[\s\S]*Slow-Moving[\s\S]*Dead Stock[\s\S]*Limited history[\s\S]*Stockout affected/);
            const guideBounds = await statusHelpBox.boundingBox();
            assert.ok(guideBounds.x >= 0 && guideBounds.x + guideBounds.width <= width && guideBounds.y >= 0 && guideBounds.y + guideBounds.height <= 1000,'Status guide fits viewport');
            await page.screenshot({path:path.join(output,'stock-movement-status-guide-'+width+'.png'),fullPage:true});
            await page.mouse.move(0,0);
            await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
            await statusGuide.press('Escape');
            assert.equal(await statusHelpBox.isVisible(),false);
            await statusGuide.click();
            assert.equal(await statusHelpBox.isVisible(),true,'Guide reopens on tap');
            await statusGuide.press('Escape');
            for (const theme of ['light','dark']) {
                await page.locator('html').evaluate((el,value)=>{el.dataset.theme=value;},theme);
                await page.screenshot({path:path.join(output,'stock-movement-default-'+theme+'-'+width+'.png'),fullPage:true,animations:'disabled'});
            }
            await page.locator('html').evaluate(el=>{el.dataset.theme='light';});
            await page.getByRole('button', {name:'Next Stock Movement page', exact:true}).click();
            assert.equal(await page.locator('[data-risk-row]:visible').count(), 2);
            const defaultReportPromise = page.waitForEvent('popup');
            await page.getByRole('button',{name:'Generate Report',exact:true}).click();
            const defaultReport = await defaultReportPromise;
            await defaultReport.waitForLoadState();
            assert.equal(await defaultReport.locator('[data-risk-row]').count(),12,'Default report includes all matching pages and excludes empty stock');
            await defaultReport.close();
            await page.getByLabel('Search Stock Movement products', {exact:true}).fill('Movement Product 2');
            assert.equal(await page.locator('[data-risk-row]:visible').count(),1);
            const limited = page.getByRole('button', {name:'Limited history criteria for Movement Product 2',exact:true});
            await limited.focus();
            assert.equal(await page.locator('#movement-criterion-102').isVisible(),true,'Criteria available to keyboard users');
            assert.match(await page.locator('#movement-criterion-102').innerText(),/90 days/);
            const tooltipBounds = await page.locator('#movement-criterion-102').boundingBox();
            assert.ok(tooltipBounds.x >= 0 && tooltipBounds.x + tooltipBounds.width <= width, 'Tooltip stays inside viewport');
            await limited.press('Escape');
            assert.equal(await page.locator('#movement-criterion-102').isVisible(),false,'Escape dismisses tooltip');
            await limited.click();
            assert.equal(await page.locator('#movement-criterion-102').isVisible(),true,'Criteria reopen on click/tap');
            await page.getByRole('button', {name:'View details for Movement Product 2',exact:true}).click();
            assert.equal(await page.locator('#movement-drawer').evaluate(el=>el.open),true);
            assert.equal(await page.locator('#movement-detail-title').innerText(),'Movement Product 2');
            assert.equal(await page.locator('#movement-detail-body .sr-period-table tbody tr').count(),3);
            assert.match(await page.locator('#movement-detail-body').innerText(),/Oct 13, 2026 – Nov 11, 2026/);
            assert.match(await page.locator('#movement-detail-body').innerText(),/Sep 13, 2026 – Oct 12, 2026/);
            assert.match(await page.locator('#movement-detail-body').innerText(),/Aug 14, 2026 – Sep 12, 2026/);
            assert.equal(await page.locator('#movement-detail-body .sr-period-table tbody tr').nth(1).locator('td').nth(1).innerText(),'2','Recorded sales remain visible even with limited history');
            await page.locator('#movement-drawer').evaluate(el=>Promise.all(el.getAnimations().map(animation=>animation.finished)));
            const drawerBounds = await page.locator('#movement-drawer').boundingBox();
            assert.ok(drawerBounds.x >= 0 && drawerBounds.x + drawerBounds.width <= width + 1,'Right drawer fits the viewport');
            assert.equal(await page.evaluate(()=>document.activeElement.id),'movement-drawer-close');
            await page.keyboard.press('Shift+Tab');
            assert.equal(await page.evaluate(()=>document.getElementById('movement-drawer').contains(document.activeElement)),true,'Keyboard focus stays inside the drawer');
            await page.getByRole('button',{name:'Close product details',exact:true}).focus();
            await page.screenshot({path:path.join(output,'stock-movement-drawer-'+width+'.png'),fullPage:true,animations:'disabled'});
            await page.locator('html').evaluate(el=>{el.dataset.theme='dark';});
            await page.screenshot({path:path.join(output,'stock-movement-drawer-dark-'+width+'.png'),fullPage:true,animations:'disabled'});
            await page.locator('html').evaluate(el=>{el.dataset.theme='light';});
            await page.keyboard.press('Escape');
            await page.waitForFunction(()=>!document.getElementById('movement-drawer').open && document.body.style.overflow === '');
            assert.equal(await page.evaluate(()=>document.activeElement.getAttribute('aria-label')),'View details for Movement Product 2','Drawer returns focus to its opener');
            assert.equal(await page.evaluate(()=>document.body.style.overflow),'');
            await page.getByRole('button', {name:'View details for Movement Product 2',exact:true}).click();
            await page.getByRole('button', {name:'Close product details',exact:true}).click();
            await page.waitForFunction(()=>!document.getElementById('movement-drawer').open && document.body.style.overflow === '');
            if(width===1440) {
                await page.getByRole('button', {name:'View details for Movement Product 2',exact:true}).click();
                await page.mouse.click(20,20);
                await page.waitForFunction(()=>!document.getElementById('movement-drawer').open && document.body.style.overflow === '');
            }
            await page.getByLabel('Search Stock Movement products', {exact:true}).fill('missing');
            assert.equal(await page.locator('#risk-empty').isVisible(),true);
            assert.equal(await page.getByRole('button',{name:'Generate Report',exact:true}).isDisabled(),true);
            await page.getByLabel('Search Stock Movement products', {exact:true}).fill('');
            await page.getByLabel('Stock',{exact:true}).selectOption('all');
            if (width < 640) {
                await page.getByLabel('Sort products',{exact:true}).selectOption('stock');
            } else {
                await page.getByRole('button', {name:'Stock',exact:true}).click();
                await page.getByRole('button', {name:'Stock',exact:true}).click();
            }
            assert.equal(await page.locator('[data-risk-row]:visible').first().getAttribute('data-stock'),'120');
            assert.equal(await page.locator('[data-risk-sort="stock"]').evaluate(el=>el.closest('th').getAttribute('aria-sort')),'descending');
            const popupPromise = page.waitForEvent('popup');
            await page.getByRole('button', {name:'Generate Report',exact:true}).click();
            const report = await popupPromise;
            await report.waitForLoadState();
            assert.equal(await report.locator('[data-risk-row]').count(),13,'Report includes all matching pages');
            assert.equal(await report.locator('.sr-report-product').count(),13,'Report includes drawer details for every product');
            assert.equal(await report.locator('.sr-report-brand strong').innerText(),'Shalom Store');
            assert.equal(await report.locator('.sr-report-letterhead h1').innerText(),'Stock Movement Report');
            assert.match(await report.locator('.sr-report-stamp').innerText(),/PHT/);
            assert.match(await report.locator('.sr-report-scope').innerText(),/All products.*13 products.*base units/);
            assert.equal(await report.locator('.sr-report-preparer').innerText(),'Prepared by Inventory Reviewer <test>','Preparer remains text, including angle brackets');
            assert.match(await report.locator('.sr-report-footer').innerText(),/Shalom Store.*RetailMind[\s\S]*End of report.*13 products/);
            assert.equal(await report.locator('dialog,template,.tooltip-box').count(),0,'Report contains readable content rather than interactive overlays');
            assert.match(await report.locator('.sr-period-label').innerText(),/Oct 13, 2026 – Nov 11, 2026/);
            assert.match(await report.locator('.sr-report-product').first().innerText(),/Returns to stock/);
            if (width === 1440) {
                await report.screenshot({path:path.join(output,'stock-movement-report.png'),fullPage:true});
                await report.pdf({path:path.join(output,'stock-movement-report.pdf'),printBackground:true,preferCSSPageSize:true});
                await report.emulateMedia({media:'print'});
                assert.equal(await report.locator('.sr-table thead').evaluate(el=>getComputedStyle(el).display),'table-header-group','Print repeats table headings');
                assert.equal(await report.locator('[data-risk-row]').first().evaluate(el=>getComputedStyle(el).display),'table-row','Print keeps tabular layout');
                assert.equal(await report.locator('h1').evaluate(el=>getComputedStyle(el).color),'rgb(30, 58, 138)','Printed title retains Store report accent');
                assert.notEqual(await report.locator('.sr-badge.sr-green').first().evaluate(el=>getComputedStyle(el).backgroundColor),'rgba(0, 0, 0, 0)','Status color survives the shared print reset');
                await report.screenshot({path:path.join(output,'stock-movement-report-print.png')});
                await report.emulateMedia({media:'screen'});
            }
            assert.equal(await report.getByRole('button',{name:'Print / Save as PDF',exact:true}).count(),1);
            await report.evaluate(()=>{window.print=()=>{window.printed=true;};});
            await report.getByRole('button',{name:'Print / Save as PDF',exact:true}).click();
            assert.equal(await report.evaluate(()=>window.printed),true);
            await report.close();
            await page.getByLabel('Search Stock Movement products',{exact:true}).fill('Movement Product 2');
            const filteredPromise = page.waitForEvent('popup');
            await page.getByRole('button', {name:'Generate Report',exact:true}).click();
            const filteredReport = await filteredPromise;
            await filteredReport.waitForLoadState();
            assert.equal(await filteredReport.locator('[data-risk-row]').count(),1,'Report respects search and stock filter');
            assert.match(await filteredReport.locator('.sr-report-scope').innerText(),/Search: Movement Product 2.*1 products/);
            await filteredReport.close();
            await page.getByLabel('Search Stock Movement products',{exact:true}).fill('');
            await page.evaluate(()=>{window.originalOpen=window.open;window.open=()=>null;});
            await page.getByRole('button',{name:'Generate Report',exact:true}).click();
            assert.match(await page.locator('#movement-report-status').innerText(),/Allow pop-ups/);
            await page.evaluate(()=>{window.open=window.originalOpen;delete window.originalOpen;});
            assert.equal(await page.locator('input[type=month]').count(),0,'Rolling periods replace calendar-month controls');
            assert.equal(await page.locator('#stock-risk .sr-table thead th').count(),7,'Main table remains compact');
            for (const theme of ['light','dark']) {
                await page.locator('html').evaluate((el, value)=>{el.dataset.theme=value;},theme);
                await page.evaluate(()=>window.scrollTo(0,0));
                await page.locator('.sr-table-scroll').evaluate(el=>{el.scrollLeft=0;});
                assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth),'Stock Movement has no page overflow');
                await page.screenshot({path:path.join(output, `stock-risk-${theme}-${width}.png`),fullPage:true});
            }
            await page.getByRole('tab', {name:'Stock Movement',exact:true}).focus();
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
