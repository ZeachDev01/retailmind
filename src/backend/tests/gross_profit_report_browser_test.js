const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const source = fs.readFileSync(path.join(root, 'src/frontend/components/report/report_generation.php'), 'utf8');
    const template = source.slice(source.indexOf('<!DOCTYPE html>')).replace(/<\?php include __DIR__ .*?\?>/, '');
    const phpBinary = process.env.PHP_BINARY || 'php';

    const renderHtml = (state) => {
        return execFileSync(
            phpBinary,
            ['-r', `
                function retailmind_theme_head() {}
                function app_url($p = "") { return "/" . ltrim($p, "/"); }
                function theme_url($p = "") { return "/" . ltrim($p, "/"); }
                function csrf_field() { return '<input type="hidden" name="csrf_token" value="test_token">'; }

                $state = json_decode($argv[1], true);
                $selectedReport = $state['selected_report'] ?? 'gross_profit';
                $activePeriod = $state['active_period'] ?? 'today';
                $filters = $state['filters'] ?? [
                    'from' => '2026-10-17',
                    'to' => '2026-10-17',
                    'product_id' => 0,
                    'category_id' => 0,
                    'granularity' => 'daily',
                    'period' => $activePeriod,
                ];
                $reportCatalog = [
                    'sales_period' => 'Daily, Weekly and Monthly Sales',
                    'gross_profit' => 'Gross Profit Report',
                ];
                $products = [
                    ['product_id' => 101, 'sku' => 'COF-101', 'product_name' => 'Artisan Coffee'],
                    ['product_id' => 102, 'sku' => 'TEA-102', 'product_name' => 'Matcha Tea'],
                ];
                $categories = [
                    ['category_id' => 1, 'category_name' => 'Beverages'],
                ];
                $validationError = $state['validation_error'] ?? null;
                $loadError = $state['load_error'] ?? null;
                $reportData = $state['report_data'] ?? [
                    'headers' => ['SKU', 'Product', 'Category', 'Net Units', 'Net Sales', 'Product Cost', 'Gross Profit', 'Margin', 'Status'],
                    'rows' => [],
                    'summary' => null,
                    'metrics' => [],
                ];
                $totalRows = count($reportData['rows']);
                $pageTitle = $selectedReport === 'gross_profit' ? 'Gross Profit Report' : 'Report Generation';

                eval('?>'.stream_get_contents(STDIN));
            `, JSON.stringify(state)],
            {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/report')}
        );
    };

    const recordedState = {
        selected_report: 'gross_profit',
        active_period: 'today',
        filters: {
            from: '2026-10-17',
            to: '2026-10-17',
            product_id: 0,
            category_id: 0,
            granularity: 'daily',
            period: 'today',
        },
        report_data: {
            headers: ['SKU', 'Product', 'Category', 'Net Units', 'Net Sales', 'Product Cost', 'Gross Profit', 'Margin', 'Status'],
            rows: [
                {
                    SKU: 'COF-101',
                    Product: 'Artisan Coffee',
                    Category: 'Beverages',
                    'Net Units': 2,
                    'Gross Sales': '200.00',
                    Discounts: '0.00',
                    Refunds: '0.00',
                    'Net Sales': '200.00',
                    'Net Cost': '100.00',
                    'Product Cost': '100.00',
                    'Gross Profit': '100.00',
                    Margin: '50.00%',
                    Status: 'Recorded',
                    raw: {
                        product_id: 101,
                        product_name: 'Artisan Coffee',
                        sku: 'COF-101',
                        category_name: 'Beverages',
                        net_units: 2,
                        net_sales: 200.00,
                        net_cost: 100.00,
                        gross_profit: 100.00,
                        margin: 50.00,
                        status: 'recorded',
                        is_incomplete: false,
                        is_estimated: false,
                    },
                },
                {
                    SKU: 'TEA-102',
                    Product: 'Matcha Tea',
                    Category: 'Beverages',
                    'Net Units': 1,
                    'Gross Sales': '50.00',
                    Discounts: '5.00',
                    Refunds: '0.00',
                    'Net Sales': '45.00',
                    'Net Cost': '25.00',
                    'Product Cost': '25.00',
                    'Gross Profit': '20.00',
                    Margin: '44.44%',
                    Status: 'Recorded',
                    raw: {
                        product_id: 102,
                        product_name: 'Matcha Tea',
                        sku: 'TEA-102',
                        category_name: 'Beverages',
                        net_units: 1,
                        net_sales: 45.00,
                        net_cost: 25.00,
                        gross_profit: 20.00,
                        margin: 44.44,
                        status: 'recorded',
                        is_incomplete: false,
                        is_estimated: false,
                    },
                },
            ],
            summary: {
                total_units: 3,
                total_gross_sales: 250.00,
                total_discounts: 5.00,
                total_refunds: 0.00,
                total_net_sales: 245.00,
                total_net_cost: 125.00,
                total_gross_profit: 120.00,
                total_margin: 48.98,
                status: 'recorded',
                affected_lines_count: 0,
                known_net_sales: 245.00,
                known_net_cost: 125.00,
                known_gross_profit: 120.00,
                known_margin: 48.98,
                is_empty: false,
            },
        },
    };

    const browser = await chromium.launch({headless: true});

    try {
        console.log('Starting Ticket #232 Gross Profit Report Browser Tests...');

        // -------------------------------------------------------------
        // Test 1: Desktop Layout C (Table Left, Summary Right) at 1280px
        // -------------------------------------------------------------
        {
            const html = renderHtml(recordedState);
            const page = await browser.newPage({viewport: {width: 1280, height: 800}});

            await page.route('http://report.test/**', route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/assets/css/')) {
                    const cssFile = path.join(root, 'src/frontend', url.pathname);
                    if (fs.existsSync(cssFile)) {
                        return route.fulfill({contentType: 'text/css', body: fs.readFileSync(cssFile, 'utf8')});
                    }
                }
                return route.fulfill({contentType: 'text/html', body: html});
            });

            await page.goto('http://report.test/report_generation.php?report=gross_profit');

            // 1. Heading and Back to dashboard link
            assert.equal(await page.locator('#page-heading').innerText(), 'Gross Profit Report');
            assert.equal(await page.locator('#gross-profit-heading').innerText(), 'Gross Profit Report');
            const backLink = page.locator('#back-to-dashboard');
            assert(await backLink.isVisible(), 'Back to dashboard link must be visible');
            const backHref = await backLink.getAttribute('href');
            assert(backHref.includes('period=today'), 'Back to dashboard href must preserve period');
            assert(backHref.includes('from=2026-10-17'), 'Back to dashboard href must preserve from date');
            assert(backHref.includes('to=2026-10-17'), 'Back to dashboard href must preserve to date');

            // 2. Period Controls
            const todayBtn = page.locator('.period-btn[data-period="today"]');
            assert(await todayBtn.isVisible(), 'Today period button visible');
            assert.equal(await todayBtn.evaluate(el => el.classList.contains('is-active')), true, 'Today button is active');
            assert(await page.locator('.period-btn[data-period="week"]').isVisible(), 'This Week period button visible');
            assert(await page.locator('.period-btn[data-period="month"]').isVisible(), 'This Month period button visible');
            assert(await page.locator('.period-btn[data-period="custom"]').isVisible(), 'Custom period button visible');

            // 3. Desktop Split Verification: Table Left, Summary Right
            const tablePanel = page.locator('.gp-table-panel');
            const summaryPanel = page.locator('.gp-summary-panel');
            assert(await tablePanel.isVisible(), 'Table panel must be visible');
            assert(await summaryPanel.isVisible(), 'Summary panel must be visible');

            const tableBox = await tablePanel.boundingBox();
            const summaryBox = await summaryPanel.boundingBox();
            assert(tableBox && summaryBox, 'Both panels must have bounding boxes');
            assert(tableBox.x < summaryBox.x, `On desktop, table (${tableBox.x}) must be to the left of summary (${summaryBox.x})`);

            // 4. Product Table Columns & Net Units Explanation
            assert(await page.locator('#net-units-explanation').isVisible(), 'Net units explanation must be visible');
            const headers = await page.locator('.gp-product-table th').allInnerTexts();
            assert.deepEqual(headers, ['Product', 'Net Units', 'Net Sales', 'Product Cost', 'Gross Profit', 'Status']);

            const rows = page.locator('.gp-product-table tbody tr');
            assert.equal(await rows.count(), 2, 'Must render 2 product rows');

            // Row 1 checks
            const row1Text = await rows.first().innerText();
            assert(row1Text.includes('Artisan Coffee'), 'Row 1 product name');
            assert(row1Text.includes('COF-101'), 'Row 1 SKU');
            assert(row1Text.includes('Recorded'), 'Row 1 text status badge');

            // 5. At a glance Summary
            assert.equal(await page.locator('#at-a-glance-summary h3').innerText(), 'At a glance');
            assert.equal(await page.locator('#glance-gross-sales').innerText(), '₱250.00');
            assert.equal(await page.locator('#glance-discounts').innerText(), '-₱5.00');
            assert.equal(await page.locator('#glance-refunds').innerText(), '-₱0.00');
            assert.equal(await page.locator('#glance-net-sales').innerText(), '₱245.00');
            assert.equal(await page.locator('#glance-net-cost').innerText(), '-₱125.00');
            assert.equal(await page.locator('#glance-gross-profit').innerText(), '₱120.00');
            assert.equal(await page.locator('#glance-margin strong').innerText(), '48.98%');

            // 6. Return Help & Operating Expense Exclusion Note
            const returnHelp = page.locator('#profit-return-help');
            assert(await returnHelp.isVisible(), 'Return help details must be visible');
            assert(!await returnHelp.evaluate(el => el.open), 'Return help should be collapsed initially');
            await returnHelp.locator('summary').click();
            assert(await returnHelp.evaluate(el => el.open), 'Return help should expand on click');
            assert(await page.getByText('Restockable returns:').isVisible(), 'Restockable help text visible');
            assert(await page.getByText('Damaged / Disposed returns:').isVisible(), 'Damaged help text visible');

            const exclusionNote = page.locator('#profit-exclusion-note');
            assert(await exclusionNote.isVisible(), 'Operating expense exclusion note visible');
            assert(
                (await exclusionNote.innerText()).includes('Rent, wages, electricity, and other operating expenses are excluded'),
                'Exclusion note text must explain operating expenses'
            );

            await page.close();
            console.log('  ok - Desktop layout C (table left, summary right, columns, values, return help, exclusion note) verified');
        }

        // -------------------------------------------------------------
        // Test 2: Mobile / Narrow Screen Layout (375px)
        // -------------------------------------------------------------
        {
            const html = renderHtml(recordedState);
            const page = await browser.newPage({viewport: {width: 375, height: 667}});

            await page.route('http://report.test/**', route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/assets/css/')) {
                    const cssFile = path.join(root, 'src/frontend', url.pathname);
                    if (fs.existsSync(cssFile)) {
                        return route.fulfill({contentType: 'text/css', body: fs.readFileSync(cssFile, 'utf8')});
                    }
                }
                return route.fulfill({contentType: 'text/html', body: html});
            });

            await page.goto('http://report.test/report_generation.php?report=gross_profit');

            // 1. Stacking: Summary Top, Table Bottom
            const tablePanel = page.locator('.gp-table-panel');
            const summaryPanel = page.locator('.gp-summary-panel');

            const tableBox = await tablePanel.boundingBox();
            const summaryBox = await summaryPanel.boundingBox();
            assert(tableBox && summaryBox, 'Both panels must have bounding boxes');
            assert(summaryBox.y < tableBox.y, `On mobile, summary (${summaryBox.y}) must be stacked ABOVE table (${tableBox.y})`);

            // 2. Horizontally scrollable table container
            const tableWrap = page.locator('.gp-table-wrap');
            const scrollInfo = await tableWrap.evaluate(el => ({
                scrollWidth: el.scrollWidth,
                clientWidth: el.clientWidth,
                overflowX: window.getComputedStyle(el).overflowX,
            }));

            assert.equal(scrollInfo.overflowX, 'auto', 'Table container must have overflow-x: auto');
            assert(scrollInfo.scrollWidth > scrollInfo.clientWidth, `Table must be horizontally scrollable (${scrollInfo.scrollWidth} > ${scrollInfo.clientWidth})`);

            // 3. Columns retain readable min-width
            const tableWidth = await page.locator('.gp-product-table').evaluate(el => el.offsetWidth);
            assert(tableWidth >= 680, `Product table must maintain readable min-width (got ${tableWidth}px)`);

            await page.close();
            console.log('  ok - Mobile layout (summary stacked above table, horizontal scroll, readable column width) verified');
        }

        // -------------------------------------------------------------
        // Test 3: Incomplete Cost Status State
        // -------------------------------------------------------------
        {
            const incompleteState = JSON.parse(JSON.stringify(recordedState));
            incompleteState.report_data.rows[0].Status = 'Incomplete';
            incompleteState.report_data.rows[0].raw.status = 'incomplete';
            incompleteState.report_data.rows[0].raw.is_incomplete = true;
            incompleteState.report_data.rows[0].raw.net_cost = null;
            incompleteState.report_data.rows[0].raw.gross_profit = null;
            incompleteState.report_data.rows[0].raw.margin = null;

            incompleteState.report_data.summary.status = 'incomplete';
            incompleteState.report_data.summary.affected_lines_count = 1;
            incompleteState.report_data.summary.total_net_cost = null;
            incompleteState.report_data.summary.total_gross_profit = null;
            incompleteState.report_data.summary.total_margin = null;
            incompleteState.report_data.summary.known_net_sales = 45.00;
            incompleteState.report_data.summary.known_net_cost = 25.00;
            incompleteState.report_data.summary.known_gross_profit = 20.00;
            incompleteState.report_data.summary.known_margin = 44.44;

            const html = renderHtml(incompleteState);
            const page = await browser.newPage({viewport: {width: 1024, height: 768}});
            await page.route('http://report.test/**', route => route.fulfill({contentType: 'text/html', body: html}));
            await page.goto('http://report.test/');

            // Row 1 cost and profit withheld
            const row1Cells = await page.locator('.gp-product-table tbody tr').first().locator('td').allInnerTexts();
            assert.equal(row1Cells[3], '—', 'Incomplete product cost withheld as —');
            assert.equal(row1Cells[4], '—', 'Incomplete product gross profit withheld as —');
            assert.equal(row1Cells[5], 'Incomplete', 'Incomplete text status badge');

            // Summary cost, profit, and margin withheld
            assert.equal(await page.locator('#glance-net-cost').innerText(), '—', 'Summary Net Cost withheld as —');
            assert.equal(await page.locator('#glance-gross-profit').innerText(), '—', 'Summary Gross Profit withheld as —');
            assert.equal(await page.locator('#glance-margin').count(), 0, 'Margin badge omitted for incomplete totals');

            // Incomplete explanation notice with affected line count
            const notice = page.locator('#profit-incomplete-explanation');
            assert(await notice.isVisible(), 'Incomplete notice visible');
            assert((await notice.innerText()).includes('1 affected sold line'), 'Shows 1 affected sold line');

            // Known-cost subtotal panel
            const knownSubtotal = page.locator('#known-costs-subtotal');
            assert(await knownSubtotal.isVisible(), 'Known-cost subtotal panel visible');
            const subtotalText = await knownSubtotal.innerText();
            assert(subtotalText.includes('Known Net Sales ₱45.00'), 'Known Net Sales displayed');
            assert(subtotalText.includes('Known Product Cost ₱25.00'), 'Known Product Cost displayed');
            assert(subtotalText.includes('Known Gross Profit ₱20.00'), 'Known Gross Profit displayed');
            assert(subtotalText.includes('Known Margin 44.44%'), 'Known Margin displayed');

            await page.close();
            console.log('  ok - Incomplete state (withheld cost/profit/margin, affected line notice, known-cost subtotal) verified');
        }

        // -------------------------------------------------------------
        // Test 4: Estimated Cost Status State
        // -------------------------------------------------------------
        {
            const estimatedState = JSON.parse(JSON.stringify(recordedState));
            estimatedState.report_data.rows[0].Status = 'Estimated';
            estimatedState.report_data.rows[0].raw.status = 'estimated';
            estimatedState.report_data.rows[0].raw.is_estimated = true;
            estimatedState.report_data.rows[0].raw.margin = null;
            estimatedState.report_data.summary.status = 'estimated';
            estimatedState.report_data.summary.total_margin = null;

            const html = renderHtml(estimatedState);
            const page = await browser.newPage({viewport: {width: 1024, height: 768}});
            await page.route('http://report.test/**', route => route.fulfill({contentType: 'text/html', body: html}));
            await page.goto('http://report.test/');

            assert(await page.locator('.profit-status-badge.status-estimated').isVisible(), 'Estimated badge visible');
            assert.equal(await page.locator('#glance-margin').count(), 0, 'Margin badge withheld for estimated state');
            assert.equal(await page.locator('#glance-gross-profit').innerText(), '₱120.00', 'Gross Profit populated');
            assert.equal(await page.locator('#glance-net-cost').innerText(), '-₱125.00', 'Net Cost populated');

            await page.close();
            console.log('  ok - Estimated state (status badge, margin withheld, populated numeric cost/profit) verified');
        }

        // -------------------------------------------------------------
        // Test 5: Negative Profit (Loss) and Refund-Only State
        // -------------------------------------------------------------
        {
            const lossState = JSON.parse(JSON.stringify(recordedState));
            lossState.report_data.rows = [
                {
                    SKU: 'REF-ONLY',
                    Product: 'Refund-Only Item',
                    Category: 'Beverages',
                    'Net Units': -1,
                    'Gross Sales': '0.00',
                    Discounts: '0.00',
                    Refunds: '100.00',
                    'Net Sales': '-100.00',
                    'Net Cost': '-70.00',
                    'Product Cost': '-70.00',
                    'Gross Profit': '-30.00',
                    Margin: '-',
                    Status: 'Recorded',
                    raw: {
                        product_id: 103,
                        product_name: 'Refund-Only Item',
                        sku: 'REF-ONLY',
                        category_name: 'Beverages',
                        net_units: -1,
                        net_sales: -100.00,
                        net_cost: -70.00,
                        gross_profit: -30.00,
                        margin: null,
                        status: 'recorded',
                        is_incomplete: false,
                        is_estimated: false,
                    },
                },
            ];
            lossState.report_data.summary = {
                total_units: -1,
                total_gross_sales: 0.00,
                total_discounts: 0.00,
                total_refunds: 100.00,
                total_net_sales: -100.00,
                total_net_cost: -70.00,
                total_gross_profit: -30.00,
                total_margin: null,
                status: 'recorded',
                affected_lines_count: 0,
                known_net_sales: -100.00,
                known_net_cost: -70.00,
                known_gross_profit: -30.00,
                known_margin: null,
                is_empty: false,
            };

            const html = renderHtml(lossState);
            const page = await browser.newPage({viewport: {width: 1024, height: 768}});
            await page.route('http://report.test/**', route => route.fulfill({contentType: 'text/html', body: html}));
            await page.goto('http://report.test/');

            // Negative net units and sales in table
            const rowText = await page.locator('.gp-product-table tbody tr').first().innerText();
            assert(rowText.includes('-1'), 'Negative net units displayed');
            assert(rowText.includes('-₱100.00'), 'Negative net sales displayed');
            assert(rowText.includes('-₱70.00'), 'Negative product cost reversed displayed');
            assert(rowText.includes('-₱30.00'), 'Negative gross profit displayed');

            // Summary signed values
            assert.equal(await page.locator('#glance-net-sales').innerText(), '-₱100.00');
            assert.equal(await page.locator('#glance-gross-profit').innerText(), '-₱30.00');
            assert.equal(await page.locator('#glance-margin').count(), 0, 'Margin withheld for negative net sales');

            await page.close();
            console.log('  ok - Negative / refund-only state (negative units, negative cost reversal, signed profit) verified');
        }

        // -------------------------------------------------------------
        // Test 6: Empty Activity State
        // -------------------------------------------------------------
        {
            const emptyState = JSON.parse(JSON.stringify(recordedState));
            emptyState.report_data.rows = [];
            emptyState.report_data.summary = {
                total_units: 0,
                total_gross_sales: 0.00,
                total_discounts: 0.00,
                total_refunds: 0.00,
                total_net_sales: 0.00,
                total_net_cost: 0.00,
                total_gross_profit: 0.00,
                total_margin: null,
                status: 'recorded',
                affected_lines_count: 0,
                known_net_sales: 0.00,
                known_net_cost: 0.00,
                known_gross_profit: 0.00,
                known_margin: null,
                is_empty: true,
            };

            const html = renderHtml(emptyState);
            const page = await browser.newPage({viewport: {width: 1024, height: 768}});
            await page.route('http://report.test/**', route => route.fulfill({contentType: 'text/html', body: html}));
            await page.goto('http://report.test/');

            const emptyMsg = page.locator('.empty-table-message');
            assert(await emptyMsg.isVisible(), 'Empty message visible');
            assert((await emptyMsg.innerText()).includes('No sales or refund activity in the selected period'));

            assert.equal(await page.locator('#glance-net-sales').innerText(), '₱0.00');
            assert.equal(await page.locator('#glance-gross-profit').innerText(), '₱0.00');
            assert.equal(await page.locator('#glance-margin').count(), 0, 'No margin on empty activity');

            await page.close();
            console.log('  ok - Empty state (zero activity message and zeroed summary) verified');
        }

        // -------------------------------------------------------------
        // Test 7: Date Validation Error Display
        // -------------------------------------------------------------
        {
            const valErrorState = JSON.parse(JSON.stringify(recordedState));
            valErrorState.validation_error = 'Start date must not be after end date.';

            const html = renderHtml(valErrorState);
            const page = await browser.newPage({viewport: {width: 1024, height: 768}});
            await page.route('http://report.test/**', route => route.fulfill({contentType: 'text/html', body: html}));
            await page.goto('http://report.test/');

            const errorAlert = page.locator('#gp-validation-error');
            assert(await errorAlert.isVisible(), 'Validation error alert must be visible');
            assert.equal(await errorAlert.innerText(), 'Start date must not be after end date.');
            assert.equal(await page.locator('.gp-product-table').count(), 0, 'Table not rendered on error');
            assert.equal(await page.locator('#at-a-glance-summary').count(), 0, 'Summary not rendered on error');

            await page.close();
            console.log('  ok - Date validation error display (alert visible, invalid table suppressed) verified');
        }

        // -------------------------------------------------------------
        // Test 8: Keyboard Access & Focus
        // -------------------------------------------------------------
        {
            const html = renderHtml(recordedState);
            const page = await browser.newPage({viewport: {width: 1280, height: 800}});

            await page.route('http://report.test/**', route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/assets/css/')) {
                    const cssFile = path.join(root, 'src/frontend', url.pathname);
                    if (fs.existsSync(cssFile)) {
                        return route.fulfill({contentType: 'text/css', body: fs.readFileSync(cssFile, 'utf8')});
                    }
                }
                return route.fulfill({contentType: 'text/html', body: html});
            });

            await page.goto('http://report.test/report_generation.php?report=gross_profit');

            // Verify Back to dashboard link can receive focus
            await page.locator('#back-to-dashboard').focus();
            const focusedId = await page.evaluate(() => document.activeElement.id);
            assert.equal(focusedId, 'back-to-dashboard', 'Back to dashboard link must be focusable');

            // Verify period buttons can be focused and have accessible labels
            const periodBtn = page.locator('.period-btn[data-period="week"]');
            await periodBtn.focus();
            const periodFocused = await page.evaluate(() => document.activeElement.getAttribute('data-period'));
            assert.equal(periodFocused, 'week', 'Period button must be focusable');

            // Verify return help details summary can be focused and toggled via keyboard Enter
            const summaryEl = page.locator('#profit-return-help summary');
            await summaryEl.focus();
            await page.keyboard.press('Enter');
            assert(await page.locator('#profit-return-help').evaluate(el => el.open), 'Details drawer opens on keyboard Enter');

            // Verify Export CSV button can be focused
            await page.locator('#export-csv-btn').focus();
            assert.equal(await page.evaluate(() => document.activeElement.id), 'export-csv-btn', 'Export CSV button must be focusable');

            await page.close();
            console.log('  ok - Keyboard access (focus navigation, details toggle via Enter, export button) verified');
        }

    } finally {
        await browser.close();
    }

    console.log('\nGross profit report browser tests: all passed!');
})();
