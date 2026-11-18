'use strict';
const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { mkdtempSync, rmSync } = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Add Product category: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const storage = mkdtempSync(path.join(os.tmpdir(), 'rm-product-category-'));
const env = { ...process.env, RM_TEST_PRODUCT_CATALOG: '1', BACKUP_STORAGE_PATH: storage, RM_THEME_TEST_DATABASE: database };
function fixture(action) {
    const result = spawnSync('php', [path.join(__dirname, 'support/theme_fixture.php'), action, database], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
}

(async () => {
    let server, browser;
    try {
        const { password } = fixture('create');
        fixture('open_fiscal_period');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], { env, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('PHP fixture server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch();
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(7000);
        await page.route('https://**/*', route => route.fulfill({ body: '' }));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(password);
        await Promise.all([
            page.waitForURL(url => !url.search.includes('login=1')),
            page.getByRole('button', { name: 'Log in', exact: true }).click()
        ]);

        const productsUrl = origin + '/components/inventory_management/products.php';
        await page.goto(productsUrl);
        const addMenu = page.locator('#product-add-trigger');
        const productDialog = page.getByRole('dialog', { name: 'Add Product', exact: true });
        const categoryDialog = page.getByRole('dialog', { name: 'Add Category', exact: true });
        const makeCategory = categoryDialog.getByRole('button', { name: 'Save Category', exact: true });
        await addMenu.click();
        await page.getByRole('menuitem', { name: 'Add Category' }).click();
        await categoryDialog.getByLabel('Category Name').fill('Tea & Coffee');
        await Promise.all([page.waitForURL('**/products.php?success=*'), makeCategory.click()]);

        const picker = productDialog.getByRole('combobox', { name: 'Category *', exact: true });
        const scan = productDialog.getByLabel('Individual Item Barcode');
        const lookup = productDialog.getByRole('button', { name: 'Look up', exact: true });
        const save = productDialog.getByRole('button', { name: 'Save Product', exact: true });
        const feedback = productDialog.locator('#create-barcode-feedback');
        await addMenu.click();
        await page.getByRole('menuitem', { name: 'Add Product' }).click();
        await scan.fill('012345678905');
        await lookup.click();
        await feedback.getByText(/Open Food Facts match/).waitFor();
        if (process.env.RM_CATEGORY_REVIEW_DIR) await page.screenshot({ path: path.join(process.env.RM_CATEGORY_REVIEW_DIR, 'category-desktop.png') });
        assert.equal(await picker.inputValue(), '', 'The catalog match leaves Category unselected');
        const suggestion = productDialog.getByRole('button', { name: 'Use Tea & Coffee', exact: true });
        await suggestion.click();
        assert.equal(await picker.inputValue(), 'Tea & Coffee');
        assert.equal(await picker.evaluate(el => el === document.activeElement), true);
        await productDialog.getByLabel('Cost Price').fill('4');
        await productDialog.getByLabel('Selling Price').fill('9');
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), save.click()]);
        await page.getByRole('button', { name: 'Manage Fixture Tea' }).click();
        await page.locator('#product-drawer').getByText('Tea & Coffee', { exact: true }).waitFor();

        await page.setViewportSize({ width: 390, height: 844 });
        const receivingUrl = origin + '/components/report/stock_receiving.php';
        await page.goto(receivingUrl);
        const receivingScan = page.locator('#product_search_scan');
        await receivingScan.fill('THEME-BARCODE');
        await receivingScan.press('Enter');
        await page.getByText('Theme Test Item', { exact: true }).first().waitFor();
        await page.locator('#supplier').fill('Original category delivery');
        await page.locator('#invoice_number').fill('CATEGORY-487');
        await receivingScan.fill('87654325');
        await receivingScan.press('Enter');
        await page.getByLabel('Individual item', { exact: true }).check();
        assert.equal(await page.locator('#supplier').inputValue(), 'Original category delivery');
        await page.getByRole('button', { name: 'Create product', exact: true }).click();
        await page.waitForURL('**/products.php?handoff=*');
        await page.waitForFunction(() => document.querySelector('#product-create-save')?.disabled === false);
        const handoff = page.url();
        assert.equal(await scan.inputValue(), '87654325');
        await lookup.click();
        await productDialog.getByText('No matching Store Category. Search or add one.', { exact: true }).waitFor();
        assert.equal(await picker.inputValue(), '');
        assert.equal(await suggestion.count(), 0);
        await picker.fill('tea');
        await page.getByRole('option', { name: 'Tea & Coffee', exact: true }).click();
        assert.equal(await picker.inputValue(), 'Tea & Coffee');

        const name = productDialog.getByLabel('Product Name');
        const cost = productDialog.getByLabel('Cost Price');
        const selling = productDialog.getByLabel('Selling Price');
        const addCategory = productDialog.getByRole('button', { name: 'Add Category', exact: true });
        await name.fill('Receiving seaweed draft');
        await cost.fill('7');
        await selling.fill('14');
        await addCategory.click();
        const categoryName = categoryDialog.getByLabel('Category Name');
        assert.equal(await categoryName.evaluate(el => el === document.activeElement), true);
        await page.keyboard.press('Escape');
        assert.equal(await addCategory.evaluate(el => el === document.activeElement), true);
        assert.equal(await name.inputValue(), 'Receiving seaweed draft');
        await addCategory.click();
        await categoryName.fill('Tea & Coffee');
        await makeCategory.click();
        await categoryDialog.getByRole('alert').waitFor();
        assert.equal(await categoryName.inputValue(), 'Tea & Coffee');
        assert.equal(page.url(), handoff);
        await categoryName.fill('Seaweed Snacks');
        await makeCategory.click();
        await categoryDialog.waitFor({ state: 'hidden' });
        assert.equal(await picker.inputValue(), 'Seaweed Snacks');
        assert.equal(await picker.evaluate(el => el === document.activeElement), true);
        assert.equal(await name.inputValue(), 'Receiving seaweed draft');
        assert.equal(await scan.inputValue(), '87654325');
        assert.equal(await cost.inputValue(), '7');
        assert.equal(await selling.inputValue(), '14');
        assert.equal(page.url(), handoff);
        assert.equal(await productDialog.evaluate(el => el.scrollWidth > el.clientWidth + 1), false);
        if (process.env.RM_CATEGORY_REVIEW_DIR) await page.screenshot({ path: path.join(process.env.RM_CATEGORY_REVIEW_DIR, 'category-mobile.png') });

        await Promise.all([page.waitForURL('**/stock_receiving.php?*'), save.press('Enter')]);
        await page.locator('#handoff_recovery').waitFor({ state: 'hidden' });
        await page.getByRole('button', { name: 'Receiving seaweed draft Enter' }).waitFor();
        assert.equal(await page.locator('#supplier').inputValue(), 'Original category delivery');
        assert.equal(await page.locator('#invoice_number').inputValue(), 'CATEGORY-487');
        await page.goto(productsUrl);
        await page.getByRole('button', { name: 'Manage Receiving seaweed draft' }).click();
        await page.locator('#product-drawer').getByText('Seaweed Snacks', { exact: true }).waitFor();
        assert.deepEqual(errors, []);
        console.log('Add Product category: passed');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
        rmSync(storage, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
