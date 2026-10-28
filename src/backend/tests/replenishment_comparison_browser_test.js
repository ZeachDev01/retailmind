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
        INSERT INTO products VALUES (2,'ZERO','Zero forecast','active',7,1,10,5,6);
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
        for (const name of ['style', 'replenishment']) await page.addStyleTag({ path: path.join(root, `src/frontend/assets/css/${name}.css`) });
        await page.evaluate(() => sessionStorage.setItem('retailmind_replenishment_snapshot_v2', JSON.stringify({ items: [{ html: '<tr><td>Old suggested layout</td></tr>' }] })));
        await page.addScriptTag({ path: path.join(root, 'src/frontend/assets/js/replenishment.js') });
        const headers = await page.locator('.queue-list th').allTextContents();
        assert.equal(headers.length, 4);
        assert.match(headers[0], /Product/);
        assert.match(headers[1], /On hand/);
        assert.match(headers[2], /Stock-rule suggestion/);
        assert.match(headers[3], /Expected sales/);
        const first = page.locator('.queue-item').filter({ hasText: 'Needs stock' });
        assert.deepEqual((await first.locator('td').allTextContents()).slice(1).map(s => s.trim().replace(/\s+/g, ' ')).slice(0, 2), ['0 units', '15 units']);
        assert.match(await first.locator('td').nth(3).textContent(), /56/);
        const zero = page.locator('.queue-item').filter({ hasText: 'Zero forecast' });
        assert.match(await zero.locator('td').nth(2).textContent(), /12 units/);
        assert.match(await zero.locator('td').nth(3).textContent(), /0 units/);
        assert.equal(await page.locator('body').textContent().then(text => text.includes('Old suggested layout')), false);
        await page.locator('#queue-search').fill('SKU-3');
        assert.equal(await page.locator('.queue-item:visible').count(), 2);
        assert.equal((await page.locator('.queue-item:visible').first().locator('td').nth(3).textContent()).trim(), 'Unavailable');
        await page.locator('#queue-search').fill('');
        await page.locator('#queue-stock').selectOption('needs_replenishment');
        assert.equal(await page.locator('.queue-item:visible').count(), 2);
        await page.locator('#queue-stock').selectOption('');
        await page.locator('#queue-sort').selectOption('quantity');
        assert.match(await page.locator('.queue-item:visible').first().textContent(), /Needs stock/);
        await page.locator('#queue-page-size').selectOption('50');
        await page.emulateMedia({ media: 'print' });
        assert.equal(await first.locator('td').nth(3).isVisible(), true, 'Printing must retain expected sales');
        await page.emulateMedia({ media: 'screen' });
        fs.mkdirSync(path.join(root, '.scratch/replenishment-comparison'), { recursive: true });
        await page.screenshot({ path: path.join(root, '.scratch/replenishment-comparison/desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(root, '.scratch/replenishment-comparison/mobile.png'), fullPage: true });
        const overflow = await page.locator('.queue-list').evaluate(el => ({ scroll: el.scrollWidth, client: el.clientWidth, overflow: getComputedStyle(el).overflowX }));
        assert.ok(overflow.scroll > overflow.client && overflow.overflow === 'auto', 'Mobile table must scroll within its region');
        console.log('Replenishment comparison browser checks passed (four columns, independent quantities, zero/unavailable, filters, cache, print, mobile).');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
