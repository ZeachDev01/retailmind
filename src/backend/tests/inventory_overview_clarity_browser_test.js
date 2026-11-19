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
    require_once ${JSON.stringify(path.resolve(__dirname, 'support/stock_movement_schema.php').replaceAll('\\', '/'))};
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
    $pdo = new PDO('sqlite::memory:');
    createStockMovementSchema($pdo);
    $sale = $pdo->prepare('INSERT INTO sales (sale_date) VALUES (?)');
    $saleItem = $pdo->prepare("INSERT INTO sale_items VALUES (?, ?, ?, 'piece', NULL)");
    $stockMovement = $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (?, ?, ?, ?)");
    $recordSale = static function (int $productId, string $date, int $units) use ($pdo, $sale, $saleItem, $stockMovement): void {
        $sale->execute([$date]);
        $saleItem->execute([$pdo->lastInsertId(), $productId, $units]);
        $stockMovement->execute([$productId, -$units, 'sale', $date]);
    };
    $movementProducts = [];
    foreach (range(1, 13) as $i) {
        $id = 100 + $i;
        $movementProducts[] = ['product_id' => $id, 'product_name' => 'Movement Product ' . $i, 'sku' => 'MOVE-' . $i,
            'category_name' => $i % 2 ? 'Personal Care' : 'Beverages', 'quantity_on_hand' => $i === 13 ? 0 : $i * 10,
            'created_at' => match ($i) { 2 => '2026-10-29', 4 => null, 5 => '2026-09-13', default => '2026-01-01' }];
        if ($i === 1) continue;
        if ($i !== 2) $recordSale($id, '2026-09-13', $i);
        if ($i !== 2 && $i !== 4) {
            $recordSale($id, '2026-08-14', 1);
            $recordSale($id, '2026-09-12', $i - 1);
        }
        foreach (range(1, $i) as $day) {
            $recordSale($id, $i === 2 ? '2026-10-' . (28 + $day) : sprintf('2026-10-%02d', 12 + $day), 3);
        }
        $stockMovement->execute([$id, $i === 3 ? 50 : 8, 'purchase', '2026-11-10']);
    }
    $riskRows = \\App\\Inventory\\StockMovementReader::read($pdo, $movementProducts, $movementPeriod);
