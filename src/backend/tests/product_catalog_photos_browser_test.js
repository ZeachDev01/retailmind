'use strict';
const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Add Product catalog photos: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const storage = mkdtempSync(path.join(os.tmpdir(), 'rm-catalog-photo-'));
const state = path.join(storage, 'provider-state');
const env = { ...process.env, RM_TEST_PRODUCT_CATALOG: '1', RM_TEST_PRODUCT_PHOTO_STATE: state, RM_THEME_TEST_DATABASE: database, BACKUP_STORAGE_PATH: storage };
const setImageResponse = outcome => writeFileSync(state, outcome);
const fixture = action => {
    const result = spawnSync('php', [require.resolve('./support/theme_fixture.php'), action, database], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
};
const gif = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');
const photo = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

(async () => {
    let browser, server;
    try {
        setImageResponse('ok');
        const data = fixture('create');
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
        browser = await chromium.launch({ headless: true });
        const productsUrl = origin + '/components/inventory_management/products.php';
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
            page.setDefaultTimeout(10000);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('https://**/*', route => route.fulfill(route.request().url().startsWith('https://images.openfoodfacts.org/images/products/')
                ? { contentType: 'image/png', body: photo }
                : { body: '' }));
            await page.goto(origin + '/?login=1', { waitUntil: 'domcontentloaded' });
            await page.locator('#landing-login-username').fill('theme_inventory_manager');
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1'), { waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
            await page.goto(productsUrl, { waitUntil: 'domcontentloaded' });
            const open = async () => {
                await page.locator('#product-add-trigger').click();
                await page.locator('#add-product-btn').click();
            };
            await open();
            const dialog = page.getByRole('dialog', { name: 'Add Product' });
            const modalBody = dialog.locator('.rm-modal-body');
            const lookup = async code => {
                await dialog.getByLabel('Individual Item Barcode').fill(code);
                await dialog.getByRole('button', { name: 'Look up' }).click();
                await dialog.getByText('Open Food Facts match.', { exact: false }).waitFor();
            };
            const preview = dialog.getByRole('img', { name: 'Product photo preview' });
            const imageLoaded = () => page.waitForFunction(() => {
                const image = document.getElementById('image-preview');
                return image.complete && image.naturalWidth > 0;
            });
            const source = dialog.getByRole('link', { name: 'Open Food Facts' });
            const license = dialog.getByRole('link', { name: 'CC BY-SA 3.0' });
            const upload = () => dialog.getByLabel('Product photo', { exact: true }).setInputFiles({ name: 'upload.gif', mimeType: 'image/gif', buffer: gif });
            const fillDetails = async name => {
                await dialog.getByLabel('Product Name').fill(name);
                await dialog.locator('#product-category').selectOption({ index: 1 }, { force: true });
                await dialog.locator('#cost-price').fill('20');
                await dialog.locator('#selling-price').fill('25');
            };
            const save = dialog.getByRole('button', { name: 'Save Product', exact: true });
            const submitUntil = async (button, parameter) => Promise.all([
                page.waitForURL(url => url.searchParams.has(parameter), { waitUntil: 'domcontentloaded' }),
                button.click(),
            ]);
            const assertSaved = async (name, bytes, attributed = false) => {
                const button = page.getByRole('button', { name: 'Manage ' + name, exact: true });
                await button.waitFor();
                assert.equal(await button.count(), 1, 'Only one Product was created');
                const thumbnail = button.locator('xpath=ancestor::tr').locator('.product-thumb img');
                if (bytes) {
                    assert.match(await thumbnail.getAttribute('src'), /product_image\.php\?file=[a-f0-9]{32}\.(png|gif)$/);
                    const saved = await page.request.get(new URL(await thumbnail.getAttribute('src'), origin).href);
                    assert.equal(saved.status(), 200);
                    assert.deepEqual(await saved.body(), bytes);
                } else {
                    assert.equal(await thumbnail.count(), 0);
                }
                await button.click();
                const details = page.locator('#product-panel-info');
                await details.waitFor();
                const savedSource = details.getByRole('link', { name: 'Open Food Facts' });
                if (attributed) await savedSource.waitFor();
                else assert.equal(await savedSource.count(), 0);
                if (attributed) assert.equal(await details.getByRole('link', { name: 'CC BY-SA 3.0' }).count(), 1);
                await page.goto(productsUrl, { waitUntil: 'domcontentloaded' });
            };
            await lookup(width === 1440 ? '012345678905' : '11111115');
            await preview.waitFor();
            await imageLoaded();
            assert.equal(await source.getAttribute('href'), width === 1440
                ? 'https://world.openfoodfacts.org/product/0012345678905' : 'https://world.openfoodfacts.org/product/11111115');
            assert.equal(await license.getAttribute('href'), 'https://creativecommons.org/licenses/by-sa/3.0/');
            const [photoBox, detailsBox] = await Promise.all([preview.boundingBox(), dialog.getByLabel('Product Name').boundingBox()]);
            assert(width === 1440 ? photoBox.x + photoBox.width < detailsBox.x : photoBox.y < detailsBox.y);
            assert(await modalBody.evaluate(body => body.scrollWidth <= body.clientWidth));
            if (width === 1440) {
                const name = 'Persisted provider photo';
                await fillDetails(name);
                await submitUntil(save, 'success');
                await assertSaved(name, photo, true);
            } else {
                const name = 'Removed provider photo';
                await dialog.getByRole('button', { name: 'Remove photo' }).click();
                await dialog.getByRole('button', { name: 'Look up' }).click();
                await dialog.getByText('Open Food Facts match.', { exact: false }).waitFor();
                assert.equal(await preview.count(), 0, 'A repeat lookup does not restore a removed photo');
                await fillDetails(name);
                await submitUntil(save, 'success');
                await assertSaved(name, null);
                await open();
                await upload();
                await lookup('22222220');
                await imageLoaded();
                assert.equal(await source.count(), 0, 'An upload already selected supersedes the catalog photo');
                const uploaded = 'Chosen upload';
                await fillDetails(uploaded);
                await submitUntil(save, 'success');
                await assertSaved(uploaded, gif);
            }

            const failures = width === 1440 ? [['33333335', 'invalid', true]] : [['44444440', 'large', false]];
            for (const [code, outcome, retry] of failures) {
                setImageResponse(outcome);
                await open();
                await lookup(code);
                const name = 'Recovered photo ' + code;
                await fillDetails(name);
                await submitUntil(save, 'warning');
                const retryAction = dialog.getByRole('button', { name: 'Retry photo' });
                const continueAction = dialog.getByRole('button', { name: 'Save without photo' });
                await retryAction.waitFor();
                assert(await continueAction.isVisible());
                assert.equal(await dialog.getByLabel('Product Name').inputValue(), name);
                assert.equal(await dialog.locator('#selling-price').inputValue(), '25');
                assert.equal(await page.getByRole('button', { name: 'Manage ' + name, exact: true }).count(), 0);
                setImageResponse('ok');
                await submitUntil(retry ? retryAction : continueAction, 'success');
                await assertSaved(name, retry ? photo : null, retry);
            }
            if (width === 390) {
                await page.goto(origin + '/components/report/stock_receiving.php', { waitUntil: 'domcontentloaded' });
                await page.locator('#invoice_number').fill('Photo recovery delivery');
                const code = '55555555';
                const scanner = page.locator('#product_search_scan');
                await scanner.fill(code);
                await scanner.press('Enter');
                await page.getByLabel('Individual item', { exact: true }).check();
                await page.getByRole('button', { name: 'Create product', exact: true }).click();
                await page.waitForFunction(() => document.querySelector('#product-create-save')?.disabled === false);
                await lookup(code);
                const name = 'Receiving photo recovery';
                await fillDetails(name);

                // A valid CDN image for a different barcode cannot be selected in this save.
                await dialog.locator('[name="catalog_photo"]').evaluate(field => {
                    field.value = 'https://images.openfoodfacts.org/images/products/22222220/front_en.4.400.jpg';
                });
                const rejected = page.waitForResponse(response => response.url().includes('products.php?handoff=') && response.status() === 422);
                await save.click();
                assert.equal((await (await rejected).json()).photoError, true);
                await dialog.getByRole('button', { name: 'Retry photo' }).waitFor();
                assert.equal(await dialog.getByLabel('Product Name').inputValue(), name);

                // Reopen the same handoff and confirm the server still offers recovery.
                await page.reload({ waitUntil: 'domcontentloaded' });
                await dialog.getByRole('button', { name: 'Save without photo' }).waitFor();
                assert.equal(await dialog.locator('#selling-price').inputValue(), '25');
                await lookup(code);
                setImageResponse('missing');
                const unavailable = page.waitForResponse(response => response.url().includes('products.php?handoff=') && response.status() === 422);
                await save.click();
                assert.equal((await (await unavailable).json()).photoError, true);
                setImageResponse('ok');
                await Promise.all([
                    page.waitForURL(url => url.pathname.endsWith('/stock_receiving.php'), { waitUntil: 'domcontentloaded' }),
                    dialog.getByRole('button', { name: 'Retry photo' }).click(),
                ]);
                await page.waitForFunction(() => document.getElementById('invoice_number').value === 'Photo recovery delivery');
                await page.goto(productsUrl, { waitUntil: 'domcontentloaded' });
                await assertSaved(name, photo, true);
            }
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Add Product catalog photos passed.');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
        rmSync(storage, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
