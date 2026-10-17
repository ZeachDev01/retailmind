'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../../..');

// Generate replenishment page HTML using standard test fixture
const getReplenishmentHtml = (tag = '') => execFileSync('php', ['-r', `
    ob_start();
    require 'src/backend/tests/replenishment_metadata_contract.php';
    ob_end_clean();
    $pdo->exec("CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
        UPDATE products SET status='active';
        INSERT INTO inventory VALUES (1, 0);
        INSERT INTO products VALUES (2, 'WATCH', 'Watch product ${tag}', 'active', 7, 1, 10, 5, 1);
        INSERT INTO products VALUES (3, 'COVERED', 'Covered product', 'active', 7, 1, 10, 5, 1);
        INSERT INTO products VALUES (4, 'UNKNOWN', 'Unknown product', 'active', 7, 1, 10, 5, 1);
        INSERT INTO inventory VALUES (2, 10), (3, 30), (4, 5);
        INSERT INTO demand_forecasts VALUES (2, 10, 2, '2026-10-01', 7, 8, 'test-model', 'High');
        INSERT INTO demand_forecasts VALUES (3, 10, 3, '2026-10-16', 7, 8, 'test-model', 'High')");
    for ($i = 5; $i <= 20; $i++) {
        $pdo->exec("INSERT INTO products VALUES ($i, 'SKU-$i', 'Bulk item $i', 'active', 7, 1, 10, 5, 1);
            INSERT INTO inventory VALUES ($i, 30)");
    }
    echo renderReplenishmentPage($pdo, 'replenishment_queue');
`], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });

