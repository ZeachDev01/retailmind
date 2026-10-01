const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
    const fixture = path.join(os.tmpdir(), `retailmind-safe-refund-${process.pid}.json`);
    const output = path.join(os.tmpdir(), 'retailmind-safe-refund-browser');
    fs.mkdirSync(output, { recursive: true });
    let browser;
    try {
        execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'cash_refund_concurrency_integration.php')], {
            env: { ...process.env, RUN_REFUND_DB_TESTS: '1', SAFE_REFUND_BROWSER_FIXTURE: fixture }, stdio: 'pipe', timeout: 120000
        });
        const fixtures = JSON.parse(fs.readFileSync(fixture, 'utf8'));
        browser = await chromium.launch({ headless: true });
        for (const [variant, html] of Object.entries(fixtures)) {
            const page = await browser.newPage();
            // Render actual HTTP handler output without touching the configured Store.
            await page.route('**/*', route => route.abort());
            await page.setContent(html, { waitUntil: 'domcontentloaded' });
            for (const css of ['style.css', 'cashier-pages.css', 'refunds.css', 'sale-receipt.css']) {
                const cssDir = path.join(__dirname, '../../frontend/assets/css');
                const content = fs.readFileSync(path.join(cssDir, css), 'utf8').replace(/@import url\("([^"]+)"\);/g,
                    (_, imported) => fs.readFileSync(path.join(cssDir, imported), 'utf8'));
                await page.addStyleTag({ content });
            }
            assert.equal(await page.locator('input[type=password]').evaluateAll(inputs => inputs.every(input => input.value === '')), true,
                'Administrator passwords are never retained in rendered forms');
            const form = page.locator('.refund-sale .refund-form');
            await form.locator('input[name^=quantity]').fill('1');
            await form.locator('select[name^=disposition]').selectOption('damaged');
            await form.locator('#reason').selectOption('customer_return');
            if (variant === 'exception') {
                assert.equal(await form.locator('input[name=exception_token]').count(), 1);
                assert.equal(await form.evaluate(el => el.checkValidity()), false, 'Lookup access alone cannot approve refund');
                await form.locator('#refund-approver').fill('refund-admin');
                await form.locator('#refund-password').fill('example-only-password');
            } else {
                assert.ok((await form.innerText()).includes('RetailMind does not transfer money.'));
                assert.equal(await form.evaluate(el => el.checkValidity()), false, 'External reference and completion are required');
                await form.locator('#refund-reference').fill('EXTERNAL-REFERENCE');
                assert.equal(await form.evaluate(el => el.checkValidity()), false, 'Reference alone does not assert external completion');
                await form.locator('input[name=external_completed]').check();
            }
            assert.equal(await form.evaluate(el => el.checkValidity()), true, 'Complete specific refund can be submitted');
            assert.ok((await form.innerText()).includes('Stock Issue'));
            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: 900 });
                await page.locator('.refund-search details').evaluate(el => { el.open = true; });
                await page.screenshot({ path: path.join(output, `${variant}-${width}.png`), fullPage: true });
                assert.equal(await page.locator('.refund-sale').evaluate(el => el.scrollWidth <= el.clientWidth + 1), true,
                    'Refund form stays within desktop and narrow screen surface');
                assert.equal(await form.locator('button[type=submit]').isEnabled(), true);
            }
            await page.close();
        }
        console.log(`Safe refund external settlement and exception browser checks: passed (${output})`);
    } finally {
        if (browser) await browser.close();
        if (fs.existsSync(fixture)) fs.unlinkSync(fixture);
    }
})().catch(error => { console.error(error); process.exit(1); });
