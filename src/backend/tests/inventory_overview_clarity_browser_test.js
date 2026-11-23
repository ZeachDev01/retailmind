const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const php = process.env.PHP_BINARY || 'php';
const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/inventory_overview.php'), 'utf8');
const statusHelp = fs.readFileSync(path.join(frontend, 'components/inventory_management/stock_movement_status_help.php'), 'utf8');
const riskView = fs.readFileSync(path.join(frontend, 'components/inventory_management/stock_risk.php'), 'utf8')
    .replaceAll("include __DIR__ . '/stock_movement_status_help.php';", '?>' + statusHelp + '<?php');
const template = source.slice(source.indexOf('<body'))
    .replace("<?php include __DIR__ . '/stock_risk.php'; ?>", riskView)
    .replace(/<\?php include[^?]+\?>/g, '')
    .replace(/^<script src=.*(?:stock-risk|info-tooltip)\.js.*<\/script>\r?$/gm, '');
const html = execFileSync(php, [], {encoding: 'utf8', input: `<?php
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
    $productCategories = ['Food'];
    $chartDataJson = '{"status":{"labels":["In stock"],"values":[3],"keys":["in-stock"]},"categories":{"labels":["Food"],"values":[30]}}';
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
const navigationScript = fs.readFileSync(path.join(frontend, 'assets/js/page-navigation.js'), 'utf8');
const pageHtml = '<!doctype html><html><head><title>Stock Overview</title></head>' + html.replace('<div class="app-shell">', shell =>
    `${shell}<!--rm-shell-start--><script data-workspace="admin">${navigationScript}</script><!--rm-shell-end-->`);
const navigationFixture = path.join(__dirname, 'support/navigation_response_fixture.php');
const fullPage = execFileSync(php, [navigationFixture], {encoding: 'utf8', input: pageHtml});
const partialPage = execFileSync(php, [navigationFixture, '1'], {encoding: 'utf8', input: pageHtml});

(async () => {
    const browser = await chromium.launch({headless: true});
    const output = path.resolve('output/playwright/inventory-overview');
    fs.mkdirSync(output, {recursive: true});
    const periodRanges = ['Aug 14, 2026 – Sep 12, 2026', 'Sep 13, 2026 – Oct 12, 2026', 'Oct 13, 2026 – Nov 11, 2026'];
    const coverageExamples = [
        {product: 1, coverage: ['', '', ''], units: ['0', '0', '0'], days: ['0', '0', '0'], total: '0'},
        {product: 2, coverage: ['History unavailable', 'History unavailable', 'Partial'], units: ['—', '—', '6'], days: ['—', '—', '2'], total: '6'},
        {product: 4, coverage: ['Coverage unknown', 'Coverage unknown', 'Coverage unknown'], units: ['—', '4', '12'], days: ['—', '1', '4'], total: '16'},
    ];
    async function assertReportProducts(report, productNumbers) {
        const names = productNumbers.map(number => 'Movement Product ' + number);
        const summary = report.getByRole('table', {name: 'Stock Movement summary', exact: true});
        const productRows = await summary.getByRole('rowheader').allInnerTexts();
        assert.deepEqual(productRows.map(text => text.split('\n')[0]), names, 'Summary includes each matching product in list order');
        const stockFlow = report.getByRole('table', {name: 'Stock flow for the latest 30 days', exact: true});
        const flowRows = await stockFlow.getByRole('rowheader').allInnerTexts();
        assert.deepEqual(flowRows.map(text => text.split('\n')[0]), names, 'Stock flow includes each matching product in list order');
    }
    async function assertRowSales(page, example) {
        const row = page.locator('[data-risk-row]:visible').filter({
            has: page.getByRole('button', {name:'View details for Movement Product ' + example.product, exact:true})
        });
        const cell = row.locator('td[data-label="90-day sales"]');
        assert.equal((await cell.innerText()).trim(), example.total, 'The row shows one 90-day sales total');
        await cell.scrollIntoViewIfNeeded();
        const bounds = await cell.boundingBox();
        assert.ok(bounds && bounds.x >= 0 && bounds.x + bounds.width <= page.viewportSize().width,
            'The 90-day total fits the viewport: ' + JSON.stringify({bounds}));
        return row;
    }
    async function drawnCanvasPixels(page, selector) {
        return page.locator(selector).evaluate(canvas => {
            const {width, height} = canvas;
            const pixels = canvas.getContext('2d').getImageData(0, 0, width, height).data;
            let drawn = 0;
            for (let i = 3; i < pixels.length; i += 4) if (pixels[i] > 0) drawn++;
            return drawn;
        });
    }
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            const overviewUrl = 'http://retailmind.test/retailmind/src/frontend/components/inventory_management/inventory_overview.php';
            let refreshRequests = 0;
            await page.route(url => url.pathname.endsWith('/inventory_overview.php'), route => {
                if (route.request().headers()['x-retailmind-navigation'] === '1') {
                    refreshRequests++;
                    return route.fulfill({contentType: 'application/vnd.retailmind.page+json', body: partialPage});
                }
                return route.fulfill({contentType: 'text/html; charset=utf-8', body: fullPage});
            });
            await page.addInitScript(() => {
                window.RetailMindUI = {hasUnsavedChanges: () => false, dismissOverlays() {}, initPageContent() {}};
            });
            await page.goto(overviewUrl);
            assert.equal(await page.locator('#overview-forecast').count(), 0, 'Stock Movement replaces the forecast comparison');
            for (const file of ['theme.css', 'style.css', 'inventory.css', 'stock-risk.css']) {
                await page.addStyleTag({path: path.join(frontend, 'assets/css', file)});
            }
            const iconCss = fs.readFileSync(path.join(frontend, 'assets/vendor/bootstrap-icons/bootstrap-icons.min.css'), 'utf8');
            const iconFont = fs.readFileSync(path.join(frontend, 'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2')).toString('base64');
            await page.addStyleTag({content: iconCss + `\n@font-face{font-family:bootstrap-icons;src:url(data:font/woff2;base64,${iconFont}) format('woff2')}`});
            await page.addScriptTag({path: path.join(frontend, 'assets/js/stock-risk.js')});
            await page.addScriptTag({path: path.join(frontend, 'assets/js/info-tooltip.js')});
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
            for (const [selector, maxHeight] of [['#movement-chart-weekly', 144], ['#movement-chart-top', 144], ['#movement-chart-coverage', 96]]) {
                assert.equal(await page.locator(selector).isVisible(), true, 'The three page charts render when the tab opens: ' + selector);
                assert.ok(await drawnCanvasPixels(page, selector) > 0, 'The chart draws pixels: ' + selector);
                const height = await page.locator(selector).evaluate(canvas => canvas.getBoundingClientRect().height);
                assert.ok(height > 0 && height <= maxHeight, 'The chart stays compact: ' + selector + ' (' + height + 'px)');
            }
            const coverageCounts = page.locator('.sr-coverage-counts li');
            assert.equal(await coverageCounts.count(), 4, 'Coverage keeps a text count beside the chart');
            for (const example of coverageExamples) await assertRowSales(page, example);
            const inconsistentHistory = await assertRowSales(page, {
                product:5, units:['5', '5', '15'], coverage:['History unavailable', '', ''], total: '25'
            });
            assert.equal(await inconsistentHistory.locator('td[data-label="90-day sales"]').innerText(), '25',
                'A positive historical figure stays readable when a period is unavailable');
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
            await page.getByRole('button', {name:'Next Stock Movement page', exact:true}).click();
            assert.equal(await page.locator('[data-risk-row]:visible').count(), 2);
            const nextPageRow = page.locator('[data-risk-row]:visible').filter({
                has: page.getByRole('button', {name:'View details for Movement Product 12', exact:true})
            });
            assert.match(await nextPageRow.locator('td[data-label="90-day sales"]').innerText(), /^\d+$/,
                'The last page keeps its 90-day total after paging');
            const defaultReportPromise = page.waitForEvent('popup');
            await page.getByRole('button',{name:'Generate Report',exact:true}).click();
            const defaultReport = await defaultReportPromise;
            await defaultReport.waitForLoadState();
            await assertReportProducts(defaultReport, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
            await defaultReport.close();
            await page.getByLabel('Search Stock Movement products', {exact:true}).fill('Movement Product 2');
            assert.equal(await page.locator('[data-risk-row]:visible').count(),1);
            await assertRowSales(page, coverageExamples[1]);
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
            const drawerChart = drawerDetails.locator('canvas[data-movement-weekly]');
            assert.equal(await drawerChart.count(), 1, 'The drawer shows one weekly chart');
            assert.equal(await drawerChart.evaluate(canvas => window.Chart.getChart(canvas)?.data.labels.length), 13, 'The drawer chart carries 13 weekly buckets');
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
                assert.equal(await drawerDetails.locator('canvas[data-movement-weekly]').count(), 1, 'Every product opens with its weekly chart');
                assert.equal(await drawerDetails.locator('canvas[data-movement-weekly]').evaluate(canvas => window.Chart.getChart(canvas)?.data.datasets[0].data.length), 13);
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
            assert.match(await page.locator('[data-risk-row]:visible').first().locator('td[data-label="90-day sales"]').innerText(), /^\d+$/,
                'Search, stock filter, sorting and pagination preserve the 90-day total');
            assert.equal(await page.locator('[data-risk-sort="stock"]').evaluate(el=>el.closest('th').getAttribute('aria-sort')),'descending');
            const popupPromise = page.waitForEvent('popup');
            await page.getByRole('button', {name:'Generate Report',exact:true}).click();
            const report = await popupPromise;
            await report.waitForLoadState();
            await assertReportProducts(report, [12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 13]);
            const summary = report.getByRole('table', {name: 'Stock Movement summary', exact: true});
            assert.deepEqual(await summary.locator('thead tr').last().getByRole('columnheader').allInnerTexts(), periodRanges);
            const summaryHeadings = await summary.locator('thead tr').first().getByRole('columnheader').allInnerTexts();
            assert.deepEqual(summaryHeadings.map(text => text.replace(/\s+/g, ' ')), [
                'Product', 'Stock now', 'Units sold (base units) and selling days', 'Status', 'Trend'
            ]);
            for (const example of coverageExamples) {
                const row = summary.getByRole('row').filter({
                    has: report.getByRole('rowheader', {name: new RegExp('^Movement Product ' + example.product + '\\b')})
                });
                const cells = (await row.getByRole('cell').allInnerTexts()).slice(1, 4);
                assert.deepEqual(
                    cells.map(cell => cell.replace(/\s+/g, ' ')),
                    example.units.map((units, index) => {
                        const days = example.days[index];
                        const sellingDays = days === '—' ? '' : days + (days === '1' ? ' selling day' : ' selling days');
                        return [units, sellingDays, example.coverage[index]].filter(Boolean).join(' ');
                    })
                );
            }
            const stockFlow = report.getByRole('table', {name: 'Stock flow for the latest 30 days', exact: true});
            assert.equal(await stockFlow.getByRole('columnheader', {name:'Returns to stock', exact:true}).count(), 1);
            const product3Flow = stockFlow.getByRole('row').filter({
                has: report.getByRole('rowheader', {name: /^Movement Product 3\b/})
            });
            assert.deepEqual(await product3Flow.getByRole('cell').allInnerTexts(), ['-11', '50', '-9', '0', '0', '30']);
            assert.equal(await report.getByText('0 means no sales were recorded.', {exact:false}).count(),1,'The report explains coverage once');
            assert.equal(await report.getByText('Forecast-only imports are excluded.', {exact:false}).count(),1,'The report includes the shared calculation basis');
            assert.equal(await report.locator('.sr-report-brand strong').innerText(),'Shalom Store');
            assert.equal(await report.locator('.sr-report-letterhead h1').innerText(),'Stock Movement Report');
            assert.match(await report.locator('.sr-report-stamp').innerText(),/PHT/);
            assert.match(await report.locator('.sr-report-scope').innerText(),/All products.*13 products.*base units/);
            assert.equal(await report.locator('.sr-report-preparer').innerText(),'Prepared by Inventory Reviewer <test>','Preparer remains text, including angle brackets');
            assert.match(await report.locator('.sr-report-footer').innerText(),/Shalom Store.*RetailMind[\s\S]*End of report.*13 products/);
            assert.equal(await report.locator('dialog,template,.tooltip-box,canvas').count(),0,'Report uses static text and tables');
            assert.equal(await report.locator('.sr-period-label').innerText(), 'Aug 14, 2026 – Nov 11, 2026 · 90-day review');
            assert.ok(await report.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Report fits the viewport');
            if (width < 640) {
                const scrollArea = report.getByRole('region', {name: 'Summary', exact: true});
                await scrollArea.press('ArrowRight');
                await report.waitForFunction(() => document.querySelector('.sr-table-scroll').scrollLeft > 0);
            }
            if (width === 1440) {
                await report.screenshot({path:path.join(output,'stock-movement-report.png'),fullPage:true});
                await report.pdf({path:path.join(output,'stock-movement-report.pdf'),printBackground:true,preferCSSPageSize:true});
                await report.emulateMedia({media:'print'});
                assert.deepEqual(await report.locator('.sr-table thead').evaluateAll(headers => headers.map(el => getComputedStyle(el).display)),['table-header-group', 'table-header-group'],'Print repeats table headings');
                assert.ok(await report.locator('.sr-table :is(th,td)').evaluateAll(cells =>
                    cells.every(cell => cell.scrollWidth <= cell.clientWidth + 1 && cell.scrollHeight <= cell.clientHeight + 1)
                ), 'Print keeps dates, values and coverage within their cells');
                assert.equal(await summary.locator('tbody tr').first().evaluate(el=>getComputedStyle(el).display),'table-row','Print keeps tabular layout');
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
            await page.getByLabel('Stock',{exact:true}).selectOption('in-stock');
            await page.getByLabel('Search Stock Movement products',{exact:true}).fill('Movement Product 2');
            const filteredPromise = page.waitForEvent('popup');
            await page.getByRole('button', {name:'Generate Report',exact:true}).click();
            const filteredReport = await filteredPromise;
            await filteredReport.waitForLoadState();
            await assertReportProducts(filteredReport, [2]);
            assert.match(await filteredReport.locator('.sr-report-scope').innerText(),/With stock.*Search: Movement Product 2.*1 products/);
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
            for (const [name, selected] of [['Stock Movement', 'Stock Movement'], ['Products', 'Total products'], ['Stock Overview', 'Stock Overview']]) {
                await page.getByRole('tab', {name, exact: true}).click();
                await page.reload();
                assert.equal(await page.getByRole('tab', {name: selected, exact: true}).getAttribute('aria-selected'), 'true', `${name} remains selected after reload`);
            }
            await page.goto(overviewUrl + '?source=legacy#stock-risk');
            assert.equal(await page.getByRole('tab', {name: 'Stock Movement', exact: true}).getAttribute('aria-selected'), 'true', 'Legacy Stock Movement links still open the tab');
            await page.getByRole('tab', {name: 'Products', exact: true}).click();
            const stockFilter = page.getByLabel('Filter by stock status');
            await stockFilter.selectOption('out');
            const historyLength = await page.evaluate(() => history.length);
            const previousRequests = refreshRequests;
            await page.evaluate(() => {
                window.addEventListener('retailmind:pagechange', () => { window.inventoryRefreshComplete = true; }, {once: true});
                window.dispatchEvent(new Event('blur'));
                window.dispatchEvent(new Event('focus'));
                window.dispatchEvent(new Event('focus'));
            });
            await page.waitForFunction(() => window.inventoryRefreshComplete, null, {timeout: 5000});
            assert.equal(refreshRequests, previousRequests + 1, 'Returning to inventory fetches a fresh view once');
            assert.equal(await page.evaluate(() => history.length), historyLength, 'Refreshing does not add browser history');
            assert.equal(await page.getByRole('tab', {name: 'Out of stock', exact: true}).getAttribute('aria-selected'), 'true', 'Returning to the page refreshes filtered Products in place');
            assert.equal(await stockFilter.inputValue(), 'out');
            assert.deepEqual(errors, []);
            await page.close();
        }
        const directWidth = 320;
        const direct = await browser.newPage({viewport:{width:directWidth, height:1000}});
        const directErrors = [];
        direct.on('pageerror', error => directErrors.push(error.message));
        await direct.addInitScript(() => { window.RetailMindUI = {}; });
        const styles = ['style.css', 'inventory.css', 'stock-risk.css']
            .map(file => '<link rel="stylesheet" href="/assets/css/' + file + '">').join('');
        const directHtml = '<!doctype html><html><head>' + styles
            + '<script defer src="/assets/vendor/chartjs/chart.umd.min.js"></script></head>'
            + html.replace('<script>', '<script src="/assets/js/stock-risk.js"></script><script>');
        await direct.route('https://retailmind.test/**', route => {
            const {pathname} = new URL(route.request().url());
            if (pathname === '/overview') return route.fulfill({body:directHtml, contentType:'text/html; charset=utf-8'});
            if (pathname.startsWith('/assets/')) return route.fulfill({path:path.join(frontend, pathname)});
            return route.abort();
        });
        await direct.goto('https://retailmind.test/overview?tab=movement');
        assert.deepEqual(directErrors, []);
        assert.equal(await direct.getByRole('tab', {name:'Stock Movement', exact:true}).getAttribute('aria-selected'), 'true');
        assert.ok(await drawnCanvasPixels(direct, '#movement-chart-weekly') > 0,
            'A direct link renders the page chart after the deferred local chart bundle loads');
        await direct.route('**/assets/vendor/chartjs/chart.umd.min.js', route => route.abort());
        await direct.goto('https://retailmind.test/overview#stock-movement');
        await assertRowSales(direct, coverageExamples[1]);
        assert.equal(await direct.locator('.sr-chart-fallback:visible').count() > 0, true,
            'A text fallback replaces the charts when the bundle is unavailable');
        assert.deepEqual(directErrors, []);
        await direct.close();
        console.log('Inventory overview clarity: passed');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
