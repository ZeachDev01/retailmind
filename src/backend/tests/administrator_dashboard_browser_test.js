const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../../..');
const payload = {
    state: 'ready', headline: {
        sales_cash_exceptions: {value: 2, status: 'warning'},
        inventory_risks: {value: 1, status: 'warning'},
        fiscal_period: {value: 'Current Store accounting period', status: 'healthy'},
    }, exceptions: [], comparison: {
        transactions: {current: 3, previous: 2, change_percent: 50},
        unusual_discounts: {current: 1, previous: 0, change_percent: null},
        reversals: {current: 1, previous: 0, change_percent: null},
    }, freshness: {generated_at: '2026-10-23 10:00:00', stale_after: '2099-10-23 10:05:00'},
    net_sales: {as_of: '2026-10-23 10:00:00', periods: {}}, gross_profit: {periods: {}},
    financial_history: {
        period_start: '2026-09-24', period_end: '2026-10-23', previous_start: '2026-08-25', previous_end: '2026-09-23',
        as_of: '2026-10-23 10:00:00', points: [{date: '2026-09-24', label: 'Sep 24', net_sales: 80, gross_profit: null, net_cost: null, status: 'incomplete'}, {date: '2026-09-25', label: 'Sep 25', net_sales: -20, gross_profit: -30, net_cost: 10, status: 'estimated'}],
        net_sales: {current: 60, previous: 0, change_percent: null},
        gross_profit: {current: null, previous: 20, change_percent: null, status: 'incomplete', previous_status: 'recorded'},
    },
};
for (const [i, period] of ['today', 'week', 'month'].entries()) {
    const label = period === 'today' ? '10:00' : 'Oct 23';
    payload.net_sales.periods[period] = {total: 80 + i, total_gross_sales: 100 + i, total_discounts: 10, refunds_total: 10, has_activity: true, period_start: ['2026-10-23','2026-10-19','2026-10-01'][i], period_end: '2026-10-23', points: [{label, date: '2026-10-23', sales: 90 + i, gross_sales: 100 + i, discounts: 10, refunds: 10, net_sales: 80 + i}]};
    payload.gross_profit.periods[period] = {total_net_cost: 50, total_gross_profit: 30 + i, total_margin: 37.5, status: 'recorded', date_range: {from: ['2026-10-23','2026-10-19','2026-10-01'][i], to: '2026-10-23'}, points: [{label, net_cost: 50, gross_profit: 30 + i, status: 'recorded'}]};
}
function render(period, data = payload) {
    return execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'render_administrator_dashboard.php'), period], {input: JSON.stringify(data), encoding: 'utf8'});
}
(async () => {
    assert.match(render('today'), /id="gross-sales-card-value"/);
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}, hasTouch: true, reducedMotion: 'reduce'});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const chartScript = process.env.CHART_JS_PATH
            ? fs.readFileSync(process.env.CHART_JS_PATH, 'utf8') + `;const RealChart = window.Chart; window.Chart = class extends RealChart { constructor(canvas, config) { super(canvas, config); (window.testCharts ||= {})[canvas.id] = this; } };`
            : 'window.Chart = class { constructor(canvas, config) { this.data = config.data; this.options = config.options; this.config = config; (window.testCharts ||= {})[canvas.id] = this; } };';
        await page.route('https://cdn.jsdelivr.net/**', route => route.fulfill({contentType: 'application/javascript', body: chartScript}));
        await page.route('http://dashboard.test/**', route => {
            const url = new URL(route.request().url());
            const asset = url.pathname.match(/^\/assets\/(.*)/);
            return route.fulfill(asset ? {path: path.join(root, 'src/frontend/assets', asset[1])} : {contentType: 'text/html', body: render(url.searchParams.get('period') || 'today')});
        });
        await page.goto('http://dashboard.test/');
        assert.equal(await page.locator('#gross-sales-card-value').innerText(), '₱100.00');
        assert.match(await page.getByRole('link', {name: 'View Stock Issues', exact: true}).getAttribute('href'), /administrator\/stock_issues\.php$/);
        assert.match(await page.getByRole('link', {name: 'View sales exceptions', exact: true}).getAttribute('href'), /sales\.php\?tab=reversals$/);
        assert.match(await page.getByRole('link', {name: 'View cash variances', exact: true}).getAttribute('href'), /administrator\/shift_report\.php$/);
        assert.match(await page.getByRole('link', {name: 'View Fiscal Periods', exact: true}).getAttribute('href'), /fiscal_periods\.php$/);
        assert.equal(await page.locator('#financial-breakdown').getAttribute('open'), null);
        assert.deepEqual(await page.evaluate(() => window.testCharts['net-sales-hourly-chart'].data.labels), ['10:00']);
        const history = await page.locator('#financial-history').innerText();
        for (const [i, period] of ['today','week','month'].entries()) {
            await page.getByRole('button', {name: ['Today','This Week','This Month'][i], exact: true}).click();
            await page.waitForURL(`**period=${period}`);
            assert.equal(await page.locator('#gross-sales-card-value').innerText(), `₱${100 + i}.00`);
            assert.equal(await page.locator('#net-sales-card-value').innerText(), `₱${80 + i}.00`);
            assert.equal(await page.locator('#gross-profit-card-value').innerText(), `₱${30 + i}.00`);
            assert.match(await page.locator('#financial-period-dates').textContent(), new RegExp(['2026-10-23','2026-10-19','2026-10-01'][i]));
            assert.match(await page.locator('#viewProfitReportBtn').getAttribute('href'), new RegExp(`from=${['2026-10-23','2026-10-19','2026-10-01'][i]}`));
            assert.equal(await page.locator('#financial-history').innerText(), history);
            assert.equal(await page.locator('.store-attention-queue .section-description').count(), 0);
            const charts = await page.evaluate(() => Object.values(window.testCharts).map(chart => chart.data.datasets.map(series => series.label)));
            assert.deepEqual(charts, [['Gross Sales','Net Sales'], ['Gross Profit'], ['Net Sales','Gross Profit']]);
        }
        assert.match(await page.locator('#financial-history').innerText(), /Incomplete/);
        await page.locator('#financial-breakdown > summary').focus();
        await page.keyboard.press('Enter');
        assert(await page.locator('#financial-breakdown table').isVisible());
        assert.match(await page.locator('#financial-breakdown table').innerText(), /Discounts/);
        assert.match(await page.locator('#financial-history').innerText(), /No comparable percentage/);
        assert.deepEqual(await page.evaluate(() => window.testCharts['financial-history-chart'].data.datasets[1].data), [null, -30]);
        assert.equal(await page.evaluate(() => window.testCharts['financial-history-chart'].options.spanGaps), false);
        fs.mkdirSync(path.join(root, 'output/playwright/dashboard-274'), {recursive: true});
        await page.locator('#financial-breakdown > summary').click();
        await page.screenshot({path: path.join(root, 'output/playwright/dashboard-274/desktop.png'), fullPage: true});
        await page.setViewportSize({width: 390, height: 844});
        await page.screenshot({path: path.join(root, 'output/playwright/dashboard-274/mobile.png'), fullPage: true});
        if (process.env.CHART_JS_PATH) {
            const chart = page.locator('#net-sales-hourly-chart');
            await chart.scrollIntoViewIfNeeded();
            const target = await page.evaluate(() => {const point = window.testCharts['net-sales-hourly-chart'].getDatasetMeta(0).data[0]; return {x: point.x, y: point.y + 5};});
            const box = await chart.boundingBox();
            await page.touchscreen.tap(box.x + target.x, box.y + target.y);
            await page.waitForFunction(() => window.testCharts['net-sales-hourly-chart'].tooltip.opacity > 0);
            assert.match(await page.evaluate(() => window.testCharts['net-sales-hourly-chart'].tooltip.body.map(item => item.lines.join(' ')).join(' ')), /Gross Sales:.*102.00/);
        }
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No page-level horizontal overflow');
        await page.locator('#financial-breakdown > summary').tap({force: true}).catch(() => page.locator('#financial-breakdown > summary').click());
        assert(await page.locator('#financial-breakdown table').isVisible());
        await page.route('https://cdn.jsdelivr.net/**', route => route.abort());
        await page.reload();
        assert(await page.getByText('Charts unavailable. Exact values remain in the detailed breakdowns.').isVisible());
        await page.getByRole('button', {name: 'Today', exact: true}).click();
        assert.equal(await page.locator('#gross-sales-card-value').innerText(), '₱100.00');
        await page.locator('#financial-breakdown > summary').click();
        assert(await page.locator('#financial-breakdown table').isVisible());
        assert.deepEqual(errors, []);
        const incomplete = structuredClone(payload);
        incomplete.gross_profit.periods.today.status = 'incomplete';
        incomplete.gross_profit.periods.today.total_gross_profit = null;
        incomplete.gross_profit.periods.today.total_net_cost = null;
        incomplete.gross_profit.periods.today.affected_lines_count = 1;
        assert.match(render('today', incomplete), /1 affected sold line missing cost/);
        assert.match(render('today', incomplete), /id="gross-profit-card-value">Incomplete</);
        const estimated = structuredClone(payload);
        estimated.gross_profit.periods.today.status = 'estimated';
        estimated.gross_profit.periods.today.total_margin = null;
        assert.match(render('today', estimated), /Costs estimated/);
        const unavailable = structuredClone(payload);
        unavailable.net_sales = null;
        unavailable.gross_profit = null;
        unavailable.financial_history = null;
        assert.match(render('today', unavailable), /id="gross-sales-card-value">Unavailable</);
        assert.match(render('today', unavailable), /Product costs and Gross Profit could not be loaded/);
        await page.close();
        console.log('Administrator dashboard browser tests: passed');
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exit(1);});
