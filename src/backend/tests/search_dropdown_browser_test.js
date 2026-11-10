const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');

const markup = `<form><label for="product">Product</label>
            <select id="product" name="product_id" data-search-dropdown required>
            <option value="">Choose product</option>
            ${Array.from({length: 8}, (_, i) => `<option value="${i + 1}">SKU-${i + 1} — Product ${i + 1}</option>`).join('')}
            <option value="99" disabled>Unavailable product</option></select>
            <label for="brand">Brand</label><input id="brand" name="brand" list="brands">
            <datalist id="brands"><option value="Acme"></option></datalist>
            <label for="category">Category</label><select id="category" name="category_id" data-search-dropdown><option value="">All categories</option><option value="1" selected>Grocery</option></select>
            <label for="family">Parent Product Family</label><select id="family" name="parent_product_id" data-search-dropdown data-search-empty-label><option value="">Standalone product</option><option value="7">Widget (W-7)</option></select>
            <button type="reset">Reset</button></form>`;

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(2000);
        await page.setContent(markup);
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
        const family = page.getByRole('combobox', {name: 'Parent Product Family', exact: true});
        assert.equal(await family.inputValue(), 'Standalone product', 'The empty option still shows its label');
        await family.click();
        assert.equal(await page.getByRole('option', {name: 'Widget (W-7)', exact: true}).isVisible(), true, 'All families stay listed while the empty label is shown');
        await page.getByRole('option', {name: 'Widget (W-7)', exact: true}).click();
        assert.equal(await family.inputValue(), 'Widget (W-7)');
        assert.equal(await page.locator('#family').inputValue(), '7');
        await family.fill('');
        await family.click();
        await page.getByRole('option', {name: 'Standalone product', exact: true}).click();
        assert.equal(await family.inputValue(), 'Standalone product', 'Standalone is visible again after it is reselected');
        assert.equal(await page.locator('#family').inputValue(), '');
        await page.getByRole('button', {name: 'Reset', exact: true}).click();
        assert.equal(await input.inputValue(), '');
        await page.evaluate(() => RetailMindUI.initPageContent());
        assert.equal(await page.getByRole('combobox', {name: 'Product', exact: true}).count(), 1, 'Partial navigation initialization is idempotent');
        await input.fill('product');
        await page.evaluate(() => window.dispatchEvent(new Event('resize')));
        assert.equal(await input.getAttribute('aria-expanded'), 'false', 'Viewport changes close a popup before it becomes detached from its field');

        // Touch screens: first tap lists choices without the on-screen keyboard, second tap unlocks typing.
        const tablet = await browser.newContext({viewport: {width: 1024, height: 768}, isMobile: true, hasTouch: true});
        const tabletPage = await tablet.newPage();
        tabletPage.setDefaultTimeout(2000);
        await tabletPage.setContent(markup);
        await tabletPage.addScriptTag({path: path.resolve(__dirname, '../../frontend/assets/js/ui.js')});
        await tabletPage.evaluate(() => RetailMindUI.initPageContent());
        const touchCategory = tabletPage.getByRole('combobox', {name: 'Category', exact: true});
        assert.equal(await touchCategory.evaluate(el => el.readOnly), true, 'Touch fields start read-only so the first tap cannot raise the keyboard');
        await touchCategory.tap();
        assert.equal(await touchCategory.getAttribute('aria-expanded'), 'true', 'First tap lists choices');
        assert.equal(await touchCategory.evaluate(el => el.readOnly), true, 'First tap keeps the keyboard closed');
        await tabletPage.getByRole('option', {name: 'Grocery', exact: true}).click();
        assert.equal(await tabletPage.locator('#category').inputValue(), '1');
        assert.equal(await touchCategory.evaluate(el => el.readOnly), true, 'Selection returns the field to keyboard-free tapping');
        await touchCategory.tap();
        await touchCategory.tap();
        assert.equal(await touchCategory.evaluate(el => el.readOnly), false, 'Second tap exposes the keyboard for filtering');
        await tabletPage.evaluate(() => window.dispatchEvent(new Event('resize')));
        assert.equal(await touchCategory.evaluate(el => el.readOnly), false, 'The viewport change from the keyboard must not re-lock the field');
        await touchCategory.tap();
        assert.equal(await touchCategory.getAttribute('aria-expanded'), 'true', 'Field reopens with the keyboard still available');
        await touchCategory.fill('all');
        assert.equal(await tabletPage.getByRole('option', {name: 'All categories', exact: true}).isVisible(), true);
        await touchCategory.press('Escape');
        assert.equal(await touchCategory.evaluate(el => el.readOnly), false, 'Closing the list does not drop the keyboard for the focused field');
        const touchBrand = tabletPage.getByRole('combobox', {name: 'Brand', exact: true});
        await touchBrand.tap();
        assert.equal(await touchBrand.evaluate(el => el.readOnly), true, 'First tap suggests known brands without the keyboard');
        await tabletPage.getByRole('option', {name: 'Acme', exact: true}).click();
        assert.equal(await touchBrand.inputValue(), 'Acme');
        await touchBrand.tap();
        await touchBrand.tap();
        await touchBrand.fill('New brand');
        assert.equal(await touchBrand.evaluate(el => el.checkValidity()), true, 'Unlocked touch field still accepts custom brands');
        await tablet.close();
        console.log('Search dropdown: passed');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
