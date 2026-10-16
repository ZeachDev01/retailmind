'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');

const swSource = fs.readFileSync(path.join(__dirname, '../../frontend/sw.js'), 'utf8');
const registerSource = fs.readFileSync(path.join(__dirname, '../../frontend/assets/js/sw-register.js'), 'utf8');

(async () => {
    let swVersion = 'v1';
    let assetHits = 0;
    let cashierNavHits = 0;

    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://' + req.headers.host);

        // Service Worker script endpoint
        if (url.pathname === '/sw.js') {
            res.writeHead(200, {
                'Content-Type': 'application/javascript; charset=utf-8',
                'Service-Worker-Allowed': '/',
                'Cache-Control': 'no-cache, no-store, must-revalidate',
            });
            res.end(`// SW Version: ${swVersion}\n${swSource}`);
            return;
        }

        // SW Register script endpoint
        if (url.pathname === '/assets/js/sw-register.js') {
            res.writeHead(200, {
                'Content-Type': 'application/javascript; charset=utf-8',
                'Cache-Control': 'public, max-age=3600',
            });
            res.end(registerSource);
            return;
        }

        // Static asset endpoint (Cache-First test)
        if (url.pathname === '/assets/css/style.css') {
            assetHits++;
            res.writeHead(200, {
                'Content-Type': 'text/css; charset=utf-8',
                'Cache-Control': 'public, max-age=31536000',
            });
            res.end('body { margin: 0; background: #f8fafc; }');
            return;
        }

        // Navigation HTML shell endpoints
        const shellTitles = {
            '/': 'RetailMind Landing Shell',
            '/components/cashier/': 'Cashier POS Shell',
            '/components/inventory_management/': 'Inventory Management Shell',
            '/components/administrator/': 'Administrator Dashboard Shell',
        };

        if (url.pathname in shellTitles) {
            if (url.pathname === '/components/cashier/') {
                cashierNavHits++;
            }
            const title = shellTitles[url.pathname];
            res.writeHead(200, {
                'Content-Type': 'text/html; charset=utf-8',
            });
            res.end(`<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>${title}</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <h1>${title}</h1>
    <div id="workspace-content">Active workspace operational view</div>
    <input id="cashier-session-input" placeholder="Scan or enter item" value="">
    <script src="/assets/js/sw-register.js" data-sw="/sw.js" data-scope="/" defer></script>
</body>
</html>`);
            return;
        }

        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('Not Found');
    });

    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    try {
        // --- 1. Root Service Worker Registration with scope '/' ---
        await page.goto(origin + '/');
        await page.waitForLoadState('networkidle');

        // Wait until service worker is activated and controlling
        await page.waitForFunction(async () => {
            const reg = await navigator.serviceWorker.getRegistration('/');
            return reg && reg.active && reg.active.state === 'activated';
        });

        const rootScope = await page.evaluate(async () => {
            const reg = await navigator.serviceWorker.getRegistration('/');
            return reg ? reg.scope : null;
        });
        assert.equal(rootScope, origin + '/', 'Root Service Worker registered with scope /');

        // Verify scope covers cashier, inventory, and administrator workspaces
        const workspaceScopes = await page.evaluate(async () => {
            const cashier = await navigator.serviceWorker.getRegistration('/components/cashier/');
            const inventory = await navigator.serviceWorker.getRegistration('/components/inventory_management/');
            const admin = await navigator.serviceWorker.getRegistration('/components/administrator/');
            return {
                cashierScope: cashier?.scope,
                inventoryScope: inventory?.scope,
                adminScope: admin?.scope,
            };
        });
        assert.equal(workspaceScopes.cashierScope, origin + '/', 'Covers /components/cashier/');
        assert.equal(workspaceScopes.inventoryScope, origin + '/', 'Covers /components/inventory_management/');
        assert.equal(workspaceScopes.adminScope, origin + '/', 'Covers /components/administrator/');

        // --- 2. Versioned static assets served Cache-First ---
        const initialAssetHits = assetHits;
        assert.ok(initialAssetHits >= 1, 'Static CSS was fetched at least once on page load');

        // Fetch the static asset again from client
        await page.evaluate(async () => {
            const res = await fetch('/assets/css/style.css');
            return res.text();
        });

        // Hit count must not increase because it was served Cache-First
        assert.equal(assetHits, initialAssetHits, 'Static asset was served from cache without network roundtrip');

        // --- 3. Navigation HTML shell caching and offline navigation ---
        await page.goto(origin + '/components/cashier/');
        await page.waitForLoadState('networkidle');
        assert.equal(await page.textContent('h1'), 'Cashier POS Shell');

        // Verify shell is stored in retailmind-shell-v1 cache
        const isCached = await page.evaluate(async () => {
            const cache = await caches.open('retailmind-shell-v1');
            const match = await cache.match('/components/cashier/');
            return !!match;
        });
        assert.ok(isCached, 'Cashier HTML shell is cached in retailmind-shell-v1');

        // Simulate complete offline network
        await context.setOffline(true);

        // Navigate to cashier shell while completely offline
        await page.goto(origin + '/components/cashier/');
        assert.equal(await page.textContent('h1'), 'Cashier POS Shell', 'Offline navigation successfully loads cached shell');

        // Restore network connectivity
        await context.setOffline(false);

        // --- 4. Service Worker lifecycle updates & non-blocking notification banner ---
        // Simulate active Cashier shift input
        await page.fill('#cashier-session-input', 'active-cart-sku-555');

        // Deploy new service worker version on server
        swVersion = 'v2';

        // Trigger background update check
        await page.evaluate(async () => {
            const reg = await navigator.serviceWorker.getRegistration('/');
            if (reg) {
                await reg.update();
            }
        });

        // Banner should appear without reloading page
        const bannerLocator = page.locator('#sw-update-banner');
        await bannerLocator.waitFor({ state: 'visible', timeout: 5000 });

        const bannerText = await bannerLocator.textContent();
        assert.ok(
            bannerText.includes('Update available') && bannerText.includes('reload'),
            'Update banner displays non-blocking notification ("Update available — Tap to reload")'
        );

        // Verify active cashier cart state was NOT lost to forced reload
        const currentInputValue = await page.inputValue('#cashier-session-input');
        assert.equal(currentInputValue, 'active-cart-sku-555', 'Page did not force an automatic reload during active session');

        // User taps reload on the banner
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'load' }),
            page.click('#sw-update-reload-btn'),
        ]);

        // After reload, verify new worker is active
        const newWorkerState = await page.evaluate(async () => {
            const reg = await navigator.serviceWorker.getRegistration('/');
            return reg && reg.active ? reg.active.state : null;
        });
        assert.equal(newWorkerState, 'activated', 'New service worker activated after user tapped reload');

        console.log('Service worker browser test: passed');
    } finally {
        await page.close();
        await context.close();
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {
    console.error('Service worker browser test failed:', error);
    process.exit(1);
});
