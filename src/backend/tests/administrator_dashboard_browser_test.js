const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const source = fs.readFileSync(path.join(root, 'src/frontend/components/administrator/dashboard.php'), 'utf8');
    const template = source.slice(source.indexOf('<!DOCTYPE html>')).replace(/<\?php include __DIR__ .*?\?>/, '');
    const phpBinary = process.env.PHP_BINARY || 'php';

    const browser = await chromium.launch();
    try {
        // Test 1: Populated state with Today, Week, and Month periods
        {
            const testPayload = JSON.stringify({
                state: 'ready',
                role: 'admin',
                range_days: 30,
                headline: {
                    sales_cash_exceptions: {label: 'Sales & cash exceptions', value: 3, status: 'warning', detail: '3 exceptions'},
                    inventory_risks: {label: 'Pending Stock Issues', value: 1, status: 'warning', detail: '1 pending'},
                    fiscal_period: {label: 'Current Fiscal Period', value: 'October 2026', status: 'open', detail: 'Open'},
                },
                exceptions: [
                    {severity: 'warning', title: 'Sales reversal', detail: 'Refund · Dispute', detected_at: '2026-10-17 09:00:00', destination: 'components/invoice/sales.php'},
                ],
                comparison: {
                    sales: {current: 305.25, previous: 200.0, change_percent: 52.6},
                    transactions: {current: 5, previous: 4, change_percent: 25.0},
                    unusual_discounts: {current: 1, previous: 0, change_percent: null},
                    reversals: {current: 1, previous: 0, change_percent: null},
                },
                staff: {active_cashiers: 2, active_inventory_managers: 1, disabled_staff: 0},
                shifts: {open: 1, unreviewed_variances: 0},
                forecast: {evaluated_products: 5, evaluations: [], operational_exceptions: 0},
                inventory: {escalated: 1, out_of_stock: 0, low_stock: 2},
                actions: [{label: 'Store Staff', description: 'Manage staff', icon: 'bi-people', destination: 'components/user_manager/user_manager.php'}],
                freshness: {generated_at: '2026-10-17 10:00:00', stale_after: '2026-10-17 10:05:00', refresh_seconds: 300, is_stale: false},
                message: null,
                net_sales: {
                    period: 'today',
                    status: 'ongoing',
                    as_of: '2026-10-17 10:00:00',
                    timezone: 'Asia/Manila',
                    total: 305.25,
                    sales_total: 375.25,
                    refunds_total: 70.00,
                    has_activity: true,
                    points: [
                        {hour: 0, label: '00:00', sales: 50.00, refunds: 0.00, net_sales: 50.00},
                        {hour: 1, label: '01:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                        {hour: 2, label: '02:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                        {hour: 3, label: '03:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                        {hour: 4, label: '04:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                        {hour: 5, label: '05:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                        {hour: 6, label: '06:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                        {hour: 7, label: '07:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                        {hour: 8, label: '08:00', sales: 225.25, refunds: 30.00, net_sales: 195.25},
                        {hour: 9, label: '09:00', sales: 0.00, refunds: 40.00, net_sales: -40.00},
                        {hour: 10, label: '10:00', sales: 100.00, refunds: 0.00, net_sales: 100.00},
                    ],
                    periods: {
                        today: {
                            period: 'today',
                            label: "Today's Net Sales",
                            short_label: 'Today',
                            interval_type: 'hourly',
                            interval_label: 'Hour',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 305.25,
                            sales_total: 375.25,
                            refunds_total: 70.00,
                            has_activity: true,
                            points: [
                                {hour: 0, label: '00:00', sales: 50.00, refunds: 0.00, net_sales: 50.00},
                                {hour: 1, label: '01:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {hour: 2, label: '02:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {hour: 3, label: '03:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {hour: 4, label: '04:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {hour: 5, label: '05:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {hour: 6, label: '06:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {hour: 7, label: '07:00', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {hour: 8, label: '08:00', sales: 225.25, refunds: 30.00, net_sales: 195.25},
                                {hour: 9, label: '09:00', sales: 0.00, refunds: 40.00, net_sales: -40.00},
                                {hour: 10, label: '10:00', sales: 100.00, refunds: 0.00, net_sales: 100.00},
                            ],
                        },
                        week: {
                            period: 'week',
                            label: "This Week's Net Sales",
                            short_label: 'This Week',
                            interval_type: 'daily',
                            interval_label: 'Day',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 655.25,
                            sales_total: 825.25,
                            refunds_total: 170.00,
                            has_activity: true,
                            points: [
                                {day: 'Mon', label: 'Mon', date: '2026-10-12', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {day: 'Tue', label: 'Tue', date: '2026-10-13', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {day: 'Wed', label: 'Wed', date: '2026-10-14', sales: 0.00, refunds: 0.00, net_sales: 0.00},
                                {day: 'Thu', label: 'Thu', date: '2026-10-15', sales: 450.00, refunds: 100.00, net_sales: 350.00},
                                {day: 'Fri', label: 'Fri', date: '2026-10-16', sales: 375.25, refunds: 70.00, net_sales: 305.25},
                            ],
                        },
                        month: {
                            period: 'month',
                            label: "This Month's Net Sales",
                            short_label: 'This Month',
                            interval_type: 'daily',
                            interval_label: 'Day',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 850.50,
                            sales_total: 1050.50,
                            refunds_total: 200.00,
                            has_activity: true,
                            points: Array.from({length: 17}, (_, idx) => {
                                const d = idx + 1;
                                return {
                                    day: d,
                                    label: `Oct ${d}`,
                                    date: `2026-10-${String(d).padStart(2, '0')}`,
                                    sales: d === 15 ? 450 : (d === 16 ? 375.25 : (d === 17 ? 225.25 : 0)),
                                    refunds: d === 15 ? 100 : (d === 16 ? 70 : (d === 17 ? 30 : 0)),
                                    net_sales: d === 15 ? 350 : (d === 16 ? 305.25 : (d === 17 ? 195.25 : 0)),
                                };
                            }),
                        },
                    },
                },
            });

            const html = execFileSync(phpBinary, ['-r', `
                function retailmind_theme_head() {}
                function app_url($p) { return '/'.$p; }
                function theme_url($p) { return '/'.$p; }
                $displayName = 'Ada Administrator';
                $selectedRange = 30;
                $rangeUrl = '/components/administrator/dashboard.php?range=30';
                $dashboard = json_decode($argv[1], true);
                $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                $formatDate = static fn(?string $v): string => $v ? (new DateTimeImmutable($v))->format('m-d-Y g:i A') : 'Unavailable';
                $formatMetric = static fn(float|int $v, bool $c = false): string => ($c ? '₱' : '') . number_format((float)$v, $c ? 2 : 0);
                $formatCurrency = static fn(float|int $v): string => ($v < 0 ? '-₱' : '₱') . number_format(abs((float)$v), 2);
                $formatChange = static fn(?float $c): string => $c === null ? 'No prior baseline' : ($c > 0 ? '+' : '') . number_format($c, 1) . '%';
                $netSales = $dashboard['net_sales'] ?? null;
                $hasNetSales = $netSales !== null && is_array($netSales);
                $netSalesPeriods = $hasNetSales ? ($netSales['periods'] ?? ['today' => $netSales]) : [];
                $selectedPeriod = 'today';
                $activePeriodData = $netSalesPeriods[$selectedPeriod] ?? [];
                $activePeriodTitle = "Today's Net Sales";
                $activePeriodTotal = $hasNetSales ? ($activePeriodData['total'] ?? 0.0) : null;
                $activePeriodPoints = $hasNetSales ? ($activePeriodData['points'] ?? []) : [];
                $activePeriodHasActivity = $hasNetSales && ($activePeriodData['has_activity'] ?? false);
                $activePeriodIntervalLabel = 'Hour';
                $netSalesPoints = $activePeriodPoints;
                $netSalesTotal = $activePeriodTotal;
                $netSalesHasActivity = $activePeriodHasActivity;
                $netSalesAsOf = $hasNetSales ? ($netSales['as_of'] ?? null) : null;
                $headlineOrder = [
                    'sales_cash_exceptions' => ['Sales & cash exceptions', 'bi-exclamation-diamond'],
                    'inventory_risks' => ['Pending Stock Issues', 'bi-box-seam'],
                    'fiscal_period' => ['Current Fiscal Period', 'bi-calendar-check'],
                ];
                $comparisonOrder = [
                    'sales' => ['Sales', true],
                    'transactions' => ['Transactions', false],
                    'unusual_discounts' => ['Unusual discounts', false],
                    'reversals' => ['Reversals', false],
                ];
                $grossProfit = null;
                $hasGrossProfit = false;
                $grossProfitPeriods = [];
                $activeProfitData = [];
                $profitStatus = 'recorded';
                $profitTotal = null;
                $costTotal = null;
                $profitMargin = null;
                $affectedLinesCount = 0;
                $activeProfitTitle = "Today's Gross Profit";
                $activeCostTitle = "Today's Product Cost";
                $profitReportUrl = '#';
                eval('?>'.stream_get_contents(STDIN));
            `, testPayload], {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/administrator')});

            const page = await browser.newPage();
            await page.route('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', route => route.fulfill({
                contentType: 'application/javascript',
                body: 'window.Chart = class { constructor(canvas, config) { this.id = canvas.id; this.config = config; this.data = config.data; this.options = config.options; } update() {} };'
            }));
            await page.route('http://dashboard.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                return route.fulfill(asset ? {path: path.join(root, 'src/frontend/assets', asset[1])} : {contentType: 'text/html', body: html});
            });

            await page.goto('http://dashboard.test/');

            // Verify removed sections are absent
            assert.equal(await page.locator('#store-tools-title').count(), 0, 'Store governance section must be absent');
            assert.equal(await page.locator('#inventory-title').count(), 0, 'Inventory oversight section must be absent');
            assert.equal(await page.locator('#forecast-title').count(), 0, 'Demand Forecast performance section must be absent');

            assert.equal(await page.locator('.period-comparison #dashboardRange').count(), 1, 'Comparison control stays beside performance');

            // Verify retained sections remain visible
            assert(await page.locator('#store-attention-title').isVisible(), 'Store attention queue must be visible');
            assert(await page.locator('#store-status-title').isVisible(), 'Store status must be visible');
            assert(await page.locator('#comparison-title').isVisible(), 'Period comparison must be visible');
            assert(await page.locator('#staff-shifts-title').isVisible(), 'Staff & cashier shifts must be visible');
            const storeStatusBox = await page.locator('#store-status-title').boundingBox();
            const salesStatisticsBox = await page.locator('.net-sales-section').boundingBox();
            assert(storeStatusBox.y < salesStatisticsBox.y, 'Store status must appear above sales statistics');

            // Verify staff heading icon and alignment
            assert.equal(await page.locator('#staff-shifts-title i.bi.bi-people').getAttribute('aria-hidden'), 'true', 'Staff heading icon must have aria-hidden="true"');
            const headingDisplay = await page.locator('#staff-shifts-title').evaluate(el => window.getComputedStyle(el).display);
            const headingAlign = await page.locator('#staff-shifts-title').evaluate(el => window.getComputedStyle(el).alignItems);
            assert.equal(headingDisplay, 'inline-flex', 'Staff heading should use inline-flex for alignment');
            assert.equal(headingAlign, 'center', 'Staff heading should vertically center icon with text');
            assert.equal(await page.locator('.staff-shifts-section .indicator-icon').count(), 5, 'Staff and cashier-shift indicators each show an icon');
            const firstIndicatorIcon = await page.locator('.staff-shifts-section .indicator-icon').first().boundingBox();
            const firstIndicatorCopy = await page.locator('.staff-shifts-section .indicator-copy').first().boundingBox();
            const indicatorGap = firstIndicatorCopy.x - (firstIndicatorIcon.x + firstIndicatorIcon.width);
            assert(indicatorGap >= 0 && indicatorGap <= 20, 'Indicator label must sit beside its icon');

            // Verify staff section placement after period comparison
            const comparisonBox = await page.locator('.period-comparison').boundingBox();
            const staffBox = await page.locator('.staff-shifts-section').boundingBox();
            assert(staffBox.y > comparisonBox.y, 'Staff section must be placed after performance comparison');

            // 1. Verify Net Sales heading and ongoing badge
            assert(await page.locator('#net-sales-title').isVisible(), 'Net sales title must be visible');
            assert.match(await page.locator('.net-sales-title-wrap .period-badge.ongoing').innerText(), /ongoing/i);

            // 2. Verify all three Net Sales summaries: Today, This Week, and This Month
            assert.equal(await page.locator('.net-sales-summary-card[data-summary-period="today"] .value').innerText(), '₱305.25');
            assert.equal(await page.locator('.net-sales-summary-card[data-summary-period="week"] .value').innerText(), '₱655.25');
            assert.equal(await page.locator('.net-sales-summary-card[data-summary-period="month"] .value').innerText(), '₱850.50');
            assert(await page.locator('.net-sales-summary-card[data-summary-period="today"]').evaluate(el => el.classList.contains('is-selected')));

            // 3. Verify selected period total beside chart defaults to Today: ₱305.25
            assert.equal(await page.locator('.net-sales-summary-stat .net-sales-summary-value').innerText(), '₱305.25');

            // 4. Verify Chart.js canvas and config for default Today
            assert.equal(await page.locator('#net-sales-hourly-chart').count(), 1);
            let chart = await page.evaluate(() => window.netSalesChart);
            assert.ok(chart, 'netSalesChart must be initialized');
            assert.equal(chart.id, 'net-sales-hourly-chart');
            assert.equal(chart.config.type, 'bar');
            assert.equal(chart.config.data.labels.length, 11);
            assert.deepEqual(chart.config.data.labels, ['00:00','01:00','02:00','03:00','04:00','05:00','06:00','07:00','08:00','09:00','10:00']);
            assert.deepEqual(chart.config.data.datasets[0].data, [50, 0, 0, 0, 0, 0, 0, 0, 195.25, -40, 100]);
            assert.equal(chart.config.options.scales.y.beginAtZero, true, 'Y scale must begin at zero to support negative values');

            // 5. Negative-axis color rendering: negative value (-40) rendered in negativeColor (#ef4444)
            const colors = chart.config.data.datasets[0].backgroundColor;
            assert.equal(colors[9], '#ef4444', 'Hour 9 negative net sales must use negative color');

            // 6. Accessible table checks: exact values for Today
            let rows = page.locator('.net-sales-table tbody tr');
            assert.equal(await rows.count(), 11, 'Table must have 11 hourly rows for Today');
            assert.equal(await rows.nth(8).locator('td[data-label="Hour"]').innerText(), '08:00');
            assert.equal(await rows.nth(8).locator('td[data-label="Net Sales"]').innerText(), '₱195.25');
            assert.equal(await rows.nth(9).locator('td[data-label="Hour"]').innerText(), '09:00');
            assert.equal(await rows.nth(9).locator('td[data-label="Net Sales"]').innerText(), '-₱40.00');
            assert(await rows.nth(9).locator('td[data-label="Net Sales"]').evaluate(el => el.classList.contains('text-negative')));

            // 7. Period controls visibility and keyboard/accessibility attributes
            assert.equal(await page.locator('.period-btn[data-period="today"]').getAttribute('aria-pressed'), 'true');
            assert.equal(await page.locator('.period-btn[data-period="week"]').getAttribute('aria-pressed'), 'false');
            assert.equal(await page.locator('.period-btn[data-period="month"]').getAttribute('aria-pressed'), 'false');

            // 8. Period Switching: Click "This Week"
            await page.locator('.period-btn[data-period="week"]').click();
            assert.equal(await page.locator('#net-sales-title').innerText(), "This Week's Net Sales");
            assert.equal(await page.locator('.net-sales-summary-stat .net-sales-summary-value').innerText(), '₱655.25');
            assert.equal(await page.locator('.period-btn[data-period="week"]').getAttribute('aria-pressed'), 'true');
            assert.equal(await page.locator('.period-btn[data-period="today"]').getAttribute('aria-pressed'), 'false');
            assert(await page.locator('.net-sales-summary-card[data-summary-period="week"]').evaluate(el => el.classList.contains('is-selected')));
            assert(!await page.locator('.net-sales-summary-card[data-summary-period="today"]').evaluate(el => el.classList.contains('is-selected')));

            // Check week chart update
            const weekChartData = await page.evaluate(() => window.netSalesChart.data);
            assert.deepEqual(weekChartData.labels, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']);
            assert.deepEqual(weekChartData.datasets[0].data, [0, 0, 0, 350, 305.25]);

            // Check week table update
            rows = page.locator('.net-sales-table tbody tr');
            assert.equal(await rows.count(), 5, 'Week breakdown table must have 5 daily rows');
            assert.equal(await rows.nth(3).locator('td[data-label="Day"]').innerText(), 'Thu');
            assert.equal(await rows.nth(3).locator('td[data-label="Net Sales"]').innerText(), '₱350.00');
            assert.equal(await rows.nth(4).locator('td[data-label="Day"]').innerText(), 'Fri');
            assert.equal(await rows.nth(4).locator('td[data-label="Net Sales"]').innerText(), '₱305.25');

            // Verify URL updated without reload
            assert(await page.evaluate(() => window.location.search.includes('period=week')), 'URL must include period=week');
            assert.equal(await page.locator('#rangeFormPeriod').inputValue(), 'week', 'Range form must retain period=week');

            // 9. Period Switching: Click "This Month"
            await page.locator('.period-btn[data-period="month"]').click();
            assert.equal(await page.locator('#net-sales-title').innerText(), "This Month's Net Sales");
            assert.equal(await page.locator('.net-sales-summary-stat .net-sales-summary-value').innerText(), '₱850.50');
            assert.equal(await page.locator('.period-btn[data-period="month"]').getAttribute('aria-pressed'), 'true');
            assert(await page.locator('.net-sales-summary-card[data-summary-period="month"]').evaluate(el => el.classList.contains('is-selected')));
            assert(await page.evaluate(() => window.location.search.includes('period=month')), 'URL must include period=month');
            rows = page.locator('.net-sales-table tbody tr');
            assert.equal(await rows.count(), 17, 'Month table must have 17 daily rows');

            // 10. Switch back to Today via keyboard interaction
            const todayBtn = page.locator('.period-btn[data-period="today"]');
            await todayBtn.focus();
            await page.keyboard.press('Enter');
            assert.equal(await page.locator('#net-sales-title').innerText(), "Today's Net Sales");
            assert.equal(await page.locator('.net-sales-summary-stat .net-sales-summary-value').innerText(), '₱305.25');
            assert(await page.evaluate(() => window.location.search.includes('period=today')), 'URL must include period=today');

            // 10b. Keyboard interaction: press Space on "This Week"
            const weekBtn = page.locator('.period-btn[data-period="week"]');
            await weekBtn.focus();
            await page.keyboard.press('Space');
            assert.equal(await page.locator('#net-sales-title').innerText(), "This Week's Net Sales");
            assert.equal(await weekBtn.getAttribute('aria-pressed'), 'true');
            await todayBtn.click();

            // 11. Responsive layout test: Desktop, tablet, mobile
            for (const width of [1200, 1024, 768, 640, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Dashboard fits at ${width}px without horizontal overflow`);
                assert(await page.locator('.net-sales-section').evaluate(el => el.scrollWidth <= el.clientWidth), `Net sales section fits at ${width}px`);
                assert(await page.locator('.net-sales-summaries-grid').evaluate(el => el.scrollWidth <= el.clientWidth), `Summaries grid fits at ${width}px`);
                assert(await page.locator('.net-sales-table-wrap').evaluate(el => getComputedStyle(el).overflowX === 'auto' && el.getBoundingClientRect().right <= innerWidth), `Exact breakdown remains scrollable within viewport at ${width}px`);
                assert(await page.locator('.net-sales-breakdown-col').first().isVisible(), `Sales and refunds remain available at ${width}px`);
                assert(await page.locator('.staff-shifts-section').evaluate(el => el.scrollWidth <= el.clientWidth), `Staff section fits at ${width}px`);
                assert.equal(await page.locator('#staff-shifts-title i.bi.bi-people').count(), 1, `Staff icon present at ${width}px`);
                assert.equal(await page.locator('#staff-shifts-title').evaluate(el => window.getComputedStyle(el).display), 'inline-flex', `Staff heading aligned at ${width}px`);
            }

            await page.close();
        }

        // Test 2: Zero activity state
        {
            const zeroPayload = JSON.stringify({
                state: 'ready',
                role: 'admin',
                range_days: 30,
                headline: {
                    sales_cash_exceptions: {label: 'Sales & cash exceptions', value: 0, status: 'healthy', detail: 'Clean'},
                    inventory_risks: {label: 'Pending Stock Issues', value: 0, status: 'healthy', detail: 'Clean'},
                    fiscal_period: {label: 'Current Fiscal Period', value: 'October 2026', status: 'open', detail: 'Open'},
                },
                exceptions: [],
                comparison: {
                    sales: {current: 0, previous: 0, change_percent: null},
                    transactions: {current: 0, previous: 0, change_percent: null},
                    unusual_discounts: {current: 0, previous: 0, change_percent: null},
                    reversals: {current: 0, previous: 0, change_percent: null},
                },
                staff: {active_cashiers: 2, active_inventory_managers: 1, disabled_staff: 0},
                shifts: {open: 0, unreviewed_variances: 0},
                forecast: {evaluated_products: 0, evaluations: [], operational_exceptions: 0},
                inventory: {escalated: 0, out_of_stock: 0, low_stock: 0},
                actions: [],
                freshness: {generated_at: '2026-10-17 10:00:00', stale_after: '2026-10-17 10:05:00', refresh_seconds: 300, is_stale: false},
                message: null,
                net_sales: {
                    period: 'today',
                    status: 'ongoing',
                    as_of: '2026-10-17 10:00:00',
                    timezone: 'Asia/Manila',
                    total: 0.00,
                    sales_total: 0.00,
                    refunds_total: 0.00,
                    has_activity: false,
                    points: Array.from({length: 11}, (_, h) => ({hour: h, label: String(h).padStart(2, '0') + ':00', sales: 0, refunds: 0, net_sales: 0})),
                    periods: {
                        today: {
                            period: 'today',
                            label: "Today's Net Sales",
                            short_label: 'Today',
                            interval_type: 'hourly',
                            interval_label: 'Hour',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 0.00,
                            sales_total: 0.00,
                            refunds_total: 0.00,
                            has_activity: false,
                            points: Array.from({length: 11}, (_, h) => ({hour: h, label: String(h).padStart(2, '0') + ':00', sales: 0, refunds: 0, net_sales: 0})),
                        },
                        week: {
                            period: 'week',
                            label: "This Week's Net Sales",
                            short_label: 'This Week',
                            interval_type: 'daily',
                            interval_label: 'Day',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 0.00,
                            sales_total: 0.00,
                            refunds_total: 0.00,
                            has_activity: false,
                            points: [{day: 'Mon', label: 'Mon', date: '2026-10-12', sales: 0, refunds: 0, net_sales: 0}],
                        },
                        month: {
                            period: 'month',
                            label: "This Month's Net Sales",
                            short_label: 'This Month',
                            interval_type: 'daily',
                            interval_label: 'Day',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 0.00,
                            sales_total: 0.00,
                            refunds_total: 0.00,
                            has_activity: false,
                            points: [{day: 1, label: 'Oct 1', date: '2026-10-01', sales: 0, refunds: 0, net_sales: 0}],
                        },
                    },
                },
            });

            const html = execFileSync(phpBinary, ['-r', `
                function retailmind_theme_head() {}
                function app_url($p) { return '/'.$p; }
                function theme_url($p) { return '/'.$p; }
                $displayName = 'Ada Administrator';
                $selectedRange = 30;
                $rangeUrl = '/components/administrator/dashboard.php?range=30';
                $dashboard = json_decode($argv[1], true);
                $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                $formatDate = static fn(?string $v): string => $v ? (new DateTimeImmutable($v))->format('m-d-Y g:i A') : 'Unavailable';
                $formatMetric = static fn(float|int $v, bool $c = false): string => ($c ? '₱' : '') . number_format((float)$v, $c ? 2 : 0);
                $formatCurrency = static fn(float|int $v): string => ($v < 0 ? '-₱' : '₱') . number_format(abs((float)$v), 2);
                $formatChange = static fn(?float $c): string => $c === null ? 'No prior baseline' : ($c > 0 ? '+' : '') . number_format($c, 1) . '%';
                $netSales = $dashboard['net_sales'] ?? null;
                $hasNetSales = $netSales !== null && is_array($netSales);
                $netSalesPeriods = $hasNetSales ? ($netSales['periods'] ?? ['today' => $netSales]) : [];
                $selectedPeriod = 'today';
                $activePeriodData = $netSalesPeriods[$selectedPeriod] ?? [];
                $activePeriodTitle = "Today's Net Sales";
                $activePeriodTotal = $hasNetSales ? ($activePeriodData['total'] ?? 0.0) : null;
                $activePeriodPoints = $hasNetSales ? ($activePeriodData['points'] ?? []) : [];
                $activePeriodHasActivity = $hasNetSales && ($activePeriodData['has_activity'] ?? false);
                $activePeriodIntervalLabel = 'Hour';
                $netSalesPoints = $activePeriodPoints;
                $netSalesTotal = $activePeriodTotal;
                $netSalesHasActivity = $activePeriodHasActivity;
                $netSalesAsOf = $hasNetSales ? ($netSales['as_of'] ?? null) : null;
                $headlineOrder = [];
                $comparisonOrder = [];
                $grossProfit = null;
                $hasGrossProfit = false;
                $grossProfitPeriods = [];
                $activeProfitData = [];
                $profitStatus = 'recorded';
                $profitTotal = null;
                $costTotal = null;
                $profitMargin = null;
                $affectedLinesCount = 0;
                $activeProfitTitle = "Today's Gross Profit";
                $activeCostTitle = "Today's Product Cost";
                $profitReportUrl = '#';
                eval('?>'.stream_get_contents(STDIN));
            `, zeroPayload], {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/administrator')});

            const page = await browser.newPage();
            await page.route('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', route => route.fulfill({
                contentType: 'application/javascript',
                body: 'window.Chart = class { constructor(canvas, config) { this.id = canvas.id; this.config = config; this.data = config.data; this.options = config.options; } update() {} };'
            }));
            await page.route('http://dashboard.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                return route.fulfill(asset ? {path: path.join(root, 'src/frontend/assets', asset[1])} : {contentType: 'text/html', body: html});
            });

            await page.goto('http://dashboard.test/');
            assert.equal(await page.locator('.net-sales-summary-stat .net-sales-summary-value').innerText(), '₱0.00');
            assert.equal(await page.locator('.net-sales-summary-card[data-summary-period="today"] .value').innerText(), '₱0.00');
            assert(await page.getByText('No sales or refunds recorded today.').isVisible(), 'No-activity note must be visible');
            await page.close();
        }

        // Test 3: Data-load error state (never displays unavailable totals as zero)
        {
            const errorPayload = JSON.stringify({
                state: 'error',
                role: 'admin',
                range_days: 30,
                headline: [],
                exceptions: [],
                comparison: [],
                staff: [],
                shifts: [],
                forecast: [],
                inventory: [],
                actions: [],
                freshness: {generated_at: '2026-10-17 10:00:00', stale_after: '2026-10-17 10:05:00', refresh_seconds: 300, is_stale: false},
                message: 'Current Store operations could not be loaded.',
                net_sales: null, // Error: net_sales unavailable
            });

            const html = execFileSync(phpBinary, ['-r', `
                function retailmind_theme_head() {}
                function app_url($p) { return '/'.$p; }
                function theme_url($p) { return '/'.$p; }
                $displayName = 'Ada Administrator';
                $selectedRange = 30;
                $rangeUrl = '/components/administrator/dashboard.php?range=30';
                $dashboard = json_decode($argv[1], true);
                $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                $formatDate = static fn(?string $v): string => $v ? (new DateTimeImmutable($v))->format('m-d-Y g:i A') : 'Unavailable';
                $formatMetric = static fn(float|int $v, bool $c = false): string => ($c ? '₱' : '') . number_format((float)$v, $c ? 2 : 0);
                $formatCurrency = static fn(float|int $v): string => ($v < 0 ? '-₱' : '₱') . number_format(abs((float)$v), 2);
                $formatChange = static fn(?float $c): string => $c === null ? 'No prior baseline' : ($c > 0 ? '+' : '') . number_format($c, 1) . '%';
                $netSales = $dashboard['net_sales'] ?? null;
                $hasNetSales = $netSales !== null && is_array($netSales);
                $netSalesPeriods = $hasNetSales ? ($netSales['periods'] ?? ['today' => $netSales]) : [];
                $selectedPeriod = 'today';
                $activePeriodData = $netSalesPeriods[$selectedPeriod] ?? [];
                $activePeriodTitle = "Today's Net Sales";
                $activePeriodTotal = $hasNetSales ? ($activePeriodData['total'] ?? 0.0) : null;
                $activePeriodPoints = $hasNetSales ? ($activePeriodData['points'] ?? []) : [];
                $activePeriodHasActivity = $hasNetSales && ($activePeriodData['has_activity'] ?? false);
                $activePeriodIntervalLabel = 'Hour';
                $netSalesPoints = $activePeriodPoints;
                $netSalesTotal = $activePeriodTotal;
                $netSalesHasActivity = $activePeriodHasActivity;
                $netSalesAsOf = $hasNetSales ? ($netSales['as_of'] ?? null) : null;
                $headlineOrder = [];
                $comparisonOrder = [];
                $grossProfit = null;
                $hasGrossProfit = false;
                $grossProfitPeriods = [];
                $activeProfitData = [];
                $profitStatus = 'recorded';
                $profitTotal = null;
                $costTotal = null;
                $profitMargin = null;
                $affectedLinesCount = 0;
                $activeProfitTitle = "Today's Gross Profit";
                $activeCostTitle = "Today's Product Cost";
                $profitReportUrl = '#';
                eval('?>'.stream_get_contents(STDIN));
            `, errorPayload], {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/administrator')});

            const page = await browser.newPage();
            await page.route('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', route => route.fulfill({
                contentType: 'application/javascript',
                body: 'window.Chart = class { constructor(canvas, config) { this.id = canvas.id; this.config = config; this.data = config.data; this.options = config.options; } update() {} };'
            }));
            await page.route('http://dashboard.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                return route.fulfill(asset ? {path: path.join(root, 'src/frontend/assets', asset[1])} : {contentType: 'text/html', body: html});
            });

            await page.goto('http://dashboard.test/');
            const totalText = await page.locator('.net-sales-summary-stat .net-sales-summary-value').innerText();
            assert.equal(totalText, 'Unavailable', 'Error state must display Unavailable, never 0.00');
            assert.notEqual(totalText, '₱0.00', 'Unavailable total must NEVER display as zero');
            assert.equal(await page.locator('.net-sales-summary-card[data-summary-period="today"] .value').innerText(), 'Unavailable');
            assert.equal(await page.locator('.net-sales-summary-card[data-summary-period="week"] .value').innerText(), 'Unavailable');
            assert.equal(await page.locator('.net-sales-summary-card[data-summary-period="month"] .value').innerText(), 'Unavailable');
            assert(await page.locator('.net-sales-section .dashboard-state-error strong').isVisible(), 'Error banner must be visible');
            assert.equal(await page.locator('#net-sales-hourly-chart').count(), 0, 'Chart must not render in error state');
            await page.close();
        }

        // Test 4: Layout A with Gross Profit (Ticket #231)
        {
            const layoutAPayload = JSON.stringify({
                state: 'ready',
                role: 'admin',
                range_days: 30,
                headline: [],
                exceptions: [],
                comparison: [],
                staff: {active_cashiers: 1, active_inventory_managers: 1, disabled_staff: 0},
                shifts: {open: 1, unreviewed_variances: 0},
                freshness: {generated_at: '2026-10-17 10:00:00', stale_after: '2026-10-17 10:05:00', refresh_seconds: 300, is_stale: false},
                message: null,
                net_sales: {
                    period: 'today',
                    status: 'ongoing',
                    as_of: '2026-10-17 10:00:00',
                    timezone: 'Asia/Manila',
                    total: 305.25,
                    sales_total: 375.25,
                    refunds_total: 70.00,
                    has_activity: true,
                    points: [{hour: 10, label: '10:00', sales: 375.25, refunds: 70.00, net_sales: 305.25}],
                    periods: {
                        today: {
                            period: 'today',
                            label: "Today's Net Sales",
                            interval_type: 'hourly',
                            interval_label: 'Hour',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 305.25,
                            sales_total: 375.25,
                            refunds_total: 70.00,
                            has_activity: true,
                            points: [{hour: 10, label: '10:00', sales: 375.25, refunds: 70.00, net_sales: 305.25}],
                        },
                        week: {
                            period: 'week',
                            label: "This Week's Net Sales",
                            interval_type: 'daily',
                            interval_label: 'Day',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 655.25,
                            sales_total: 825.25,
                            refunds_total: 170.00,
                            has_activity: true,
                            points: [{day: 'Fri', label: 'Fri', date: '2026-10-16', sales: 825.25, refunds: 170.00, net_sales: 655.25}],
                        },
                        month: {
                            period: 'month',
                            label: "This Month's Net Sales",
                            interval_type: 'daily',
                            interval_label: 'Day',
                            status: 'ongoing',
                            as_of: '2026-10-17 10:00:00',
                            timezone: 'Asia/Manila',
                            total: 850.50,
                            sales_total: 1050.50,
                            refunds_total: 200.00,
                            has_activity: true,
                            points: [{day: 17, label: 'Oct 17', date: '2026-10-17', sales: 1050.50, refunds: 200.00, net_sales: 850.50}],
                        },
                    },
                },
                gross_profit: {
                    status: 'ongoing',
                    as_of: '2026-10-17 10:00:00',
                    timezone: 'Asia/Manila',
                    periods: {
                        today: {
                            period: 'today',
                            status: 'recorded',
                            date_range: {from: '2026-10-17', to: '2026-10-17'},
                            total_net_sales: 305.25,
                            total_net_cost: 150.00,
                            total_gross_profit: 155.25,
                            total_margin: 50.86,
                            affected_lines_count: 0,
                        },
                        week: {
                            period: 'week',
                            status: 'estimated',
                            date_range: {from: '2026-10-12', to: '2026-10-17'},
                            total_net_sales: 655.25,
                            total_net_cost: 320.00,
                            total_gross_profit: 335.25,
                            total_margin: null,
                            affected_lines_count: 0,
                        },
                        month: {
                            period: 'month',
                            status: 'incomplete',
                            date_range: {from: '2026-10-01', to: '2026-10-17'},
                            total_net_sales: 850.50,
                            total_net_cost: null,
                            total_gross_profit: null,
                            total_margin: null,
                            affected_lines_count: 3,
                        },
                    },
                },
            });

            const html = execFileSync(phpBinary, ['-r', `
                function retailmind_theme_head() {}
                function app_url($p) { return '/'.$p; }
                function theme_url($p) { return '/'.$p; }
                $displayName = 'Ada Administrator';
                $selectedRange = 30;
                $rangeUrl = '/components/administrator/dashboard.php?range=30';
                $dashboard = json_decode($argv[1], true);
                $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                $formatDate = static fn(?string $v): string => $v ? (new DateTimeImmutable($v))->format('m-d-Y g:i A') : 'Unavailable';
                $formatMetric = static fn(float|int $v, bool $c = false): string => ($c ? '₱' : '') . number_format((float)$v, $c ? 2 : 0);
                $formatCurrency = static fn(float|int $v): string => ($v < 0 ? '-₱' : '₱') . number_format(abs((float)$v), 2);
                $formatChange = static fn(?float $c): string => $c === null ? 'No prior baseline' : ($c > 0 ? '+' : '') . number_format($c, 1) . '%';
                $netSales = $dashboard['net_sales'] ?? null;
                $hasNetSales = $netSales !== null && is_array($netSales);
                $netSalesPeriods = $hasNetSales ? ($netSales['periods'] ?? ['today' => $netSales]) : [];
                $selectedPeriod = 'today';
                $activePeriodData = $netSalesPeriods[$selectedPeriod] ?? [];
                $activePeriodTitle = "Today's Net Sales";
                $activePeriodTotal = $hasNetSales ? ($activePeriodData['total'] ?? 0.0) : null;
                $activePeriodPoints = $hasNetSales ? ($activePeriodData['points'] ?? []) : [];
                $activePeriodHasActivity = $hasNetSales && ($activePeriodData['has_activity'] ?? false);
                $activePeriodIntervalLabel = 'Hour';
                $netSalesPoints = $activePeriodPoints;
                $netSalesTotal = $activePeriodTotal;
                $netSalesHasActivity = $activePeriodHasActivity;
                $netSalesAsOf = $hasNetSales ? ($netSales['as_of'] ?? null) : null;
                $headlineOrder = [];
                $comparisonOrder = [];

                $grossProfit = $dashboard['gross_profit'] ?? null;
                $hasGrossProfit = $grossProfit !== null && is_array($grossProfit);
                $grossProfitPeriods = $hasGrossProfit ? ($grossProfit['periods'] ?? []) : [];
                $activeProfitData = $grossProfitPeriods[$selectedPeriod] ?? ($grossProfitPeriods['today'] ?? []);
                $activeProfitTitle = "Today's Gross Profit";
                $activeCostTitle = "Today's Product Cost";
                $profitStatus = (string)($activeProfitData['status'] ?? 'recorded');
                $profitTotal = $activeProfitData['total_gross_profit'] ?? null;
                $costTotal = $activeProfitData['total_net_cost'] ?? null;
                $profitMargin = $activeProfitData['total_margin'] ?? null;
                $affectedLinesCount = (int)($activeProfitData['affected_lines_count'] ?? 0);
                $profitRange = $activeProfitData['date_range'] ?? [];
                $activeProfitFrom = (string)($profitRange['from'] ?? date('Y-m-d'));
                $activeProfitTo = (string)($profitRange['to'] ?? date('Y-m-d'));
                $profitReportUrl = app_url('components/report/report_generation.php') . '?report=gross_profit&from=' . urlencode($activeProfitFrom) . '&to=' . urlencode($activeProfitTo);

                eval('?>'.stream_get_contents(STDIN));
            `, layoutAPayload], {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/administrator')});

            const page = await browser.newPage();
            await page.route('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', route => route.fulfill({
                contentType: 'application/javascript',
                body: 'window.Chart = class { constructor(canvas, config) { this.id = canvas.id; this.config = config; this.data = config.data; this.options = config.options; } update() {} };'
            }));
            await page.route('http://dashboard.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                return route.fulfill(asset ? {path: path.join(root, 'src/frontend/assets', asset[1])} : {contentType: 'text/html', body: html});
            });

            await page.goto('http://dashboard.test/');

            assert.equal(await page.locator('.net-sales-summary-stat').count(), 0, 'Profit layout shows each selected total once');
            const periodControls = await page.locator('.period-controls').boundingBox();
            const summaries = await page.locator('.profit-cards-grid').boundingBox();
            assert(periodControls.y < summaries.y, 'Period controls precede the figures they affect');
            for (const width of [1440, 768, 390, 320]) {
                await page.setViewportSize({width, height: 1000});
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Profit layout fits at ${width}px`);
                assert(await page.locator('.net-sales-breakdown-col').first().isVisible(), 'Exact sales and refunds stay accessible');
                if (process.env.DASHBOARD_SCREENSHOT_DIR) {
                    fs.mkdirSync(process.env.DASHBOARD_SCREENSHOT_DIR, {recursive: true});
                    await page.screenshot({path: path.join(process.env.DASHBOARD_SCREENSHOT_DIR, `dashboard-${width}.png`), fullPage: true});
                }
            }
            await page.setViewportSize({width: 1440, height: 1000});

            // 1. Group 3 cards: Net Sales, Gross Profit, Cost of Products Sold
            assert.equal(await page.locator('.profit-cards-grid.layout-a-cards .layout-a-card').count(), 3, 'Must render exactly 3 Layout A cards');
            assert.equal(await page.locator('#net-sales-card-label').innerText(), "Today's Net Sales");
            assert.equal(await page.locator('#net-sales-card-value').innerText(), '₱305.25');

            // 2. Gross profit beside Net Sales with restrained green emphasis only on positive complete result
            assert.equal(await page.locator('#gross-profit-card-label').innerText(), "Today's Gross Profit");
            assert.equal(await page.locator('#gross-profit-status-badge').innerText(), 'Recorded');
            assert.equal(await page.locator('#gross-profit-card-value').innerText(), '₱155.25');
            assert(await page.locator('.card-gross-profit').evaluate(el => el.classList.contains('is-positive-complete')), 'Positive complete profit must have is-positive-complete');
            assert.equal(await page.locator('#profit-margin-value').innerText(), 'Margin: 50.9%');

            // 3. Cost of Products Sold card
            assert.equal(await page.locator('#cost-card-label').innerText(), "Today's Product Cost");
            assert.equal(await page.locator('#cost-status-badge').innerText(), 'Recorded');
            assert.equal(await page.locator('#cost-card-value').innerText(), '₱150.00');

            // 4. Formula banner & exclusion note
            assert(await page.locator('.profit-formula-bar').isVisible(), 'Formula bar must be visible');
            assert(await page.getByText('Rent, wages, electricity, and other operating expenses are excluded').isVisible(), 'Operating expense exclusion note visible');

            // 5. Expandable return help
            const details = page.locator('#profit-return-help');
            assert(!await details.evaluate(el => el.open), 'Return help details should be closed initially');
            await page.locator('#profit-return-help summary').click();
            assert(await details.evaluate(el => el.open), 'Clicking summary opens details');
            assert(await page.getByText('Restockable returns:').isVisible(), 'Restockable help visible when open');

            // 6. View profit report link preserving active date range
            const reportBtn = page.locator('#viewProfitReportBtn');
            assert.match(await reportBtn.getAttribute('href'), /report=gross_profit.*from=2026-10-17.*to=2026-10-17/);

            // 7. Period switching: This Week (estimated status, margin withheld, no green emphasis)
            await page.locator('.period-btn[data-period="week"]').click();
            assert.equal(await page.locator('#net-sales-card-label').innerText(), "This Week's Net Sales");
            assert.equal(await page.locator('#net-sales-card-value').innerText(), '₱655.25');
            assert.equal(await page.locator('#gross-profit-card-label').innerText(), "This Week's Gross Profit");
            assert.equal(await page.locator('#gross-profit-card-value').innerText(), '₱335.25');
            assert.equal(await page.locator('#gross-profit-status-badge').innerText(), 'Estimated');
            assert(!await page.locator('.card-gross-profit').evaluate(el => el.classList.contains('is-positive-complete')), 'Estimated profit must not have green emphasis');
            assert.equal(await page.locator('#gross-profit-card-hint').innerText(), 'Costs estimated · Margin withheld');
            assert.equal(await page.locator('#cost-card-label').innerText(), "This Week's Product Cost");
            assert.equal(await page.locator('#cost-card-value').innerText(), '₱320.00');
            assert.match(await reportBtn.getAttribute('href'), /from=2026-10-12.*to=2026-10-17/);

            // 8. Period switching: This Month (incomplete status, numeric cost/profit withheld, affected lines count, explanation visible)
            await page.locator('.period-btn[data-period="month"]').click();
            assert.equal(await page.locator('#net-sales-card-label').innerText(), "This Month's Net Sales");
            assert.equal(await page.locator('#net-sales-card-value').innerText(), '₱850.50');
            assert.equal(await page.locator('#gross-profit-card-label').innerText(), "This Month's Gross Profit");
            assert.equal(await page.locator('#gross-profit-status-badge').innerText(), 'Incomplete');
            assert.equal(await page.locator('#gross-profit-card-value').innerText(), 'Incomplete');
            assert(await page.locator('#gross-profit-card-value').evaluate(el => el.classList.contains('is-incomplete')));
            assert.equal(await page.locator('#cost-card-label').innerText(), "This Month's Product Cost");
            assert.equal(await page.locator('#cost-card-value').innerText(), 'Incomplete');
            assert.equal(await page.locator('#profit-affected-lines').innerText(), '3 affected sold lines missing cost');
            assert(!await page.locator('#profit-incomplete-explanation').evaluate(el => el.hidden), 'Incomplete explanation banner visible');
            assert.match(await reportBtn.getAttribute('href'), /from=2026-10-01.*to=2026-10-17/);

            // 9. Keyboard return to Today
            const todayBtn = page.locator('.period-btn[data-period="today"]');
            await todayBtn.focus();
            await page.keyboard.press('Enter');
            assert.equal(await page.locator('#gross-profit-card-value').innerText(), '₱155.25');
            assert(await page.locator('.card-gross-profit').evaluate(el => el.classList.contains('is-positive-complete')));

            await page.close();
        }

        console.log('Administrator dashboard browser tests: passed (populated, period switching, negative axis, zero-activity, error states, and responsive layout verified).');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exit(1);
});
