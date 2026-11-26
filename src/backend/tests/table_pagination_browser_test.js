const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const root = path.resolve(__dirname, '../../..');
const assets = path.join(root, 'src/frontend/assets');
const partial = path.join(root, 'src/backend/app/Views/table_pagination.php').replaceAll('\\', '/');
function render(url) {
    const query = Object.fromEntries(url.searchParams);
    const footer = execFileSync(process.env.PHP_BINARY || 'php', ['-r', `
        $_GET=json_decode($argv[1],true);
        $pageSize=filter_var($_GET['length']??15,FILTER_VALIDATE_INT);
        $pageSize=in_array($pageSize,[10,15,25,50,100],true)?$pageSize:15;
        $totalRows=83; $totalPages=max(1,(int)ceil($totalRows/$pageSize));
        $page=min($totalPages,max(1,(int)($_GET['page']??1)));
        $rows=array_slice(range(1,$totalRows),($page-1)*$pageSize,$pageSize);
        $hasNext=$page<$totalPages; $paginationFilters=['q'=>'saved-filter'];
        $url=fn($params)=>'?'.http_build_query($params+['length'=>$pageSize,'q'=>'saved-filter']);
        echo '<div class="table-wrap"><table><thead><tr><th>Record</th></tr></thead><tbody>';
        foreach($rows as $row) echo '<tr><td>Record '.$row.'</td></tr>';
        echo '</tbody></table></div>';
        require $argv[2];
    `, JSON.stringify(query), partial], {encoding: 'utf8'});
    const client = url.pathname === '/client';
    const records = Array.from({length: 83}, (_, index) => `<tr><td>Record ${index + 1}</td></tr>`).join('');
    return `<!doctype html><html data-theme="light"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Table pagination fixture</title>
        <link rel="stylesheet" href="/assets/css/theme.css"><link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/cashier-pages.css">
        <style>.main-content{margin:0!important;padding:24px!important}table{min-width:0!important}body{margin:0}</style></head>
        <body class="cashier-refunds-page"><main class="main-content"><h1>${client ? 'Client' : 'Server'} table pagination</h1>
        ${client ? `<div class="table-wrap"><table><thead><tr><th>Record</th></tr></thead><tbody>${records}</tbody></table></div>` : footer}
        </main><script src="/assets/js/ui.js"></script></body></html>`;
}
const server = http.createServer((req, res) => {
    try {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/assets/')) {
            const file = path.resolve(assets, url.pathname.slice(8));
            if (!file.startsWith(assets + path.sep) || !fs.existsSync(file)) { res.writeHead(404).end(); return; }
            res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
            res.end(fs.readFileSync(file));
        } else { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(render(url)); }
    } catch (error) { res.writeHead(500).end(String(error)); }
});

(async () => {
    await new Promise(resolve => server.listen(process.env.PAGINATION_PORT || 0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    if (process.argv.includes('--serve')) { console.log(origin); return; }
    const browser = await chromium.launch();
    const output = path.join(root, 'output/playwright/table-pagination');
    fs.mkdirSync(output, {recursive: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const route of ['/', '/client']) {
            await page.goto(origin + route);
            const footer = page.locator('.rm-table-footer');
            const selector = footer.locator('select');
            assert.equal(await selector.inputValue(), '15');
            assert.equal(await page.locator('tbody tr:visible').count(), 15);
            assert.equal(await footer.getByRole('button', {name: 'Apply'}).count(), 0);
            assert(await footer.locator('.rm-page-size').evaluate(el => el.previousElementSibling.textContent.trim() === '1'), 'Page-size dropdown follows page 1');
            assert.doesNotMatch(await footer.locator('.rm-page-size').innerText(), /entries per page/i);
            await footer.getByRole(route === '/' ? 'link' : 'button', {name: 'Next page', exact: true}).or(footer.getByRole('link', {name: 'Next', exact: true})).click();
            assert.match(await page.locator('tbody tr:visible').first().innerText(), /Record 16/);
            await selector.selectOption('25');
            await page.waitForFunction(() => document.querySelectorAll('tbody tr:not([hidden])').length === 25);
            assert.match(await page.locator('tbody tr:visible').first().innerText(), /Record 1$/);
            if (route === '/') assert.equal(new URL(page.url()).searchParams.get('q'), 'saved-filter');
            await selector.selectOption('15');
            await page.waitForFunction(() => document.querySelectorAll('tbody tr:not([hidden])').length === 15);
            assert.equal(await footer.locator('select').count(), 1, 'Redraw keeps one page-size dropdown');
            if (route === '/client') {
                await footer.getByRole('button', {name: 'Page 1', exact: true}).focus();
                await page.keyboard.press('Tab');
                assert(await selector.evaluate(el => el === document.activeElement), 'Keyboard order reaches dropdown after page 1');
                await selector.selectOption('25');
                assert(await selector.evaluate(el => el === document.activeElement), 'Page-size change preserves keyboard focus');
                await selector.selectOption('15');
            }
            for (const theme of ['light', 'dark']) for (const width of [1440, 1280, 1181, 1024, 820, 768, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${route}/${theme}/${width}: no overflow`);
                assert(await footer.locator('a, button, select').evaluateAll(controls => controls.every(control => control.getBoundingClientRect().height >= 44)), '44px targets');
                {
                    const layout = await footer.evaluate(el => {
                        const box = el.getBoundingClientRect(), size = el.querySelector('.rm-page-size').getBoundingClientRect();
                        const buttons = [...el.querySelector('nav').children].map(button => button.getBoundingClientRect());
                        const summary = el.firstElementChild.getBoundingClientRect();
                        return {
                            controlsInRow: buttons.every(button => Math.abs(button.y - buttons[0].y) <= 1),
                            summaryAligned: Math.abs(summary.y + summary.height / 2 - size.y - size.height / 2) <= 1,
                            separate: summary.right <= buttons[0].x,
                            contained: buttons.every(button => button.x >= box.x && button.right <= box.right),
                        };
                    });
                    assert(layout.contained, `${route}/${theme}/${width}: navigation stays within footer`);
                    if (width >= 768) {
                        assert(layout.controlsInRow, `${route}/${theme}/${width}: pagination stays on one row`);
                        assert(layout.summaryAligned, 'Summary aligns with pagination on desktop and tablet');
                        assert(layout.separate, 'Summary does not overlap pagination');
                    }
                }
                if ([1440, 1280, 1024, 768, 390].includes(width)) await footer.screenshot({path: path.join(output, `${route === '/' ? 'server' : 'client'}-${theme}-${width}.png`)});
            }
            await page.setViewportSize({width: 1440, height: 900});
            await page.locator('main').evaluate(el => { el.style.width = '720px'; });
            assert(await footer.locator('nav').evaluate(el => {
                const buttons = [...el.children].map(button => button.getBoundingClientRect());
                return buttons.every(button => Math.abs(button.y - buttons[0].y) <= 1);
            }), 'Narrow panel uses tablet layout even on desktop');
            await page.locator('main').evaluate(el => { el.style.removeProperty('width'); });
        }
        await page.goto(origin + '/?page=999&length=10');
        assert.match(await page.locator('tbody tr:visible').first().innerText(), /Record 81/);
        assert.equal(await page.locator('[aria-current="page"]').innerText(), '9');
        assert.equal(await page.locator('.history-page-buttons [aria-label^="Page"]').count(), 5);
        await page.goto(origin + '/?length=-1');
        assert.equal(await page.locator('select').inputValue(), '15');
        assert.deepEqual(errors, []);
        console.log('Shared table pagination: passed (server/client, automatic size, filters, boundaries, responsive, themes).');
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
