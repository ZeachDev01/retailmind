const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const cssDirectory = path.resolve(__dirname, '../../frontend/assets/css');
    const server = http.createServer((req, res) => {
        res.setHeader('Cache-Control', 'no-store');
        if (req.url.endsWith('.css')) {
            const fileName = path.basename(req.url.split('?')[0]);
            const filePath = path.join(cssDirectory, fileName);
            if (fs.existsSync(filePath)) {
                res.setHeader('Content-Type', 'text/css');
                return res.end(fs.readFileSync(filePath, 'utf8'));
            }
            res.statusCode = 404;
            return res.end('Not found');
        }
        res.setHeader('Content-Type', 'text/html');
        if (req.url === '/sales') {
            return res.end(`<!DOCTYPE html>
<html>
<head><link rel="stylesheet" href="/style.css"></head>
<body class="receipts-page">
    <a class="skip-link" href="#receipts-main">Skip to main content</a>
    <main id="receipts-main"><h1>Receipts</h1></main>
</body></html>`);
        }
        if (req.url === '/refunds') {
            return res.end(`<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="/refunds.css">
</head>
<body class="cashier-page cashier-refunds-page">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <main id="main-content"><h1>Refunds</h1></main>
</body></html>`);
        }
        return res.end(`<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="/cashier.css">
</head>
<body class="cashier-page cashier-pos-page">
    <a class="skip-link" href="#pos-main">Skip to main content</a>
    <main id="pos-main"><h1>POS</h1></main>
</body></html>`);
    });

    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const port = server.address().port;
        browser = await chromium.launch({ headless: true });

        for (const route of ['/pos', '/sales', '/refunds']) {
            const page = await browser.newPage();
            await page.goto(`http://127.0.0.1:${port}${route}`);

            const skipLink = page.locator('.skip-link');
            const initialBox = await skipLink.boundingBox();

            // The skip-link must not be visible in normal flow on initial page load / refresh
            const isInitiallyOffscreenOrHidden = await skipLink.evaluate(el => {
                const style = window.getComputedStyle(el);
                const rect = el.getBoundingClientRect();
                return rect.bottom <= 0 || rect.top < 0 || style.transform.includes('matrix') || style.display === 'none' || style.visibility === 'hidden';
            });

            assert.equal(
                isInitiallyOffscreenOrHidden,
                true,
                `On ${route}, .skip-link is visible in normal flow on load (top: ${initialBox?.y}, bottom: ${initialBox ? initialBox.y + initialBox.height : 0})`
            );

            // When focused, the skip-link should be brought into view
            await page.keyboard.press('Tab');
            await page.waitForTimeout(200);
            const rect = await skipLink.evaluate(el => {
                const r = el.getBoundingClientRect();
                return {
                    top: r.top,
                    bottom: r.bottom,
                    transform: window.getComputedStyle(el).transform,
                    isActive: document.activeElement === el
                };
            });
            const focusedInView = rect.top >= 0 && rect.bottom > 0;
            assert.equal(focusedInView, true, `On ${route}, focused .skip-link should be in view`);

            await page.close();
        }
        console.log('PASS: .skip-link is properly hidden until focused across all routes.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(err => {
    console.error(err.message);
    process.exit(1);
});
