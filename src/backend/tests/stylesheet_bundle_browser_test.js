const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const cssDirectory = path.resolve(__dirname, '../../frontend/assets/css');
    const sources = ['global', 'forms', 'utilities', 'sidebar', 'dashboard', 'tables', 'modals', 'products', 'responsive'];
    const oldBundle = sources.map(name => `@import url("${name}.css");`).join('\n');
    const server = http.createServer((request, response) => {
        response.setHeader('Cache-Control', 'no-store');
        if (request.url.endsWith('.css')) {
            response.setHeader('Content-Type', 'text/css');
            const css = request.url === '/before.css' ? oldBundle
                : fs.readFileSync(path.join(cssDirectory, path.basename(request.url)), 'utf8');
            setTimeout(() => response.end(css), 100);
            return;
        }
        response.setHeader('Content-Type', 'text/html');
        response.end(`<link rel="stylesheet" href="/${request.url === '/before' ? 'before' : 'style'}.css">
            <div class="app-shell"><aside id="appSidebar" class="sidebar">Inventory</aside>
            <main class="main-content"><h1>Inventory</h1><button class="btn btn-primary">Add product</button>
            <input aria-label="Product"><table><thead><tr><th>Product</th></tr></thead>
            <tbody><tr><td>Example</td></tr></tbody></table></main></div>`);
    });
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        browser = await chromium.launch({headless: true});
        const results = [];
        for (const route of ['before', 'after']) {
            const page = await browser.newPage();
            let requests = 0;
            page.on('request', request => { if (request.url().endsWith('.css')) requests++; });
            const started = performance.now();
            await page.goto(`http://127.0.0.1:${server.address().port}/${route}`);
            const elapsed = Math.round(performance.now() - started);
            const styles = [];
            for (const width of [390, 1280]) {
                await page.setViewportSize({width, height: 844});
                styles.push(await page.evaluate(() => ['body', '#appSidebar', '.main-content', 'button', 'input', 'table', 'th', 'td'].map(selector => {
                    const style = getComputedStyle(document.querySelector(selector));
                    return ['display', 'width', 'height', 'color', 'backgroundColor', 'fontFamily', 'fontSize', 'padding', 'margin', 'border'].map(property => style[property]);
                })));
            }
            results.push({requests, elapsed, styles});
            await page.close();
        }
        assert.deepEqual(results[1].styles, results[0].styles, 'Bundling preserves desktop and mobile styles');
        assert.equal(results[0].requests, 10, 'Baseline uses the entry stylesheet plus nine imports');
        assert.equal(results[1].requests, 1, 'The bundle requires one stylesheet request');
        console.log(`Stylesheet bundle browser check: passed (10 → 1 requests; simulated 100 ms network: ${results[0].elapsed} → ${results[1].elapsed} ms)`);
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
