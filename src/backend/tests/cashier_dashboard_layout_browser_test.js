const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const source = fs.readFileSync(path.join(root, 'src/frontend/components/cashier/dashboard.php'), 'utf8');
    const template = source.slice(source.indexOf('<!DOCTYPE html>')).replace(/<\?php include __DIR__ .*?\?>/, '');
    const browser = await chromium.launch();
    try {
        for (const empty of [false, true]) {
            const html = execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['-r', `
                function retailmind_theme_head() {}
                function app_url($path) { return '/'.$path; }
                function format_display_datetime($value) { return $value; }
                $cashierMetrics=['today_sales'=>1234567.89,'transactions'=>1000,'average_transaction'=>1234.56];
                $recentTransactions=$argv[1]?[]:[['sale_id'=>42,'total_amount'=>1234567.89,'item_count'=>12,'payment_method'=>'cash','sale_date'=>'Oct 16, 2026 08:00 AM']];
                $lowStockWarnings=$argv[1]?[]:[['product_name'=>str_repeat('Long product name ',12),'sku'=>str_repeat('SKU',30),'reorder_level'=>10,'quantity_on_hand'=>2]];
                eval('?>'.stream_get_contents(STDIN));
            `, empty ? '1' : '0'], {input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/cashier')});
            const page = await browser.newPage();
            await page.route('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', route => route.fulfill({
                contentType: 'application/javascript',
                body: 'window.Chart = class { constructor(canvas, config) { window.salesChart = {id: canvas.id, config}; } };'
            }));
            await page.route('http://dashboard.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                return route.fulfill(asset ? {path: path.join(root, 'src/frontend/assets', asset[1])} : {contentType: 'text/html', body: html});
            });
            await page.goto('http://dashboard.test/');
            assert.equal(await page.locator('#cashier-sales-chart').count(), empty ? 0 : 1);
            if (!empty) {
                const chart = await page.evaluate(() => window.salesChart);
                assert.equal(chart.id, 'cashier-sales-chart');
                assert.equal(chart.config.type, 'bar');
                assert.deepEqual(chart.config.data.labels, ['#42']);
                assert.deepEqual(chart.config.data.datasets[0].data, [1234567.89]);
                assert.equal(chart.config.options.scales.y.beginAtZero, true);
            }
            assert.equal(await page.locator('.cashier-action-card').count(), 3);
            assert.equal(await page.locator('.cashier-stats article').count(), 4);
            for (const width of [1600, 1440, 1200, 1024, 768, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Dashboard fits at ${width}`);
                assert(await page.locator('.table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth), `Transactions fit at ${width}`);
                for (const selector of ['.cashier-stats', '.cashier-quick-actions', '.cashier-dashboard-grid']) {
                    assert(await page.locator(selector).evaluate(el => el.scrollWidth <= el.clientWidth), `${selector} fits at ${width}`);
                }
            }
            if (empty) assert(await page.getByText('No transactions recorded today.').isVisible());
            else assert.match(await page.getByRole('link', {name: 'View receipt'}).getAttribute('href'), /sale_id=42/);
            await page.close();
        }
        console.log('Cashier dashboard: populated/empty states, cards, links, and grids fit at seven screen widths.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
