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
        $type='sales'; $category=''; $page=2; $recordId=null; $dateError=''; $hasNext=true;
        $pageSize=10; $totalRows=35; $totalPages=4;
        $fromDay='2026-10-01'; $throughDay='2026-10-16'; $idColumn='sale_id';
        $columns=['sale_id'=>'Sale #','sale_date'=>'Date','total_amount'=>'Total','payment_method'=>'Payment','note'=>'Note'];
        $rows=[['sale_id'=>42,'sale_date'=>'Oct 16, 2026 08:00 AM','total_amount'=>'100.00','payment_method'=>'Cash','note'=>str_repeat('Long saved note ',20)]];
        $display=fn($column,$value)=>htmlspecialchars((string)$value);
        $renderPayment=fn($value)=>'<span>'.htmlspecialchars((string)$value).'</span>';
        $url=fn($params)=>app_url('components/cashier/history.php?'.http_build_query($params+['type'=>$type,'date_from'=>$fromDay,'date_to'=>$throughDay,'category'=>$category]));
        eval('?><?php use App\\Services\\CashierOperationalHistoryService; ?>'.stream_get_contents(STDIN));
    `;
    const php = process.env.PHP_BINARY || (fs.existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
    let html = execFileSync(php, ['-r', setup], {
        input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/cashier')
    });
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        await page.route('http://history.test/**', route => {
            const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
            if (asset) return route.fulfill({path: path.join(root, 'src/frontend/assets', asset[1])});
            return route.fulfill({contentType: 'text/html', body: html.replace('</body>', '<script src="/assets/js/ui.js"></script></body>')});
        });
        await page.goto('http://history.test/');
        assert.equal(await page.locator('.auto-table-pagination').count(), 0, 'No redundant auto smart table pagination on history');
        assert.equal(await page.locator('.history-pagination').count(), 1, 'Exactly one pagination bar on history');
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
            assert(await page.locator('.history-id-cell').filter({hasText: '#42'}).isVisible());
            assert.equal(await page.getByRole('link', {name: '#42'}).count(), 0, 'Sales ID is non-functional and not a link');
            assert(await page.getByRole('button', {name: 'Details'}).first().isVisible(), 'Details button is the functioning control');
            assert(await page.locator('.history-pagination').evaluate(el => el.scrollWidth <= el.clientWidth), 'Pagination fits at ' + width);
        }
        for (const [type, category, labels] of [
            ['refunds', 'full', ['All categories', 'Partially refunded', 'Fully refunded']],
            ['movements', 'cash_out', ['All categories', 'Cash in', 'Cash out', 'Safe drop']]
        ]) {
            const categorySetup = setup.replace("$type='sales'; $category=''", `$type='${type}'; $category='${category}'`)
                .replace("eval('?>", `
                    $columns=App\\Services\\CashierOperationalHistoryService::types()[$type]['columns'];
                    $idColumn=App\\Services\\CashierOperationalHistoryService::types()[$type]['id'];
                    $rows=[array_fill_keys(array_keys($columns), '')];
                    $rows[0]=array_replace($rows[0], [$idColumn=>42, 'sale_id'=>42, 'created_at'=>'2026-10-16 08:00',
                        'refund_amount'=>100, 'refund_status'=>'full', 'movement_type'=>'cash_out', 'amount'=>100, 'payment_method'=>'cash']);
                    $display=fn($column,$value)=>htmlspecialchars((string)(in_array($column,['refund_status','movement_type'],true)
                        ? (App\\Services\\CashierOperationalHistoryService::categoryOptions($type)[$value]??$value) : $value));
                    eval('?>`);
            html = execFileSync(php, ['-r', categorySetup], {
                input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/cashier')
            });
            await page.goto('http://history.test/?type=' + type);
            assert.deepEqual(await page.getByLabel('Category', {exact: true}).locator('option').allTextContents(), labels);
            assert.equal(await page.getByLabel('Category', {exact: true}).inputValue(), category);
            assert(await page.locator('td').getByText(labels[2], {exact: true}).isVisible());
            const next = new URL(await page.getByRole('link', {name: 'Next', exact: true}).getAttribute('href'), page.url());
            assert.equal(next.searchParams.get('category'), category, 'Pagination preserves category');
            assert.equal(next.searchParams.get('date_from'), '2026-10-01');
            assert.equal(await page.locator('.history-page-length input[name="category"]').inputValue(), category);
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                const bounds = await page.locator('.history-filters').evaluate(el => {
                    const select = el.querySelector('select').getBoundingClientRect();
                    return {right: select.right, width: document.documentElement.scrollWidth, viewport: innerWidth};
                });
                assert(bounds.width <= width && bounds.right <= width, type + ' filters fit at ' + width);
            }
            if (type === 'movements') {
                await page.screenshot({path: path.join(require('node:os').tmpdir(), 'retailmind-history-categories-mobile.png'), fullPage: true});
            }
            const submitted = page.waitForRequest(request => new URL(request.url()).searchParams.get('category') === category);
            await page.getByRole('button', {name: 'Apply filters', exact: true}).click();
            const query = new URL((await submitted).url()).searchParams;
            assert.equal(query.get('type'), type);
            assert.equal(query.get('date_from'), '2026-10-01');
            assert.equal(query.get('date_to'), '2026-10-16');
            html = execFileSync(php, ['-r', categorySetup.replace("eval('?><?php use", "$rows=[]; eval('?><?php use")], {
                input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/cashier')
            });
            await page.goto('http://history.test/?empty=1');
            assert(await page.getByText('No records match the selected dates and category.', {exact: false}).isVisible());
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
        await page.locator('.history-page-length button[type="submit"]').click();
        assert.match((await request).url(), /sale_id=42&length=25/);
        console.log('Transaction History: responsive category filters, empty states, private record labels and filter-preserving pagination passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
