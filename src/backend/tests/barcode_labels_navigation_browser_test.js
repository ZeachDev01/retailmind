const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const products = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
    const link = products.match(/<a\b[^>]*>[^\n]*?<strong>Barcode Labels<\/strong><\/a>/)[0]
        .replace(/<\?=[\s\S]*?\?>/, '/print_barcodes.php');
    const navigation = fs.readFileSync(path.join(frontend, 'assets/js/page-navigation.js'), 'utf8');
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const requests = [];
            await page.route('http://labels.test/**', route => {
                const url = new URL(route.request().url());
                if (url.pathname === '/navigation.js') {
                    return route.fulfill({ contentType: 'text/javascript', body: navigation });
                }
                if (url.pathname === '/print_barcodes.php') {
                    requests.push(route.request().headers());
                    // Printable labels are a standalone HTML document, outside the workspace shell.
                    return route.fulfill({ contentType: 'text/html', body: '<main class="barcode-page"><h1>Printable Barcode Labels</h1></main>' });
                }
                return route.fulfill({ contentType: 'text/html', body: `<main class="main-content">${link}</main><script src="/navigation.js" data-workspace="inventory_manager"></script>` });
            });
            await page.goto('http://labels.test/products.php');
            await page.getByRole('menuitem', { name: 'Barcode Labels' }).click();
            await page.waitForSelector('.barcode-page, [data-navigation-status][role="alert"]');
            assert.equal(await page.locator('[data-navigation-status][role="alert"]').count(), 0,
                'Barcode Labels must open instead of showing "This page could not be loaded completely"');
            await page.waitForURL('http://labels.test/print_barcodes.php', { timeout: 3000 });
            assert.equal(await page.getByRole('heading', { name: 'Printable Barcode Labels' }).isVisible(), true);
            assert.equal(requests.length, 1);
            assert.equal(requests[0]['x-retailmind-navigation'], undefined, 'Labels must open as a complete printable document');
            await page.close();
        }
        console.log('Barcode Labels navigation: passed (desktop/mobile)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
