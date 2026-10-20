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
            const html = execFileSync(process.env.PHP_BINARY || 'php', ['-r', `
                function retailmind_theme_head() {}
                function app_url($path) { return '/'.$path; }
                function format_display_datetime($value) { return $value; }
                $cashierMetrics=['today_sales'=>1234567.89,'transactions'=>1000,'average_transaction'=>1234.56];
                $cashierMetrics['hourly_sales']=array_fill(0,24,0);
                if (!$argv[1]) $cashierMetrics['hourly_sales'][8]=1234567.89;
                else $cashierMetrics['transactions']=0;
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
            assert.equal(await page.locator('#cashier-sales-chart').count(), 1);
            if (!empty) {
                const chart = await page.evaluate(() => window.salesChart);
                assert.equal(chart.id, 'cashier-sales-chart');
                assert.equal(chart.config.type, 'bar');
                assert.deepEqual(chart.config.data.labels, Array.from({length: 24}, (_, hour) => String(hour).padStart(2, '0') + ':00'));
                assert.equal(chart.config.data.datasets[0].data[8], 1234567.89);
                assert.equal(chart.config.options.scales.y.beginAtZero, true);
            }
            assert.equal(await page.locator('.cashier-action-card').count(), 3);
            assert.equal(await page.locator('.cashier-stats article').count(), 4);
            assert.equal(await page.locator('.cashier-action-card strong').first().evaluate(el => getComputedStyle(el).fontSize), '16px');
            assert.equal(await page.locator('.cashier-stats .hint').first().evaluate(el => getComputedStyle(el).fontSize), '14px');
            assert.equal(await page.locator('.dashboard-panel-header h2').first().evaluate(el => getComputedStyle(el).fontSize), '20px');
            assert.match(await page.locator('.gcash-card-value').first().evaluate(el => getComputedStyle(el).fontVariantNumeric), /tabular-nums/);
            const screenshots = path.join(root, 'output/playwright/cashier-dashboard-typeset');
            fs.mkdirSync(screenshots, {recursive: true});
            for (const width of [1600, 1440, 1200, 1024, 768, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Dashboard fits at ${width}`);
                assert(await page.locator('.table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth), `Transactions fit at ${width}`);
                for (const selector of ['.cashier-stats', '.cashier-quick-actions', '.cashier-dashboard-grid']) {
                    assert(await page.locator(selector).evaluate(el => el.scrollWidth <= el.clientWidth), `${selector} fits at ${width}`);
                }
                if ([1440, 390].includes(width)) await page.screenshot({path: path.join(screenshots, `cashier-${empty ? 'empty' : 'populated'}-${width}.png`), fullPage: true});
            }
            if (empty) assert(await page.getByText('No transactions recorded.', {exact: true}).isVisible());
            else assert.match(await page.getByRole('link', {name: 'View receipt'}).getAttribute('href'), /sale_id=42/);
            await page.getByRole('tab', {name: 'GCash', exact: true}).click();
            assert(await page.locator('#panel-gcash').isVisible());
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert(await page.locator('#panel-gcash').evaluate(el => el.scrollWidth <= el.clientWidth), `GCash fits at ${width}`);
                if (!empty && width !== 320) await page.screenshot({path: path.join(screenshots, `gcash-${width}.png`), fullPage: true});
            }
            await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
            assert(await page.locator('#panel-gcash').evaluate(el => el.scrollWidth <= el.clientWidth), 'GCash fits at 200% text size: ' + await page.locator('#panel-gcash').evaluate(el => JSON.stringify([...el.querySelectorAll('*')].filter(item => item.scrollWidth > item.clientWidth).map(item => item.className))));
            await page.getByRole('tab', {name: 'Cashier', exact: true}).click();
            for (const selector of ['.cashier-stats', '.cashier-quick-actions', '.cashier-dashboard-grid']) {
                assert(await page.locator(selector).evaluate(el => el.scrollWidth <= el.clientWidth), `${selector} fits at 200% text size`);
            }
            await page.close();
        }
        console.log('Cashier dashboard: populated/empty states, cards, links, and grids fit at seven screen widths.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
