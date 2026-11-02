const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../../..');
const html = execFileSync('php', ['-r', `
    ob_start();
    require 'src/backend/tests/replenishment_metadata_contract.php';
    ob_end_clean();
    $pdo->exec("CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
        UPDATE products SET status='active'; INSERT INTO inventory VALUES (1, 0);
        DELETE FROM demand_forecasts;
        UPDATE platform_settings SET setting_value='v1' WHERE setting_key='ml_approved_model_version';
        INSERT INTO products VALUES (2,'ZERO','Zero forecast','active',7,1,9,8,6);
        INSERT INTO inventory VALUES (2,8)");
    $insert = $pdo->prepare('INSERT INTO demand_forecasts VALUES (?,10,?,?,?,?,?,?)');
    $origin = (new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    foreach ([1,2] as $id) {
        for ($day=1; $day<=28; $day++) $insert->execute([($id-1)*28+$day,$id,$origin,$day,$id===1?2:0,'v1','High']);
    }
    for ($id=3; $id<=30; $id++) {
        $pdo->exec("INSERT INTO products VALUES ($id,'SKU-$id','Product $id','active',7,1,10,5,1);
            INSERT INTO inventory VALUES ($id,20)");
    }
    echo renderReplenishmentPage($pdo,'replenishment_queue');
`], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        await page.route('http://retailmind.test/**', route => route.fulfill({
            contentType: 'text/html', body: html.replace(/<link[^>]+>/g, '').replace(/<script[^>]*>.*?<\/script>/gs, ''),
        }));
        await page.goto('http://retailmind.test/');
        assert.doesNotMatch(await page.locator('main').textContent(), /Non-binding buying guidance|Stock rules suggest how much to buy\. Expected sales estimate customer purchases\. The Inventory Manager decides how much to buy\./);
        for (const name of ['theme', 'style', 'replenishment']) await page.addStyleTag({ path: path.join(root, `src/frontend/assets/css/${name}.css`) });
        await page.evaluate(() => sessionStorage.setItem('retailmind_replenishment_snapshot_v2', JSON.stringify({ items: [{ html: '<tr><td>Old suggested layout</td></tr>' }] })));
        await page.addScriptTag({ path: path.join(root, 'src/frontend/assets/js/replenishment.js') });
        const headers = await page.locator('.queue-list th').allTextContents();
        assert.equal(headers.length, 4);
        assert.match(headers[0], /Product/);
        assert.match(headers[1], /On hand/);
        assert.match(headers[2], /Stock-rule suggestion/);
        assert.match(headers[3], /Expected sales/);
        const first = page.locator('.queue-item').filter({ hasText: 'Needs stock' });
        assert.equal(await page.getByText('Stock-rule details', { exact: true }).count(), 0);
        const suggestion = first.locator('td').nth(2).getByRole('button');
        const stockHelp = first.locator('td').nth(2).getByRole('tooltip', { includeHidden: true });
        assert.equal(await stockHelp.isVisible(), false);
        await suggestion.hover();
        assert.equal(await stockHelp.isVisible(), true);
        assert.equal((await stockHelp.innerText()).trim(), 'Reorder level: 10 units + Safety stock: 5 units');
        fs.mkdirSync(path.join(root, '.scratch/replenishment-comparison'), { recursive: true });
        await page.screenshot({ path: path.join(root, '.scratch/replenishment-comparison/stock-tooltip.png'), fullPage: true });
        await page.mouse.move(0, 0);
        assert.equal(await stockHelp.isVisible(), false);
        await suggestion.focus();
        assert.equal(await stockHelp.isVisible(), true);
        assert.equal(await suggestion.getAttribute('aria-describedby'), await stockHelp.getAttribute('id'));
        await page.keyboard.press('Escape');
        assert.equal(await stockHelp.isVisible(), false);
        await page.locator('#queue-search').focus();
        assert.deepEqual((await first.locator('td').allInnerTexts()).slice(1).map(s => s.trim().replace(/\s+/g, ' ')).slice(0, 2), ['0 units', '15 units']);
        assert.match(await first.locator('td').nth(3).textContent(), /56/);
        const zero = page.locator('.queue-item').filter({ hasText: 'Zero forecast' });
        await zero.locator('td').nth(2).getByRole('button').hover();
        assert.equal((await zero.getByRole('tooltip').innerText()).trim(), 'Reorder level: 9 units + Safety stock: 8 units');
        await page.mouse.move(0, 0);
        assert.match(await zero.locator('td').nth(2).textContent(), /12 units/);
        assert.match(await zero.locator('td').nth(3).textContent(), /0 units/);
        assert.equal(await page.locator('body').textContent().then(text => text.includes('Old suggested layout')), false);
        await page.locator('#queue-search').fill('SKU-3');
        assert.equal(await page.locator('.queue-item:visible').count(), 2);
        assert.equal((await page.locator('.queue-item:visible').first().locator('td').nth(3).textContent()).trim(), 'Not available yet');
        await page.locator('#queue-search').fill('');
        await page.locator('#queue-stock').selectOption('needs_replenishment');
        assert.equal(await page.locator('.queue-item:visible').count(), 2);
        await page.locator('#queue-stock').selectOption('');
        await page.locator('#queue-sort').selectOption('quantity');
        assert.match(await page.locator('.queue-item:visible').first().textContent(), /Needs stock/);
        await page.locator('#queue-page-size').selectOption('50');
        const identityLayout = await page.locator('.queue-item').evaluateAll(rows => rows.map(row => {
            const name = row.querySelector('.queue-product').getBoundingClientRect();
            const sku = row.querySelector('.queue-sku').getBoundingClientRect();
            const badge = row.querySelector('.queue-priority').getBoundingClientRect();
            return { nameBottom: name.bottom, skuTop: sku.top, skuRight: sku.right, badgeLeft: badge.left, badgeRight: badge.right };
        }));
        assert.ok(identityLayout.every(row => row.skuTop >= row.nameBottom - 1), 'SKU must sit beneath the product name');
        assert.ok(identityLayout.every(row => row.skuRight < row.badgeLeft), 'Product identity must stay separate from stock status');
        assert.ok(identityLayout.every(row => Math.abs(row.badgeRight - identityLayout[0].badgeRight) <= 1), 'Stock badges must share a stable column');
        await first.locator('.queue-details summary').click();
        assert.equal(await first.locator('.queue-details').evaluate(details => {
            const content = details.getBoundingClientRect();
            const cell = details.parentElement.getBoundingClientRect();
            return content.left >= cell.left && content.right <= cell.right;
        }), true, 'Expanded forecast details must stay within the Product column');
        await first.locator('.queue-details summary').click();
        await page.emulateMedia({ media: 'print' });
        assert.equal(await first.locator('td').nth(3).isVisible(), true, 'Printing must retain expected sales');
        await page.emulateMedia({ media: 'screen' });
        await page.locator('#queue-actions-trigger').focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('#queue-recalculate').isVisible(), true);
        assert.equal(await page.locator('#queue-recalculate').evaluate(el => el === document.activeElement), true);
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#queue-actions-trigger').getAttribute('aria-expanded'), 'false');
        assert.equal(await page.locator('#queue-actions-trigger').evaluate(el => el === document.activeElement), true);
        await page.screenshot({ path: path.join(root, '.scratch/replenishment-comparison/desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(root, '.scratch/replenishment-comparison/mobile.png'), fullPage: true });
        await suggestion.focus();
        assert.equal(await stockHelp.isVisible(), true, 'Mobile keyboard focus must reveal stock rules');
        const helpBounds = await stockHelp.boundingBox();
        const listBounds = await page.locator('.queue-list').boundingBox();
        assert.ok(helpBounds.x >= listBounds.x && helpBounds.x + helpBounds.width <= listBounds.x + listBounds.width, 'Mobile stock tooltip must fit inside the scroll region');
        await page.screenshot({ path: path.join(root, '.scratch/replenishment-comparison/mobile-stock-tooltip.png'), fullPage: true });
        const overflow = await page.locator('.queue-list').evaluate(el => ({ scroll: el.scrollWidth, client: el.clientWidth, overflow: getComputedStyle(el).overflowX }));
        assert.ok(overflow.scroll > overflow.client && overflow.overflow === 'auto', 'Mobile table must scroll within its region');
        await first.locator('.queue-product').evaluate(el => { el.textContent = 'Long product name '.repeat(12); });
        for (const width of [390, 768, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Page must contain overflow at ${width}px`);
            assert.equal(await page.locator('.queue-item[data-name="Needs stock"] .queue-product-cell').evaluate(cell => {
                const identity = cell.querySelector('.queue-product-identity').getBoundingClientRect();
                const badge = cell.querySelector('.queue-priority').getBoundingClientRect();
                return identity.right < badge.left;
            }), true, `Long product names must not overlap stock status at ${width}px`);
        }
        await page.setViewportSize({ width: 768, height: 1000 });
        await page.screenshot({ path: path.join(root, '.scratch/replenishment-comparison/tablet-long-name.png'), fullPage: true });
        await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; document.documentElement.style.colorScheme = 'dark'; });
        await page.screenshot({ path: path.join(root, '.scratch/replenishment-comparison/tablet-dark.png'), fullPage: true, animations: 'disabled' });
        console.log('Replenishment comparison browser checks passed (four columns, independent quantities, zero/unavailable, filters, cache, print, mobile).');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
