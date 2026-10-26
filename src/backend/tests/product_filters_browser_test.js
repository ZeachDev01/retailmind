const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
    // Use the production toolbar and toggle, with server-rendered data omitted.
    const toolbar = source.slice(source.indexOf('<div class="catalog-toolbar"'), source.indexOf('<div class="catalog-filter-row"'));
    const panel = source.slice(source.indexOf('<div class="catalog-filter-row"'), source.indexOf('<div class="catalog-utility-controls"'));
    const markup = (toolbar + panel + '</div></div>').replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '');
    const toggleScript = source.slice(source.indexOf('const filterToggle ='), source.indexOf('function getFilters()'));
    const css = fs.readFileSync(path.join(frontend, 'assets/css/style.css'), 'utf8') + source.match(/<style>([\s\S]*?)<\/style>/)[1];
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            await page.setContent(`<html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style></head><body class="products-page">${markup}</body></html>`);
            await page.addScriptTag({ content: toggleScript });
            const toggle = page.getByRole('button', { name: 'Filter', exact: true });
            const category = page.getByLabel('Filter by category');
            assert.equal(await category.isVisible(), false, `${width}: extra filters start collapsed`);
            assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
            await toggle.click();
            assert.equal(await category.isVisible(), true, `${width}: Filter opens extra options`);
            assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
            await toggle.click();
            assert.equal(await category.isVisible(), false, `${width}: Filter closes extra options`);
            assert.equal(await toggle.getAttribute('aria-expanded'), 'false');

            const emptyMarkup = source.slice(source.indexOf('<section class="data-table-shell catalog-empty"'), source.indexOf('</section>', source.indexOf('<section class="data-table-shell catalog-empty"')) + '</section>'.length)
                .replace(/<\?php else: \?>[\s\S]*?<\?php endif; \?>/, '')
                .replace(/<\?=[\s\S]*?\?>|<\?php[\s\S]*?\?>/g, '');
            const renderPage = source.slice(source.indexOf('function renderPage()'), source.indexOf('[searchInput, categoryFilter, stockFilter, statusFilter, priceFilter].forEach'));
            await page.setContent(`<html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style></head><body>${emptyMarkup}<div id="catalog-results"></div><div id="pagination-buttons"></div><div id="page-status"></div><div id="page-summary"></div><div id="result-count"></div></body></html>`);
            await page.addScriptTag({ content: `
                let productRows = [], filteredRows = [], currentPage = 1;
                const pageSizeInput = { value: 10 };
                const resultCount = document.getElementById('result-count');
                const pageSummary = document.getElementById('page-summary');
                const pageStatus = document.getElementById('page-status');
                const paginationButtons = document.getElementById('pagination-buttons');
                function syncSelectAll() {}
                ${renderPage}
                renderPage();
            ` });
            assert.equal(await page.getByRole('heading', { name: 'No products in the catalog yet' }).isVisible(), true, `${width}: first-use guidance appears`);
            assert.equal(await page.getByRole('button', { name: 'Add Product' }).isVisible(), true, `${width}: direct Add Product action appears`);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${width}: empty state fits the viewport`);
            await page.addScriptTag({ content: `function openModal(id) { window.openedModal = id; }
                ${source.match(/document\.getElementById\('empty-add-product'\)\?\.addEventListener\([^;]+;/)[0]}` });
            await page.getByRole('button', { name: 'Add Product' }).click();
            assert.equal(await page.evaluate(() => window.openedModal), 'add-product-modal', `${width}: action opens the existing product form`);
            await page.evaluate(() => {
                productRows = [document.createElement('tr')];
                renderPage();
            });
            assert.equal(await page.getByRole('heading', { name: 'No matching products' }).isVisible(), true, `${width}: filtered empty state differs from first use`);
            await page.evaluate(() => {
                filteredRows = productRows.slice();
                renderPage();
            });
            assert.equal(await page.locator('#catalog-results').getAttribute('hidden'), null, `${width}: results return when a product matches`);
            await page.close();
        }
        console.log('Product filters and empty states browser: passed (desktop/mobile)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
