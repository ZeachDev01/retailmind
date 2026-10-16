const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../../..');
const html = execFileSync('php', ['-r', `
    ob_start();
    require 'src/backend/tests/replenishment_metadata_contract.php';
    ob_end_clean();
    $pdo->exec("CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
        UPDATE products SET status='active';
        INSERT INTO inventory VALUES (1, 0);
        INSERT INTO products VALUES (2, 'WATCH', 'Watch product', 'active', 7, 1, 10, 5, 1);
        INSERT INTO products VALUES (3, 'COVERED', 'Covered product', 'active', 7, 1, 10, 5, 1);
        INSERT INTO products VALUES (4, 'UNKNOWN', 'Unknown product', 'active', 7, 1, 10, 5, 1);
        INSERT INTO inventory VALUES (2, 10), (3, 30), (4, 5);
        INSERT INTO demand_forecasts VALUES (2, 10, 2, '2026-10-01', 7, 8, 'test-model', 'High');
        INSERT INTO demand_forecasts VALUES (3, 10, 3, '2026-10-16', 7, 8, 'test-model', 'High')");
    for ($i = 5; $i <= 30; $i++) {
        $pdo->exec("INSERT INTO products VALUES ($i, 'SKU-$i', 'Bulk item $i', 'active', 7, 1, 10, 5, 1);
            INSERT INTO inventory VALUES ($i, 40)");
    }
    echo renderReplenishmentPage($pdo, 'replenishment_queue');
`], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.setDefaultTimeout(1500);
        await page.setContent(html.replace(/<link[^>]+>/g, '').replace(/<script[^>]*>.*?<\/script>/gs, ''));
        await page.addStyleTag({ path: path.join(root, 'src/frontend/assets/css/style.css') });
        await page.addStyleTag({ path: path.join(root, 'src/frontend/assets/css/replenishment.css') });
        assert.deepEqual((await page.locator('.queue-priority').allTextContents()).slice(0, 4), ['Out of stock', 'Low stock', 'Low stock', 'In stock']);
        assert.equal(await page.locator('select option').evaluateAll(options => options.some(option => /Urgent|Watch|Covered|Unknown|Urgency/i.test(option.textContent))), false);
        await page.addScriptTag({ path: path.join(root, 'src/frontend/assets/js/replenishment.js') });

        // Compact single-line layout & inline cells check
        const rowLayout = await page.locator('.queue-item').first().evaluate(row => {
            const cells = row.querySelectorAll('td');
            const productRect = row.querySelector('.queue-product').getBoundingClientRect();
            const skuRect = row.querySelector('.queue-sku').getBoundingClientRect();
            const onHandNum = cells[2].querySelector('.queue-number').getBoundingClientRect();
            const onHandUnit = cells[2].querySelector('small').getBoundingClientRect();
            const suggestedNum = cells[3].querySelector('.queue-number').getBoundingClientRect();
            const suggestedUnit = cells[3].querySelector('small').getBoundingClientRect();
            const detailsInWhyNow = !!cells[4].querySelector('.queue-details');
            const detailsInProduct = !!cells[1].querySelector('.queue-details');
            const midY = rect => (rect.top + rect.bottom) / 2;
            return {
                rowHeight: row.getBoundingClientRect().height,
                productSkuSameLine: Math.abs(midY(productRect) - midY(skuRect)) < 12 && skuRect.left >= productRect.right - 2,
                onHandInline: Math.abs(midY(onHandNum) - midY(onHandUnit)) < 12 && onHandUnit.left >= onHandNum.right - 2,
                suggestedInline: Math.abs(midY(suggestedNum) - midY(suggestedUnit)) < 12 && suggestedUnit.left >= suggestedNum.right - 2,
                detailsInWhyNow,
                detailsInProduct,
            };
        });
        assert.equal(rowLayout.detailsInWhyNow, true, 'Forecast details must be inside Why now column');
        assert.equal(rowLayout.detailsInProduct, false, 'Forecast details must not be inside Product column');
        assert.equal(rowLayout.productSkuSameLine, true, 'Product name and SKU must be inline on same line');
        assert.equal(rowLayout.onHandInline, true, 'On hand number and units must be inline');
        assert.equal(rowLayout.suggestedInline, true, 'Suggested number and units must be inline');
        assert.ok(rowLayout.rowHeight <= 64, `Compact row height expected <= 64px, got ${rowLayout.rowHeight}`);
        assert.equal(await page.locator('.queue-item').evaluateAll(rows => rows.every(r => r.getBoundingClientRect().height <= 64)), true, 'All collapsed rows must stay compact single-line');

        // Forecast details disclosure expansion reveals basis, explanation, readiness status, and stale indicator
        const watchRow = page.locator('.queue-item').filter({ hasText: 'Watch product' });
        await watchRow.locator('.queue-details summary').click();
        const detailsText = await watchRow.locator('.queue-details').innerText();
        assert.match(detailsText, /Demand Forecast/);
        assert.match(detailsText, /High readiness forecast/);
        assert.match(detailsText, /Stale forecast: 2026-10-01/);
        await watchRow.locator('.queue-details summary').click();

        // Sticky table header inside scrollable container
        const stickyCheck = await page.evaluate(() => {
            const container = document.querySelector('.queue-list');
            const th = container.querySelector('thead th');
            const beforeTop = th.getBoundingClientRect().top;
            container.scrollTop = 200;
            const afterTop = th.getBoundingClientRect().top;
            return {
                scrolled: container.scrollTop > 0,
                stickyPosition: getComputedStyle(th).position,
                topDelta: Math.abs(afterTop - beforeTop),
            };
        });
        assert.equal(stickyCheck.stickyPosition, 'sticky');
        assert.equal(stickyCheck.scrolled, true, 'Table container must be vertically scrollable');
        assert.ok(stickyCheck.topDelta <= 1, 'Sticky table header must stay pinned at top when scrolling');

        // Summary cards filter and toggle reset
        const expectedCards = [
            ['Out of stock', '1', ['Needs stock']],
            ['Low stock', '2', ['Unknown product', 'Watch product']],
            ['In stock', '27', ['Covered product']],
        ];
        for (const [status, count, products] of expectedCards) {
            const card = page.getByRole('link', { name: `Show ${status} products` });
            assert.equal(await card.locator('.queue-stat-number').innerText(), count);
            await page.locator('#queue-search').fill('no match');
            await card.click();
            assert.equal(await card.getAttribute('aria-current'), 'true');
            assert.equal(await page.locator('#queue-stock').inputValue(), status);
            assert.deepEqual((await page.locator('.queue-item:visible .queue-product').allTextContents()).slice(0, products.length), products);
            assert.equal(await page.locator('#queue-search').inputValue(), '');
            // Second click on active card clears filter
            await card.click();
            assert.equal(await card.getAttribute('aria-current'), null);
            assert.equal(await page.locator('#queue-stock').inputValue(), '');
            assert.equal(await page.locator('.queue-item:visible').count(), 30);
            assert.equal(await page.locator('#queue-results').innerText(), 'Showing 30 of 30 products');
        }
        await page.locator('#queue-sort').selectOption('name');
        await page.locator('#queue-sort').selectOption('priority');
        assert.deepEqual((await page.locator('.queue-item:visible .queue-product').allTextContents()).slice(0, 4), ['Needs stock', 'Unknown product', 'Watch product', 'Covered product']);
        const outOfStock = page.getByRole('link', { name: 'Show Out of stock products' });
        await outOfStock.focus();
        await page.keyboard.press('Enter');
        assert.deepEqual(await page.locator('.queue-item:visible .queue-product').allTextContents(), ['Needs stock']);
        assert.equal(await outOfStock.getAttribute('aria-current'), 'true');
        await outOfStock.focus();
        await page.keyboard.press('Enter');
        assert.equal(await outOfStock.getAttribute('aria-current'), null);
        assert.equal(await page.locator('.queue-item:visible').count(), 30);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByRole('link', { name: 'Show In stock products' }).click();
        assert.equal((await page.locator('.queue-item:visible .queue-product').allTextContents())[0], 'Covered product');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal((await page.locator('.queue-item').first().locator('td').nth(5).innerText()).trim(), 'View only');
        assert.equal(await page.locator('.queue-receive').count(), 0);

        const managerHtml = execFileSync('php', ['-r', `
            ob_start();
            require 'src/backend/tests/replenishment_metadata_contract.php';
            ob_end_clean();
            $GLOBALS['test_can_receive'] = true;
            $pdo->exec("CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
                UPDATE products SET status='active';
                INSERT INTO inventory VALUES (1, 0)");
            echo renderReplenishmentPage($pdo, 'replenishment_queue');
        `], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.setContent(managerHtml.replace(/<link[^>]+>/g, '').replace(/<script[^>]*>.*?<\/script>/gs, ''));
        await page.addStyleTag({ path: path.join(root, 'src/frontend/assets/css/style.css') });
        await page.addStyleTag({ path: path.join(root, 'src/frontend/assets/css/replenishment.css') });
        const receiveLink = page.locator('.queue-item').first().locator('a.queue-receive');
        assert.match(await receiveLink.innerText(), /Add bought goods/);
        assert.match(await receiveLink.getAttribute('href'), /stock_receiving\.php\?product_id=1/);
        assert.ok((await page.locator('.queue-item').first().boundingBox()).height <= 64);
        console.log('Replenishment cards filter matching products with mouse and keyboard on desktop and mobile.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
