const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const [file, tableTag] of [
            ['inventory_overview.php', '<table class="overview-product-table">'],
            ['products.php', '<table class="data-table" id="products-table">'],
        ]) {
            const source = fs.readFileSync(path.join(frontend, 'components/inventory_management', file), 'utf8');
            const start = source.indexOf(tableTag);
            assert.ok(start >= 0, `${file}: product table exists`);
            const table = source.slice(start, source.indexOf('</table>', start) + '</table>'.length);
            const html = execFileSync(process.env.PHP_BINARY || 'php', [], {
                encoding: 'utf8',
                input: `<?php
                    require_once '${path.join(frontend, '../backend/app/Services/PhilippineTime.php').replace(/\\/g, '/')}';
                    $lowStockProductIds = $expiredProductIds = $expiringProductIds = [];
                    $products = [];
                    foreach ([1234.5, 0, null] as $id => $cost) {
                        $products[] = ['product_id' => $id + 1, 'product_name' => 'Product ' . $id,
                            'brand' => 'Test', 'sku' => 'SKU-' . $id, 'barcode' => '123456789' . $id,
                            'category_name' => 'Test', 'unit_price' => 1500, 'cost_price' => $cost,
                            'quantity_on_hand' => [10, 0, 1][$id], 'reorder_level' => 2,
                            'next_expiration' => null];
                    }
                ?>${table}`,
            });

            const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
            const css = fs.readFileSync(path.join(frontend, 'assets/css/inventory.css'), 'utf8');
            const sharedCss = fs.readFileSync(path.join(frontend, 'assets/css/style.css'), 'utf8');
            const inlineCss = source.match(/<style>([\s\S]*?)<\/style>/)?.[1] || '';
            const shellClass = file === 'products.php' ? 'data-table-shell product-table-shell' : 'overview-product-table-shell';
            const markup = `<style>${sharedCss}${css}${inlineCss}</style><div class="${shellClass}"><div class="data-table-scroll">${html}</div></div>`;
            await page.setContent(markup);
            const headers = (await page.locator('thead th').allTextContents()).map(text => text.trim());
            const sellingIndex = headers.indexOf('Selling Price');
            const costIndex = headers.indexOf('Cost Price');
            const productsPage = file === 'products.php';
            assert.equal(headers[productsPage ? costIndex - 1 : sellingIndex - 4], 'Product', 'Comparison columns follow Product and inventory status');
            assert.ok(sellingIndex >= 0, 'Selling Price remains visible');
            assert.equal(costIndex, sellingIndex + (productsPage ? -1 : 1), 'Products shows Cost Price before Selling Price; Stock Overview keeps its order');
            if (productsPage) {
                assert.ok(!headers.includes('SKU / Barcode'), 'Products table omits SKU / Barcode');
                assert.ok(!headers.includes('Category'), 'Products table omits Category');
            } else {
                assert.equal(headers[headers.indexOf('Product') + 1], 'Status', 'Inventory status appears immediately after Product');
                assert.deepEqual(headers.slice(sellingIndex - 2, sellingIndex + 2), ['SKU / Barcode', 'Category', 'Selling Price', 'Cost Price']);
            }
            const rows = page.locator('tbody tr');
            for (const [index, expectedCost] of ['₱1,234.50', '₱0.00', '₱0.00'].entries()) {
                const cells = rows.nth(index).locator('td');
                assert.equal(await cells.count(), headers.length, 'Price columns stay aligned with their headers');
                assert.equal((await cells.nth(sellingIndex).innerText()).trim(), '₱1,500.00');
                assert.equal((await cells.nth(costIndex).innerText()).trim(), expectedCost);
                assert.equal(await cells.nth(costIndex).isVisible(), true);
                if (!productsPage) {
                    assert.equal((await cells.nth(headers.indexOf('Status')).innerText()).trim(), ['Available', 'Out of stock', 'Low stock'][index]);
                    assert.equal((await cells.nth(sellingIndex - 2).innerText()).replace(/\s+/g, ' ').trim(), `SKU-${index} 123456789${index}`);
                    assert.equal((await cells.nth(sellingIndex - 1).innerText()).trim(), 'Test');
                }
            }
            if (!productsPage) {
                await page.locator('body').evaluate(body => body.insertAdjacentHTML('beforeend', `
                    <div id="overview-default-view"></div><div id="overview-product-view"></div>
                    <div id="overview-product-empty"></div><input id="overview-product-search">
                    <select id="overview-category-filter"><option value=""></option></select>
                    <div id="overview-product-pagination"></div><span id="overview-page-summary"></span>
                    <span id="overview-page-status"></span><button id="overview-page-previous"></button>
                    <button id="overview-page-next"></button><button id="overview-back"></button>`));
                const script = source.slice(source.indexOf('    const productView ='), source.indexOf("    productSearch.addEventListener('input'"));
                await page.addScriptTag({ content: "const defaultView = document.getElementById('overview-default-view');" + script });
                for (const [column, ascending, descending] of [
                    ['Status', ['Product 0', 'Product 2', 'Product 1'], ['Product 1', 'Product 2', 'Product 0']],
                    ['Cost Price', ['Product 1', 'Product 2', 'Product 0'], ['Product 0', 'Product 1', 'Product 2']],
                    ['SKU / Barcode', ['Product 0', 'Product 1', 'Product 2'], ['Product 2', 'Product 1', 'Product 0']],
                ]) {
                    const sort = page.getByRole('button', { name: column, exact: true });
                    await sort.click();
                    assert.deepEqual(await rows.locator('.overview-product-name strong').allTextContents(), ascending, `${column}: ascending sort uses the matching cell`);
                    await sort.click();
                    assert.deepEqual(await rows.locator('.overview-product-name strong').allTextContents(), descending, `${column}: descending sort uses the matching cell`);
                }
            }
            if (file === 'products.php') {
                for (const width of [1440, 1024, 768, 390]) {
                    await page.setViewportSize({ width, height: 900 });
                    for (const index of [headers.indexOf('Product'), costIndex, sellingIndex]) {
                        assert.equal(await page.locator('thead th').nth(index).isVisible(), true, `${width}px: ${headers[index]} header visible`);
                        assert.equal(await rows.first().locator('td').nth(index).isVisible(), true, `${width}px: ${headers[index]} value visible`);
                    }
                    const positions = await rows.first().locator('td').evaluateAll(cells => cells.slice(1, 6).map(cell => cell.getBoundingClientRect().top));
                    assert.ok(positions.every(top => Math.abs(top - positions[0]) < 1), `${width}px: comparison columns stay beside Product`);
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px: table scroll stays inside the page`);
                }
                const chooser = source.match(/<div class="rm-modal-body"><label class="u-checkbox-row"><input type="checkbox" data-column=[^\n]+/)[0];
                const preferencesScript = source.slice(source.indexOf('        const columnPreferences ='), source.indexOf('        // Saved view'));
                await page.route('http://retailmind.test/products', route => route.fulfill({ contentType: 'text/html', body: markup + chooser }));
                await page.goto('http://retailmind.test/products');
                await page.evaluate(() => localStorage.setItem('retailmind_product_columns', JSON.stringify({ barcode: false, category: false, price: false, cost: false })));
                assert.equal(await page.locator('[data-column="barcode"], [data-column="category"]').count(), 0, 'Removed columns cannot be restored through column controls');
                await page.addScriptTag({ content: preferencesScript });
                for (const name of ['Selling Price', 'Cost Price']) {
                    assert.equal(await page.getByRole('columnheader', { name, exact: true }).isVisible(), true, `${name}: old settings must not hide the newly requested columns after initialization`);
                }
                await page.reload();
                await page.addScriptTag({ content: preferencesScript });
                assert.equal(await page.getByRole('columnheader', { name: /SKU \/ Barcode|Category/ }).count(), 0, 'Removed columns stay absent after initialization and reload');
                await page.locator('[data-column="cost"]').uncheck();
                assert.equal(await page.locator('th.column-cost').isVisible(), false, 'Users can still hide an optional column');
                await page.reload();
                await page.addScriptTag({ content: preferencesScript });
                assert.equal(await page.locator('th.column-cost').isVisible(), false, 'New visibility choices persist after reload');
            }
            await page.close();
        }
        console.log('Inventory price comparison: passed');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
