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
            await page.close();
        }
        console.log('Product filters browser: passed (desktop/mobile default, open, close)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
