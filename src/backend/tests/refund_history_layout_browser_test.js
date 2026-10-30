const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const directory = path.join(root, 'src/frontend/components/cashier');
    const source = fs.readFileSync(path.join(directory, 'refunds.php'), 'utf8');
    const history = source.slice(source.indexOf('<section class="dashboard-section refund-history"'), source.indexOf('</main>'));
    const output = path.join(root, 'output/playwright/refund-history');
    fs.mkdirSync(output, {recursive: true});
    const browser = await chromium.launch();
    try {
        for (const state of ['empty', 'populated', 'past-end']) {
            const html = execFileSync(process.env.PHP_BINARY || 'php', ['-r', `
                require '../../../backend/app/Services/CashRefundService.php';
                class_alias('App\\Services\\CashRefundService', 'CashRefundService');
                function app_url($path) { return '/'.$path; }
                function format_display_datetime($value) { return $value; }
                $state=$argv[1]; $totalRows=$state==='empty'?0:1;
                $page=$state==='past-end'?2:1; $pageSize=10; $totalPages=1; $hasNext=false;
                $paginationFilters=[]; $url=fn($params)=>'?'.http_build_query($params).'#refund-history';
                $refunds=$state==='populated'?[['refund_id'=>42,'sale_id'=>21,'shift_id'=>7,
                    'created_at'=>'Oct 30, 2026 10:00 AM','reason'=>'other','note'=>'Customer return',
                    'payment_method'=>'cash','refund_amount'=>150.00]]:[];
                eval('?>'.stream_get_contents(STDIN));
            `, state], {input: history, encoding: 'utf8', cwd: directory});
            const page = await browser.newPage();
            await page.route('http://refunds.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                return route.fulfill(asset ? {path: path.join(root, 'src/frontend/assets', asset[1])} : {
                    contentType: 'text/html', body: `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                    <link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/theme.css">
                    <link rel="stylesheet" href="/assets/css/cashier-pages.css"><link rel="stylesheet" href="/assets/css/refunds.css">
                    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css"></head>
                    <body class="cashier-refunds-page"><main class="main-content"><h1>Refunds</h1>
                    <section class="dashboard-section refund-search"><h2>Find a sale to refund</h2>
                    <label for="sale_id">Receipt number</label><input id="sale_id" type="number" min="1"></section>${html}</main></body></html>`
                });
            });
            await page.goto('http://refunds.test/');
            assert.equal(await page.locator('.history-pagination').count(), state === 'empty' ? 0 : 1);
            if (state === 'empty') {
                assert(await page.getByRole('heading', {name: 'No refunds yet'}).isVisible());
                assert.equal(await page.locator('.refund-history-heading span').count(), 0);
                await page.getByRole('link', {name: 'Find a sale to refund'}).click();
                assert(await page.locator('#sale_id').evaluate(el => el === document.activeElement), 'Empty-state link leads keyboard users to the receipt field');
            } else if (state === 'past-end') {
                assert(await page.getByRole('heading', {name: 'No refunds on this page'}).isVisible());
                assert.equal(await page.getByRole('link', {name: 'View first page'}).getAttribute('href'), '?page=1#refund-history');
            } else {
                assert.equal(await page.locator('.refund-empty').count(), 0);
                assert.match(await page.getByRole('link', {name: 'Preview Refund Receipt #42'}).getAttribute('href'), /refund_id=42/);
            }
            for (const theme of ['light', 'dark']) {
                await page.locator('html').evaluate((el, theme) => el.dataset.theme = theme, theme);
                for (const width of [1440, 390, 320]) {
                    await page.setViewportSize({width, height: 900});
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${state} fits ${theme} at ${width}`);
                    if (state !== 'populated') assert(await page.locator('.refund-empty').evaluate(el => el.scrollWidth <= el.clientWidth));
                    if (width !== 320) await page.screenshot({path: path.join(output, `${state}-${theme}-${width}.png`), fullPage: true});
                }
            }
            await page.close();
        }
    } finally { await browser.close(); }
    console.log('Refund history layout: passed (empty, populated, past-end; light/dark; 1440/390/320).');
})().catch(error => {console.error(error); process.exit(1);});
