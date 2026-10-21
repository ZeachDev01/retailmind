const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(2000);
        await page.setContent(`<form><label for="product">Product</label>
            <select id="product" name="product_id" data-search-dropdown required>
            <option value="">Choose product</option>
            ${Array.from({length: 8}, (_, i) => `<option value="${i + 1}">SKU-${i + 1} — Product ${i + 1}</option>`).join('')}
            <option value="99" disabled>Unavailable product</option></select>
            <label for="brand">Brand</label><input id="brand" name="brand" list="brands">
            <datalist id="brands"><option value="Acme"></option></datalist>
            <label for="category">Category</label><select id="category" name="category_id" data-search-dropdown><option value="">All categories</option><option value="1" selected>Grocery</option></select>
            <button type="reset">Reset</button></form>`);
        await page.addScriptTag({path: path.resolve(__dirname, '../../frontend/assets/js/ui.js')});
        await page.evaluate(() => RetailMindUI.initPageContent());
        const input = page.getByRole('combobox', {name: 'Product', exact: true});
        await input.fill('product');
        assert.equal(await page.getByRole('option').count(), 5);
        await page.getByRole('button', {name: 'Show all 8 matches'}).click();
        assert.equal(await page.getByRole('option').count(), 8);
        await page.getByRole('option', {name: 'SKU-7 — Product 7', exact: true}).click();
        assert.equal(await page.locator('form').evaluate(form => new FormData(form).get('product_id')), '7');
        await input.fill('product 2');
        await input.press('ArrowDown');
        await input.press('Enter');
        assert.equal(await input.inputValue(), 'SKU-2 — Product 2');
        assert.equal(await page.locator('#product').inputValue(), '2');
        await input.fill('missing');
        assert.equal(await input.evaluate(el => el.checkValidity()), false, 'Unselected text cannot submit an old product ID');
        assert.equal(await page.getByText('No matches found', {exact: true}).isVisible(), true);
        await input.press('Escape');
        assert.equal(await input.getAttribute('aria-expanded'), 'false');
        const brand = page.getByRole('combobox', {name: 'Brand', exact: true});
        await brand.fill('ac');
        await page.getByRole('option', {name: 'Acme', exact: true}).click();
        assert.equal(await brand.inputValue(), 'Acme');
        await brand.fill('New brand');
        assert.equal(await brand.evaluate(el => el.checkValidity()), true, 'Datalist fields still accept custom values');
        const category = page.getByRole('combobox', {name: 'Category', exact: true});
        await category.fill('');
        assert.equal(await category.evaluate(el => el.checkValidity()), true, 'Optional selectors can be cleared');
        assert.equal(await page.locator('form').evaluate(form => new FormData(form).get('category_id')), '');
        await page.getByRole('button', {name: 'Reset', exact: true}).click();
        assert.equal(await input.inputValue(), '');
        await page.evaluate(() => RetailMindUI.initPageContent());
        assert.equal(await page.getByRole('combobox', {name: 'Product', exact: true}).count(), 1, 'Partial navigation initialization is idempotent');
        await input.fill('product');
        await page.evaluate(() => window.dispatchEvent(new Event('resize')));
        assert.equal(await input.getAttribute('aria-expanded'), 'false', 'Viewport changes close a popup before it becomes detached from its field');
        console.log('Search dropdown: passed');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
