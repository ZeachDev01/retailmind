const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const root = path.resolve(__dirname, '../../..');
function render(variant) {
    const source = fs.readFileSync(path.join(root, 'src/frontend/components/administrator/shift_report.php'), 'utf8');
    // Exercise production presentation and summary calculations without writing Store data.
    const template = source.slice(source.indexOf('function shift_report_e('))
        .replace(/<\?php include __DIR__ .*?\?>/, '');
    return execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['-r', `
        function retailmind_theme_head() {}
        function app_url($path) { return '/'.$path; }
        function theme_url($path) { return '/'.$path; }
        function csrf_field() { return '<input type="hidden" name="csrf_token" value="fixture">'; }
        $defaultFrom='2026-10-01'; $defaultTo='2026-10-31'; $error=''; $success='';
        $filters=['from'=>$defaultFrom,'to'=>$defaultTo,'cashier_id'=>'','register_id'=>'','shift_id'=>''];
        $cashiers=[['user_id'=>1,'full_name'=>'Alex Rivera']];
        $registers=[['register_id'=>1,'name'=>'Front register']];
        $shifts=[['shift_id'=>42,'opened_at'=>'2026-10-31 08:00:00']];
        $row=array_fill_keys(['Record','Date','Cashier','Register','Status','Opened At','Closed At','Closed By',
            'Intervention Reason','Closing Notes','Variance Explanation'], '');
        foreach (['Sale Count','Cash Sales','Card Sales','E-Wallet Sales','Total Sales','Cash Refunds',
            'Card Refunds','E-Wallet Refunds','Total Refunds','Cash In','Cash Out','Safe Drops','Opening Cash',
            'Expected Cash','Counted Cash','Variance'] as $key) $row[$key]='0.00';
        $row=array_merge($row, ['Shift ID'=>42,'Cashier ID'=>1,'Register ID'=>1,'Date'=>'2026-10-31',
            'Cashier'=>'Alex Rivera','Register'=>'Front register','Status'=>'closed','Sale Count'=>18,
            'Total Sales'=>'8250.00','Cash Sales'=>'6000.00','Card Sales'=>'2250.00',
            'Expected Cash'=>'7000.00','Counted Cash'=>'6900.00','Variance'=>'-100.00',
            'Variance Explanation'=>'Shortage: PHP 100.00 short','variance_review_required'=>1,
            'Opened At'=>'2026-10-31 08:00:00','Closed At'=>'2026-10-31 16:00:00',
            'Closing Notes'=>str_repeat('Voucher verification needed. ',8)]);
        $open=array_merge($row,['Shift ID'=>43,'Status'=>'open','Variance'=>'','Counted Cash'=>'',
            'Closed At'=>'','variance_review_required'=>0]);
        $legacy=array_merge($row,['Shift ID'=>'','Record'=>'Legacy sales','variance_review_required'=>0]);
        $report=['rows'=>[$row,$open,$legacy],'totals'=>['Total Sales'=>'24750.00','Sale Count'=>54,'Total Refunds'=>'0.00']];
        $targetedShift=null;
        if ($argv[1]==='many') {
            $report['rows']=[];
            for ($i=0; $i<28; $i++) $report['rows'][]=array_merge($row,['Shift ID'=>42+$i]);
        }
        if ($argv[1]==='empty') $report=['rows'=>[],'totals'=>[]];
        if ($argv[1]==='review' || $argv[1]==='reviewed') {
            $filters['shift_id']='42'; $targetedShift=$row;
            if ($argv[1]==='reviewed') $targetedShift=array_merge($row,['reviewed_at'=>'2026-10-31 18:00:00',
                'reviewed_by'=>2,'review_notes'=>'Voucher confirmed.','review_shortage'=>0]);
        }
        eval(stream_get_contents(STDIN));
    `, variant], {input: template, encoding: 'utf8', cwd: root});
}

