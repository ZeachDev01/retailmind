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
        // Test 1: Populated state with positive, zero, and refund-only negative hours
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
                $netSalesPoints = $hasNetSales ? ($netSales['points'] ?? []) : [];
                $netSalesTotal = $hasNetSales ? ($netSales['total'] ?? 0.0) : null;
                $netSalesHasActivity = $hasNetSales && ($netSales['has_activity'] ?? false);
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
                eval('?>'.stream_get_contents(STDIN));
            `, testPayload], {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/administrator')});

            const page = await browser.newPage();
            await page.route('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', route => route.fulfill({
                contentType: 'application/javascript',
                body: 'window.Chart = class { constructor(canvas, config) { this.id = canvas.id; this.config = config; } };'
            }));
            await page.route('http://dashboard.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                return route.fulfill(asset ? {path: path.join(root, 'src/frontend/assets', asset[1])} : {contentType: 'text/html', body: html});
            });

            await page.goto('http://dashboard.test/');

            // Verify Net Sales heading and ongoing badge
            assert(await page.locator('#net-sales-title').isVisible(), 'Net sales title must be visible');
            assert.match(await page.locator('.period-badge.ongoing').innerText(), /ongoing/i);

            // Verify displayed total: ₱305.25
            assert.equal(await page.locator('.net-sales-summary-value').innerText(), '₱305.25');

            // Verify Chart.js canvas and config
            assert.equal(await page.locator('#net-sales-hourly-chart').count(), 1);
            const chart = await page.evaluate(() => window.netSalesChart);
            assert.ok(chart, 'netSalesChart must be initialized');
            assert.equal(chart.id, 'net-sales-hourly-chart');
            assert.equal(chart.config.type, 'bar');
            assert.equal(chart.config.data.labels.length, 11);
            assert.deepEqual(chart.config.data.labels, ['00:00','01:00','02:00','03:00','04:00','05:00','06:00','07:00','08:00','09:00','10:00']);
            assert.deepEqual(chart.config.data.datasets[0].data, [50, 0, 0, 0, 0, 0, 0, 0, 195.25, -40, 100]);
            assert.equal(chart.config.options.scales.y.beginAtZero, true, 'Y scale must begin at zero to support negative values');

            // Negative-axis color rendering: negative value (-40) rendered in negativeColor (#ef4444)
            const colors = chart.config.data.datasets[0].backgroundColor;
            assert.equal(colors[9], '#ef4444', 'Hour 9 negative net sales must use negative color');

            // Accessible table checks: exact values without hover or color dependence
            const rows = page.locator('.net-sales-table tbody tr');
            assert.equal(await rows.count(), 11, 'Table must have 11 hourly rows');
            // Check hour 8
            assert.equal(await rows.nth(8).locator('td[data-label="Hour"]').innerText(), '08:00');
            assert.equal(await rows.nth(8).locator('td[data-label="Net Sales"]').innerText(), '₱195.25');
            // Check hour 9 (negative)
            assert.equal(await rows.nth(9).locator('td[data-label="Hour"]').innerText(), '09:00');
            assert.equal(await rows.nth(9).locator('td[data-label="Net Sales"]').innerText(), '-₱40.00');
            assert(await rows.nth(9).locator('td[data-label="Net Sales"]').evaluate(el => el.classList.contains('text-negative')));

            // Responsive layout test: Desktop, tablet, mobile
            for (const width of [1200, 1024, 768, 640, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Dashboard fits at ${width}px without horizontal overflow`);
                assert(await page.locator('.net-sales-section').evaluate(el => el.scrollWidth <= el.clientWidth), `Net sales section fits at ${width}px`);
                assert(await page.locator('.net-sales-table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth), `Accessible table fits at ${width}px`);
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
                $netSalesPoints = $hasNetSales ? ($netSales['points'] ?? []) : [];
                $netSalesTotal = $hasNetSales ? ($netSales['total'] ?? 0.0) : null;
                $netSalesHasActivity = $hasNetSales && ($netSales['has_activity'] ?? false);
                $netSalesAsOf = $hasNetSales ? ($netSales['as_of'] ?? null) : null;
                $headlineOrder = [];
                $comparisonOrder = [];
                eval('?>'.stream_get_contents(STDIN));
            `, zeroPayload], {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/administrator')});

            const page = await browser.newPage();
            await page.route('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', route => route.fulfill({
                contentType: 'application/javascript',
                body: 'window.Chart = class { constructor(canvas, config) { this.id = canvas.id; this.config = config; } };'
            }));
            await page.route('http://dashboard.test/**', route => route.fulfill({contentType: 'text/html', body: html}));

            await page.goto('http://dashboard.test/');
            assert.equal(await page.locator('.net-sales-summary-value').innerText(), '₱0.00');
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
                $netSalesPoints = $hasNetSales ? ($netSales['points'] ?? []) : [];
                $netSalesTotal = $hasNetSales ? ($netSales['total'] ?? 0.0) : null;
                $netSalesHasActivity = $hasNetSales && ($netSales['has_activity'] ?? false);
                $netSalesAsOf = $hasNetSales ? ($netSales['as_of'] ?? null) : null;
                $headlineOrder = [];
                $comparisonOrder = [];
                eval('?>'.stream_get_contents(STDIN));
            `, errorPayload], {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/administrator')});

            const page = await browser.newPage();
            await page.route('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', route => route.fulfill({
                contentType: 'application/javascript',
                body: 'window.Chart = class { constructor(canvas, config) { this.id = canvas.id; this.config = config; } };'
            }));
            await page.route('http://dashboard.test/**', route => route.fulfill({contentType: 'text/html', body: html}));

            await page.goto('http://dashboard.test/');
            const totalText = await page.locator('.net-sales-summary-value').innerText();
            assert.equal(totalText, 'Unavailable', 'Error state must display Unavailable, never 0.00');
            assert.notEqual(totalText, '₱0.00', 'Unavailable total must NEVER display as zero');
            assert(await page.locator('.net-sales-section .dashboard-state-error strong').isVisible(), 'Error banner must be visible');
            assert.equal(await page.locator('#net-sales-hourly-chart').count(), 0, 'Chart must not render in error state');
            await page.close();
        }

        console.log('Administrator dashboard browser tests: passed (populated, negative axis, zero-activity, error states, and responsive layout verified).');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exit(1);
});
