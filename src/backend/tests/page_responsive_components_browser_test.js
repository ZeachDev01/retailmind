const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

const read = path => fs.readFileSync('src/frontend/' + path, 'utf8').replace(/\r\n/g, '\n');
const clean = html => html.replace(/<\?(?:php|=)[\s\S]*?\?>/g, '');
const slice = (source, start, end) => clean(source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start))));

(async () => {
    const browser = await chromium.launch({headless:true});
    try {
        const page = await browser.newPage();
        const shared = ['style', 'theme'].map(name => read('assets/css/' + name + '.css')).join('\n');
        const promotions = read('components/inventory_management/promotions.php');
        const requests = read('components/inventory_management/replenishment_requests.php');
        const settings = read('components/system_administrator/system_settings.php');
        const products = read('components/inventory_management/products.php');
        const counts = read('components/inventory_management/inventory_counts.php');
        const cashier = read('components/cashier/dashboard.php');
        const sales = read('components/invoice/sales.php');
        const fixtures = [
            {name:'promotions', css:'inventory', html:slice(promotions, '<div class="two-col">', '</main>'), selectors:['.two-col', '.two-col > section', 'input', 'select', 'button']},
            {name:'replenishment', css:'inventory', html:slice(requests, '<div class="create-section">', '            <?php if (empty($requests))') + slice(requests, '<div class="request-card">', '                <?php endforeach; ?>\n            <?php endif; ?>'), selectors:['.tabs', '.forecast-items', '.request-card', 'input:not([type="hidden"])', 'select', 'textarea', 'button']},
            {name:'settings', html:slice(settings, '<div class="dashboard-section"><h3>Email delivery test', '</main>'), selectors:['form', 'input', 'button']},
            {name:'products', css:'inventory', inline:products.match(/<style>([\s\S]*?)<\/style>/)[1], html:slice(products, '<div class="catalog-toolbar"', '        </main>'), selectors:['.catalog-toolbar', 'input:not([type="hidden"])', 'select', 'button']},
            {name:'counts', css:'inventory', html:slice(counts, '<div class="count-layout">', '    <script>'), selectors:['.count-layout', '.count-panel', 'input:not([type="hidden"])', 'select', 'textarea', 'button']},
            {name:'cashier dashboard', css:'cashier', html:slice(cashier, '<div class="cashier-dashboard-grid">', '        </main>'), selectors:['.cashier-dashboard-grid', '.dashboard-panel', 'a']},
            {name:'sales reversals', css:'invoices', html:'<div class="receipts-container">' + slice(sales, '<div class="grid-two sales-reversal-grid">', '<?php require __DIR__') + '</div>', selectors:['.sales-reversal-grid', '.sales-reversal-grid > div', '.panel']},
        ];
        const failures = [];
        for (const fixture of fixtures) {
            const html = '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + shared + (fixture.css ? read('assets/css/' + fixture.css + '.css') : '') + (fixture.inline || '') + '</style><div class="app-shell"><aside class="sidebar"></aside><main class="main-content">' + fixture.html + '</main></div>';
            for (const width of [320,375,425,768,1024,1440]) {
                await page.setViewportSize({width,height:900});
                await page.setContent(html);
                const overflow = await page.evaluate(selectors => [...document.querySelectorAll(selectors.join(','))].filter(el => el.getClientRects().length && !el.closest('[hidden]') && !el.closest('.table-wrap, .data-table-scroll')).map(el => {
                    const container = el.parentElement.closest('.dashboard-section, .create-section, .forecast-item, .request-card, .main-content');
                    return {tag:el.tagName, cls:el.className, name:el.name, box:el.getBoundingClientRect().toJSON(), parent:container.getBoundingClientRect().toJSON()};
                }).filter(el => el.box.x < Math.max(0,el.parent.x) - 1 || el.box.right > Math.min(innerWidth,el.parent.right) + 1), fixture.selectors);
                if (overflow.length) failures.push({page:fixture.name,width,overflow});
                const pageWidth = await page.evaluate(() => document.documentElement.scrollWidth);
                if (pageWidth > width + 1) failures.push({page:fixture.name,width,pageWidth});
            }
        }
        if (process.env.RESPONSIVE_DIAGNOSTIC) console.log(JSON.stringify(failures, null, 2));
        assert.deepEqual(failures, [], 'Page-specific grids, forms and action controls remain inside every viewport');
        console.log('Page responsive components: passed (production products, counts, cashier dashboard, sales reversals, promotion, replenishment and settings markup at 320/375/425/768/1024/1440px)');
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode=1;});