(async () => {
    const browser = await chromium.launch();
    const output = path.join(root, 'tmp/manual/shift-report-redesign');
    fs.mkdirSync(output, {recursive: true});
    try {
        const page = await browser.newPage({reducedMotion: 'reduce'});
        for (const variant of ['list', 'empty', 'review', 'reviewed', 'many']) {
            await page.setContent(render(variant));
            for (const asset of ['style.css', 'reports.css', 'shift-report.css', 'theme.css']) {
                await page.addStyleTag({path: path.join(root, 'src/frontend/assets/css', asset)});
            }
            await page.addScriptTag({path: path.join(root, 'src/frontend/assets/js/ui.js')});
            await page.evaluate(() => window.RetailMindUI.initPageContent());
            assert.equal(await page.locator('.shift-results #shift-filters form[method="get"]').count(), 1, 'Filters belong to records panel');
            assert.equal(await page.locator('.shift-results .shift-summary-table').count(), 1, 'Records share the filters panel');
            for (const theme of ['light', 'dark']) {
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                for (const width of [1440, 1024, 768, 375, 667]) {
                    await page.setViewportSize({width, height: width === 667 ? 375 : 960});
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${variant}/${theme}/${width}: page fits`);
                    assert(await page.locator('.report-filter-grid').evaluate(el => el.scrollWidth <= el.clientWidth), 'Filters fit');
                    if (variant === 'list') {
                        const tableWidth = await page.locator('table').evaluate(el => el.getBoundingClientRect().width);
                        await page.getByRole('button', {name: 'Breakdown for Shift #42', exact: true}).click();
                        const panel = page.locator('#shift-breakdown-0');
                        assert(await panel.isVisible(), 'Breakdown opens');
                        assert(await panel.evaluate(el => el.scrollWidth <= el.clientWidth && el.getBoundingClientRect().right <= innerWidth), 'Breakdown fits');
                        assert.equal(await page.locator('table').evaluate(el => el.getBoundingClientRect().width), tableWidth, 'Breakdown does not widen table');
                        await page.keyboard.press('Escape');
                        assert.equal(await panel.isVisible(), false, 'Escape dismisses breakdown');
                        await page.getByRole('button', {name: 'Breakdown for Shift #42', exact: true}).click();
                        await panel.getByRole('button', {name: 'Close', exact: true}).click();
                        assert.equal(await panel.isVisible(), false, 'Close dismisses breakdown');
                        if (width <= 760) assert(await page.locator('.table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth), 'Mobile records need no horizontal scroll');
                    }
                    if ([1440, 375].includes(width)) await page.screenshot({path: path.join(output, `${variant}-${theme}-${width}.png`), fullPage: true});
                }
            }
            assert.equal(await page.locator('form input[name="action"][value="export_csv"]').count(), 1, 'CSV action preserved');
            await page.emulateMedia({media: 'print'});
            assert.equal(await page.locator('#shift-filters').isVisible(), false, 'Print hides filters');
            assert(await page.locator('.shift-summary-table').isVisible(), 'Print preserves records');
            await page.emulateMedia({media: 'screen'});
            if (variant === 'empty') assert(await page.getByRole('link', {name: 'Adjust filters'}).isVisible());
            if (variant === 'review') {
                assert(await page.getByRole('button', {name: 'Mark reviewed', exact: true}).isVisible());
                assert.equal(await page.locator('#explanation').getAttribute('required'), '');
                assert(await page.getByRole('button', {name: 'Save draft', exact: true}).isVisible());
            }
            if (variant === 'reviewed') {
                assert.equal(await page.getByText('Voucher confirmed.', {exact: false}).count(), 1, 'Completed explanation appears once');
                await page.getByText('Reopen review', {exact: true}).click();
                assert(await page.getByLabel('Reason to reopen').isVisible(), 'Reopen form available');
                assert.equal(await page.locator('.shift-investigation-card .shift-reopen-disclosure').count(), 1, 'Reopen stays inside review card');
            }
            if (variant === 'many') {
                assert.equal(await page.locator('tbody tr:visible').count(), 25, 'Shared pagination still applies');
                assert(await page.locator('tfoot').isVisible(), 'Report totals stay visible outside pagination');
                await page.getByRole('button', {name: 'Next page', exact: true}).click();
                assert.equal(await page.locator('tbody tr:visible').count(), 3, 'Next page shows remaining shifts');
                assert(await page.locator('tfoot').isVisible(), 'Totals remain on next page');
            }
        }
        console.log('Shift report layout: passed (list, empty, review, reviewed; light/dark; five widths; breakdown dismissal).');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