?>${template}`});

(async () => {
    const browser = await chromium.launch({headless: true});
    const output = path.resolve('output/playwright/inventory-overview');
    fs.mkdirSync(output, {recursive: true});
    const periodRanges = ['Aug 14, 2026 – Sep 12, 2026', 'Sep 13, 2026 – Oct 12, 2026', 'Oct 13, 2026 – Nov 11, 2026'];
    const coverageExamples = [
        {product: 1, coverage: ['', '', ''], units: ['0', '0', '0'], days: ['0', '0', '0']},
        {product: 2, coverage: ['History unavailable', 'History unavailable', 'Partial'], units: ['—', '—', '6'], days: ['—', '—', '2']},
        {product: 4, coverage: ['Coverage unknown', 'Coverage unknown', 'Coverage unknown'], units: ['—', '4', '12'], days: ['—', '1', '4']},
    ];
    async function assertPeriodDetails(details, example) {
        const table = details.getByRole('table');
        assert.match(await table.getByRole('columnheader', {name: /Units sold/}).innerText(), /base units/i);
        assert.equal(await table.getByRole('columnheader', {name: 'Selling days', exact: true}).count(), 1);
        const rows = table.locator('tbody').getByRole('row');
        assert.equal(await rows.count(), 3);
        for (const [index, range] of periodRanges.entries()) {
            const row = rows.nth(index);
            const heading = (await row.getByRole('rowheader').innerText()).replace(/\s+/g, ' ');
            assert.equal(heading, [range, example.coverage[index], index === 2 ? 'Latest 30 days' : ''].filter(Boolean).join(' '));
            assert.deepEqual(await row.getByRole('cell').allInnerTexts(), [example.units[index], example.days[index]]);
        }
    }
    async function assertRowPeriods(page, example, metric, width) {
        const row = page.locator('[data-risk-row]:visible').filter({
            has: page.getByRole('button', {name:'View details for Movement Product ' + example.product, exact:true})
        });
        const periods = row.locator('.sr-sales-periods');
        assert.deepEqual(await periods.locator('dt').allTextContents(), periodRanges, 'The three exact periods appear oldest first');
        assert.deepEqual(await periods.locator('.sr-sales-value').allInnerTexts(), example[metric]);
        assert.deepEqual(await periods.locator('dd').evaluateAll(cells => cells.map(cell => cell.querySelector('.sr-sales-coverage')?.innerText || '')), example.coverage);
        for (const item of await periods.locator(':is(dt,.sr-sales-value,.sr-sales-coverage)').all()) {
            const bounds = await item.boundingBox();
            assert.ok(bounds && bounds.x >= 0 && bounds.x + bounds.width <= width,
                'Each date, value and coverage label fits the viewport: ' + JSON.stringify({width, bounds, text:await item.innerText()}));
        }
        return row;
    }
    async function drawnBarPixels(row) {
        return row.locator('canvas').evaluate(canvas => {
            const {width, height} = canvas;
            const pixels = canvas.getContext('2d').getImageData(0, 0, width, height).data;
            const bars = [0, 0, 0];
            for (let i = 3; i < pixels.length; i += 4) {
                if (pixels[i] > 0) bars[Math.floor(((i - 3) / 4 % width) * 3 / width)]++;
            }
            return bars;
        });
    }
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
            await page.addScriptTag({path: path.join(frontend, 'assets/vendor/chartjs/chart.umd.min.js')});
            await page.getByRole('tab', {name: 'Stock Movement', exact: true}).click();
            assert.equal(await page.locator('#overview-risk').isVisible(), true);
            for (const id of ['overview-metrics', 'overview-charts', 'overview-default-view', 'overview-product-view']) {
                assert.equal(await page.locator('#' + id).isVisible(), false, id + ' hides while comparing Stock Movement');
            }
            assert.equal(await page.locator('[data-risk-row]:visible').count(), 10);
            const salesMetric = page.getByLabel('Sales metric', {exact:true});
            assert.equal(await salesMetric.isVisible(), true, 'The sales metric has a visible control');
            const metricBounds = await salesMetric.boundingBox();
            assert.ok(metricBounds.x >= 0 && metricBounds.x + metricBounds.width <= width, 'The metric fits the viewport');
            assert.equal(await salesMetric.locator('option:checked').innerText(), 'Units sold (base units)');
            let unitsBarRatio;
            for (const example of coverageExamples) {
                const row = await assertRowPeriods(page, example, 'units', width);
                assert.equal(await row.locator('canvas').isVisible(), true, 'The local chart bundle renders visible rows');
                const pixels = await drawnBarPixels(row);
                assert.deepEqual(pixels.map(count => count > 0), example.units.map(value => Number(value) > 0), 'Only periods with positive figures draw bars');
                if (example.product === 4) unitsBarRatio = pixels[1] / pixels[2];
            }
            const inconsistentHistory = await assertRowPeriods(page, {
                product:5, units:['5', '5', '15'], coverage:['History unavailable', '', '']
            }, 'units', width);
            assert.deepEqual((await drawnBarPixels(inconsistentHistory)).map(count => count > 0), [false, true, true],
                'A positive historical figure stays readable when coverage is unavailable, with no bar');
            const coverageGuide = page.getByRole('button', {name:'About sales history coverage', exact:true});
            await coverageGuide.focus();
            assert.match(await page.locator('#movement-coverage-help').innerText(), /0 means.*dash.*Partial means.*History unavailable means.*Coverage unknown means/);
            await coverageGuide.press('Escape');
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
            await salesMetric.focus();
            await salesMetric.press('ArrowDown');
            assert.equal(await salesMetric.locator('option:checked').innerText(), 'Selling days');
            for (const example of coverageExamples) {
                const row = await assertRowPeriods(page, example, 'days', width);
                if (example.product === 2) {
                    const accessiblePeriods = await row.locator('.sr-sales-periods').ariaSnapshot();
                    for (const text of [...periodRanges, '2', 'selling days', 'Partial', 'History unavailable']) {
                        assert.ok(accessiblePeriods.includes(text), 'Assistive technology can read ' + text);
                    }
                }
                if (example.product === 4) {
                    const pixels = await drawnBarPixels(row);
                    assert.ok(pixels[1] / pixels[2] < unitsBarRatio, 'Bar heights reflect the selected metric');
                }
            }
            await page.getByRole('button', {name:'Next Stock Movement page', exact:true}).click();
            assert.equal(await page.locator('[data-risk-row]:visible').count(), 2);
            const nextPageRow = page.locator('[data-risk-row]:visible').filter({
                has: page.getByRole('button', {name:'View details for Movement Product 12', exact:true})
            });
            assert.deepEqual(await nextPageRow.locator('.sr-sales-value').allInnerTexts(), ['2', '1', '12']);
            assert.ok((await drawnBarPixels(nextPageRow)).every(count => count > 0));
            const defaultReportPromise = page.waitForEvent('popup');
            await page.getByRole('button',{name:'Generate Report',exact:true}).click();
            const defaultReport = await defaultReportPromise;
            await defaultReport.waitForLoadState();
            assert.equal(await defaultReport.locator('[data-risk-row]').count(),12,'Default report includes all matching pages and excludes empty stock');
            await defaultReport.close();
            await page.getByLabel('Search Stock Movement products', {exact:true}).fill('Movement Product 2');
            assert.equal(await page.locator('[data-risk-row]:visible').count(),1);
            await assertRowPeriods(page, coverageExamples[1], 'days', width);
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
            const drawerDetails = page.locator('#movement-detail-body');
            await assertPeriodDetails(drawerDetails, coverageExamples[1]);
            assert.match(await drawerDetails.innerText(), /0 means no sales were recorded.*A dash.*means no figure is available.*Partial means.*Coverage unknown means/s);
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
            for (const example of coverageExamples.filter(example => example.product !== 2)) {
                await page.getByLabel('Search Stock Movement products', {exact:true}).fill('MOVE-' + example.product);
                await page.getByRole('button', {name:'Open details for Movement Product ' + example.product, exact:true}).click();
                await assertPeriodDetails(drawerDetails, example);
                await page.getByRole('button', {name:'Close product details',exact:true}).click();
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
            assert.equal(await salesMetric.locator('option:checked').innerText(), 'Selling days', 'Search, stock filter, sorting and pagination preserve the metric');
            assert.deepEqual(await page.locator('[data-risk-row]:visible').first().locator('.sr-sales-value').allInnerTexts(), ['2', '1', '12']);
            assert.equal(await page.locator('[data-risk-sort="stock"]').evaluate(el=>el.closest('th').getAttribute('aria-sort')),'descending');
            const popupPromise = page.waitForEvent('popup');
            await page.getByRole('button', {name:'Generate Report',exact:true}).click();
            const report = await popupPromise;
            await report.waitForLoadState();
            assert.equal(await report.locator('[data-risk-row]').count(),13,'Report includes all matching pages');
            assert.equal(await report.locator('.sr-report-product').count(),13,'Report includes drawer details for every product');
            for (const example of coverageExamples) {
                const details = report.locator('.sr-report-product').filter({
                    has: report.getByRole('heading', {name:'Movement Product ' + example.product, exact:true})
                });
                await assertPeriodDetails(details, example);
            }
            assert.equal(await report.getByText('0 means no sales were recorded.', {exact:false}).count(),1,'The report explains coverage once');
            assert.equal(await report.getByText('Forecast-only imports are excluded.', {exact:false}).count(),1,'The report includes the shared calculation basis');
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
        const direct = await browser.newPage({viewport:{width:1440, height:1000}});
        await direct.addInitScript(() => { window.RetailMindUI = {}; });
        const styles = ['style.css', 'inventory.css', 'stock-risk.css']
            .map(file => '<link rel="stylesheet" href="/assets/css/' + file + '">').join('');
        const directHtml = '<!doctype html><html><head>' + styles
            + '<script defer src="/assets/vendor/chartjs/chart.umd.min.js"></script></head>'
            + html.replace('<script>', '<script src="/assets/js/stock-risk.js"></script><script>');
        await direct.route('https://retailmind.test/**', route => {
            const {pathname} = new URL(route.request().url());
            if (pathname === '/overview') return route.fulfill({body:directHtml, contentType:'text/html'});
            if (pathname.startsWith('/assets/')) return route.fulfill({path:path.join(frontend, pathname)});
            return route.abort();
        });
        await direct.goto('https://retailmind.test/overview?tab=movement');
        assert.equal(await direct.getByRole('tab', {name:'Stock Movement', exact:true}).getAttribute('aria-selected'), 'true');
        assert.deepEqual(
            (await drawnBarPixels(await assertRowPeriods(direct, coverageExamples[1], 'units', 1440))).map(count => count > 0),
            [false, false, true], 'A direct link renders after the deferred local chart bundle loads'
        );
        await direct.route('**/assets/vendor/chartjs/chart.umd.min.js', route => route.abort());
        await direct.goto('https://retailmind.test/overview#stock-movement');
        const fallback = await assertRowPeriods(direct, coverageExamples[1], 'units', 1440);
        assert.equal(await fallback.locator('canvas').isVisible(), false, 'Exact figures remain readable when the chart bundle is unavailable');
        await direct.getByLabel('Sales metric', {exact:true}).selectOption('days');
        await assertRowPeriods(direct, coverageExamples[1], 'days', 1440);
        await direct.close();
        console.log('Inventory overview clarity: passed');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
