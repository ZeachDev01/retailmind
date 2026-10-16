const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const source = fs.readFileSync(path.join(root, 'src/frontend/components/cashier/history.php'), 'utf8');
    const template = source.slice(source.indexOf('<!DOCTYPE html>')).replace(/<\?php include __DIR__ .*?\?>/, '');
    const setup = `
        require_once '../../../backend/app/Services/CashierOperationalHistoryService.php';
        function retailmind_theme_head() {}
        function app_url($path) { return '/'.$path; }
        $tabs=['sales'=>'Sales','refunds'=>'Cash Refunds','movements'=>'Drawer movements','shifts'=>'Shifts'];
        $type='sales'; $page=2; $recordId=null; $dateError=''; $hasNext=true;
        $pageSize=10; $totalRows=35; $totalPages=4;
        $fromDay='2026-10-01'; $throughDay='2026-10-16'; $idColumn='sale_id';
        $columns=['sale_id'=>'Sale #','sale_date'=>'Date','total_amount'=>'Total','payment_method'=>'Payment','note'=>'Note'];
        $rows=[['sale_id'=>42,'sale_date'=>'Oct 16, 2026 08:00 AM','total_amount'=>'100.00','payment_method'=>'Cash','note'=>str_repeat('Long saved note ',20)]];
        $display=fn($column,$value)=>htmlspecialchars((string)$value);
        $url=fn($params)=>app_url('components/cashier/history.php?'.http_build_query($params+['date_from'=>$fromDay,'date_to'=>$throughDay]));
        eval('?><?php use App\\Services\\CashierOperationalHistoryService; ?>'.stream_get_contents(STDIN));
    `;
    const php = process.env.PHP_BINARY || (fs.existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
    const html = execFileSync(php, ['-r', setup], {
        input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/cashier')
    });
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        await page.route('http://history.test/**', route => {
            const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
            if (asset) return route.fulfill({path: path.join(root, 'src/frontend/assets', asset[1])});
            return route.fulfill({contentType: 'text/html', body: html});
        });
        await page.goto('http://history.test/');
        assert.equal(await page.locator('.history-tab[aria-current="page"]').innerText(), 'Sales');
        assert.match(await page.getByRole('link', {name: 'Next', exact: true}).getAttribute('href'), /page=3.*date_from=2026-10-01/);
        assert.equal(await page.getByLabel('From date').inputValue(), '2026-10-01');
        assert.equal(await page.getByLabel('To date').inputValue(), '2026-10-16');
        assert.equal(await page.locator('td[data-label]').count(), 5);
        assert.equal(await page.getByRole('combobox', {name: 'Entries per page'}).inputValue(), '10');
        assert.equal(await page.getByRole('link', {name: 'Page 2', exact: true}).getAttribute('aria-current'), 'page');
        assert.match(await page.getByRole('link', {name: 'Last page'}).getAttribute('href'), /page=4/);
        for (const width of [1440, 1024, 768, 390, 320]) {
            await page.setViewportSize({width, height: 900});
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Page fits at ' + width);
            assert(await page.locator('.history-table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth), 'Records fit at ' + width);
            assert(await page.getByRole('link', {name: '#42', exact: true}).isVisible());
            assert(await page.locator('.history-pagination').evaluate(el => el.scrollWidth <= el.clientWidth), 'Pagination fits at ' + width);
        }
        const refundSetup = setup.replace("eval('?>", `
            $paginationFilters=['sale_id'=>42];
            $url=fn($params)=>app_url('components/cashier/refunds.php?'.http_build_query($params+$paginationFilters+['length'=>$pageSize])).'#refund-history';
            eval('?>`);
        const refundFooter = execFileSync(php, ['-r', refundSetup], {
            input: fs.readFileSync(path.join(root, 'src/backend/app/Views/table_pagination.php'), 'utf8'),
            encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/cashier')
        });
        const css = ['style.css', 'cashier-pages.css'].map(file => fs.readFileSync(path.join(root, 'src/frontend/assets/css', file), 'utf8')).join('\n');
        await page.goto('http://history.test/components/cashier/refunds.php');
        await page.setContent('<style>' + css + '</style><body class="cashier-refunds-page">' + refundFooter + '</body>');
        for (const width of [1440, 390, 320]) {
            await page.setViewportSize({width, height: 900});
            assert(await page.locator('.history-pagination').evaluate(el => el.scrollWidth <= el.clientWidth), 'Refund pagination fits at ' + width);
        }
        assert.match(await page.getByRole('link', {name: 'Next', exact: true}).getAttribute('href'), /page=3&sale_id=42&length=10#refund-history/);
        const request = page.waitForRequest(url => url.url().includes('length=25'));
        await page.getByRole('combobox', {name: 'Entries per page'}).selectOption('25');
        assert.match((await request).url(), /sale_id=42&length=25/);
        console.log('Transaction History: desktop/mobile layout, field labels, active navigation, and date-preserving pagination passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