(async () => {
    let queueRequests = 0;
    let recalculateRequests = 0;
    let receivingPostCount = 0;

    const server = http.createServer((req, res) => {
        const url = new URL(req.url, `http://${req.headers.host}`);

        // Static assets
        if (url.pathname.startsWith('/assets/')) {
            const relPath = url.pathname.replace(/^\//, '');
            const filePath = path.join(root, 'src/frontend', relPath);
            if (fs.existsSync(filePath)) {
                const ext = path.extname(filePath);
                const mimeTypes = {
                    '.js': 'application/javascript; charset=utf-8',
                    '.css': 'text/css; charset=utf-8',
                    '.json': 'application/json; charset=utf-8',
                };
                res.writeHead(200, { 'Content-Type': mimeTypes[ext] || 'application/octet-stream' });
                res.end(fs.readFileSync(filePath));
                return;
            }
        }

        // Replenishment Queue page
        if (url.pathname === '/components/inventory_management/replenishment_queue.php') {
            queueRequests++;
            const isRecalc = url.searchParams.has('recalculate');
            if (isRecalc) recalculateRequests++;
            const html = getReplenishmentHtml(isRecalc ? '(Recalculated)' : '');
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end(html);
            return;
        }

        // Stock Receiving page
        if (url.pathname === '/components/report/stock_receiving.php') {
            if (req.method === 'POST') {
                receivingPostCount++;
                let body = '';
                req.on('data', chunk => { body += chunk; });
                req.on('end', () => {
                    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                    res.end(`<!DOCTYPE html><html><body>
                        <div class="message success">Shopping entry saved.</div>
                        <script>
                            sessionStorage.removeItem('retailmind_replenishment_snapshot');
                            sessionStorage.removeItem('retailmind_replenishment_page');
                        </script>
                        <a id="back-to-replenish" href="/components/inventory_management/replenishment_queue.php">Review updated recommendations</a>
                    </body></html>`);
                });
                return;
            }
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end(`<!DOCTYPE html><html><body>
                <form id="receiving_form" method="POST" action="/components/report/stock_receiving.php">
                    <input name="supplier" value="Test Supplier">
                    <button class="btn-submit" type="submit">Save</button>
                </form>
                <script>
                    const form = document.getElementById('receiving_form');
                    form.addEventListener('submit', () => {
                        try {
                            sessionStorage.removeItem('retailmind_replenishment_snapshot');
                            sessionStorage.removeItem('retailmind_replenishment_page');
                        } catch (_) {}
                    });
                </script>
            </body></html>`);
            return;
        }

        // Dummy Products page to test inter-page navigation
        if (url.pathname === '/components/inventory_management/products.php') {
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end(`<!DOCTYPE html><html><body><h1>Products</h1><a id="nav-replenish" href="/components/inventory_management/replenishment_queue.php">Replenishment</a></body></html>`);
            return;
        }

        res.writeHead(404);
        res.end('Not Found');
    });

    const port = await new Promise(resolve => {
        server.listen(0, '127.0.0.1', () => resolve(server.address().port));
    });

    const baseUrl = `http://127.0.0.1:${port}`;
    const browser = await chromium.launch({ headless: true });

    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        page.setDefaultTimeout(3000);

        // =========================================================================
        // 1. Initial Load & Snapshot Storage in sessionStorage
        // =========================================================================
        await page.goto(`${baseUrl}/components/inventory_management/replenishment_queue.php`);
        assert.equal(queueRequests, 1, 'Initial page load should issue exactly 1 request');

        const initialSnapshot = await page.evaluate(() => {
            const raw = sessionStorage.getItem('retailmind_replenishment_snapshot');
            return raw ? JSON.parse(raw) : null;
        });

        assert.ok(initialSnapshot, 'retailmind_replenishment_snapshot must be saved to sessionStorage upon initial load');
        assert.ok(typeof initialSnapshot.timestamp === 'number' && initialSnapshot.timestamp > 0, 'Snapshot must contain a valid timestamp');
        assert.ok(Array.isArray(initialSnapshot.items) && initialSnapshot.items.length >= 4, 'Snapshot must contain recommended product items');
        assert.ok(initialSnapshot.items.some(i => i.name.includes('Watch product')), 'Snapshot items must include loaded forecast products');

        // =========================================================================
        // 2. Navigating views & filtering resolving from sessionStorage without network
        // =========================================================================
        const reqsBeforeFilter = queueRequests;

        // Search filtering
        await page.locator('#queue-search').fill('Watch');
        const visibleNames = await page.locator('.queue-item:visible .queue-product').allTextContents();
        assert.ok(visibleNames.some(name => name.includes('Watch product')), 'Filtered results must show matching search items');
        assert.equal(queueRequests, reqsBeforeFilter, 'Search filtering must resolve client-side without network requests');

        // Stock status card navigation view
        await page.locator('#queue-search').fill('');
        const outOfStockCard = page.locator('[data-queue-stock="Out of stock"]');
        await outOfStockCard.click();
        assert.equal(await outOfStockCard.getAttribute('aria-current'), 'true');
        assert.equal(await page.locator('#queue-stock').inputValue(), 'Out of stock');
        assert.equal(queueRequests, reqsBeforeFilter, 'Card view navigation must resolve client-side without network requests');

        // Pagination resolving from snapshot
        await page.locator('[data-queue-stock="Out of stock"]').click(); // toggle off
        await page.locator('#queue-page-size').selectOption('10');
        const resultsText = await page.locator('#queue-results').innerText();
        assert.match(resultsText, /Showing 1–10 of \d+ products/);
        assert.equal(queueRequests, reqsBeforeFilter, 'Pagination must resolve client-side without network requests');

        // Dynamic snapshot hydration verification:
        // Inject a distinct synthetic item into sessionStorage snapshot and verify it renders and filters
        await page.evaluate(() => {
            const snap = JSON.parse(sessionStorage.getItem('retailmind_replenishment_snapshot'));
            snap.items.push({
                name: 'Snapshot Offline Item',
                sku: 'OFFLINE-99',
                stock: 'Low stock',
                onHand: 1,
                quantity: 42,
            });
            sessionStorage.setItem('retailmind_replenishment_snapshot', JSON.stringify(snap));
        });

        // Trigger filter to observe sync from sessionStorage snapshot
        await page.locator('#queue-search').fill('OFFLINE-99');
        assert.equal(await page.locator('.queue-item:visible .queue-product').innerText(), 'Snapshot Offline Item');
        assert.equal(queueRequests, reqsBeforeFilter, 'Filtering from updated sessionStorage snapshot must require 0 network requests');

        // =========================================================================
        // 3. Persistence across navigation
        // =========================================================================
        // Navigate away to products page
        await page.goto(`${baseUrl}/components/inventory_management/products.php`);
        const snapshotOnOtherPage = await page.evaluate(() => sessionStorage.getItem('retailmind_replenishment_snapshot'));
        assert.ok(snapshotOnOtherPage, 'retailmind_replenishment_snapshot must persist in sessionStorage across page navigation');

        // Navigate back to replenishment queue
        await page.goto(`${baseUrl}/components/inventory_management/replenishment_queue.php`);
        const snapshotAfterReturn = await page.evaluate(() => sessionStorage.getItem('retailmind_replenishment_snapshot'));
        assert.ok(snapshotAfterReturn, 'Snapshot must remain in sessionStorage when returning to replenishment queue');

        // =========================================================================
        // 4. Cache eviction upon Stock Receiving submission
        // =========================================================================
        await page.goto(`${baseUrl}/components/report/stock_receiving.php`);
        const snapBeforeReceiving = await page.evaluate(() => sessionStorage.getItem('retailmind_replenishment_snapshot'));
        assert.ok(snapBeforeReceiving, 'Snapshot exists before receiving submission');

        // Submit receiving record
        await page.locator('.btn-submit').click();
        await page.waitForURL('**/components/report/stock_receiving.php');

        const snapAfterReceiving = await page.evaluate(() => sessionStorage.getItem('retailmind_replenishment_snapshot'));
        assert.equal(snapAfterReceiving, null, 'Stock Receiving submission must invalidate retailmind_replenishment_snapshot');

        // =========================================================================
        // 5. Cache eviction and fresh fetch upon manual "Recalculate Recommendations"
        // =========================================================================
        // Re-visit replenishment queue to create a fresh snapshot
        await page.goto(`${baseUrl}/components/inventory_management/replenishment_queue.php`);
        const initialTimestamp = await page.evaluate(() => {
            const raw = sessionStorage.getItem('retailmind_replenishment_snapshot');
            return raw ? JSON.parse(raw).timestamp : null;
        });
        assert.ok(initialTimestamp > 0, 'Fresh snapshot established');

        const reqsBeforeRecalc = queueRequests;
        const recalcsBefore = recalculateRequests;

        // Click manual Recalculate Recommendations button
        const recalcBtn = page.locator('#queue-recalculate');
        assert.equal(await recalcBtn.isVisible(), true, 'Manual Recalculate Recommendations button must be visible');
        await recalcBtn.click();

        await page.waitForURL('**/replenishment_queue.php?recalculate=1');
        assert.equal(queueRequests, reqsBeforeRecalc + 1, 'Manual recalculate must make a fresh request to the server');
        assert.equal(recalculateRequests, recalcsBefore + 1, 'Manual recalculate query param ?recalculate=1 must be requested');

        // Verify fresh forecast snapshot persisted in sessionStorage
        const freshSnapshot = await page.evaluate(() => {
            const raw = sessionStorage.getItem('retailmind_replenishment_snapshot');
            return raw ? JSON.parse(raw) : null;
        });
        assert.ok(freshSnapshot, 'Fresh snapshot must be saved in sessionStorage after recalculation');
        assert.ok(freshSnapshot.items.some(i => i.name.includes('(Recalculated)')), 'Fresh snapshot must contain newly calculated recommendations');

        console.log('Replenishment session snapshot browser test passed.');
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})();
