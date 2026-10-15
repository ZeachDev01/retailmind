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
        INSERT INTO demand_forecasts VALUES (2, 10, 2, '2026-10-16', 7, 8, 'test-model', 'High');
        INSERT INTO demand_forecasts VALUES (3, 10, 3, '2026-10-16', 7, 8, 'test-model', 'High')");
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
        assert.deepEqual(await page.locator('.queue-priority').allTextContents(), ['Out of stock', 'Low stock', 'Low stock', 'In stock']);
        assert.equal(await page.locator('select option').evaluateAll(options => options.some(option => /Urgent|Watch|Covered|Unknown|Urgency/i.test(option.textContent))), false);
        await page.addScriptTag({ path: path.join(root, 'src/frontend/assets/js/replenishment.js') });
        const expectedCards = [
            ['Out of stock', '1', ['Needs stock']],
            ['Low stock', '2', ['Unknown product', 'Watch product']],
            ['In stock', '1', ['Covered product']],
        ];
        for (const [status, count, products] of expectedCards) {
            const card = page.getByRole('link', { name: `Show ${status} products` });
            assert.equal(await card.locator('.queue-stat-number').innerText(), count);
            await page.locator('#queue-search').fill('no match');
            await page.locator('#queue-stock').selectOption('In stock');
            await card.click();
            assert.equal(await page.locator('#queue-stock').inputValue(), status);
            assert.deepEqual(await page.locator('.queue-item:visible .queue-product').allTextContents(), products);
            assert.equal(await page.locator('#queue-search').inputValue(), '');
        }
        await page.locator('#queue-stock').selectOption('');
        await page.locator('#queue-sort').selectOption('name');
        await page.locator('#queue-sort').selectOption('priority');
        assert.deepEqual(await page.locator('.queue-item:visible .queue-product').allTextContents(), ['Needs stock', 'Unknown product', 'Watch product', 'Covered product']);
        const outOfStock = page.getByRole('link', { name: 'Show Out of stock products' });
        await outOfStock.focus();
        await page.keyboard.press('Enter');
        assert.deepEqual(await page.locator('.queue-item:visible .queue-product').allTextContents(), ['Needs stock']);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByRole('link', { name: 'Show In stock products' }).click();
        assert.deepEqual(await page.locator('.queue-item:visible .queue-product').allTextContents(), ['Covered product']);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        console.log('Replenishment cards filter matching products with mouse and keyboard on desktop and mobile.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
