const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
(async () => {
    if (process.argv[2] !== '--browser') {
        execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'cash_refund_concurrency_integration.php')], {
            env: { ...process.env, RUN_REFUND_DB_TESTS: '1', RUN_REFUND_MANAGER_BROWSER: '1' }, stdio: 'inherit', timeout: 180000
        });
        return;
    }
    const origin = process.argv[3];
    const output = path.join(os.tmpdir(), 'retailmind-return-condition-manager');
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ serviceWorkers: 'block', extraHTTPHeaders: {'X-Fixture-User':'6','X-Fixture-Workspace':'inventory_manager'} });
        page.setDefaultTimeout(15000);
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            const asset = url.pathname.match(/\/assets\/(.+)$/);
            if (url.origin === origin && asset) return route.fulfill({ path: path.join(__dirname, '../../frontend/assets', decodeURIComponent(asset[1])) });
            if (url.pathname.endsWith('/session_activity.php')) return route.fulfill({ status: 204, body: '' });
            if (url.origin === origin) return route.continue();
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({width,height:900});
            await page.goto(`${origin}/invoice`);
            await page.locator('[data-receipt-details="102"]').click();
            await page.locator('#receiptOtherTab').click();
            const conditions = page.locator('.refund-condition-details');
            await conditions.getByText('Manager inspection found damage', {exact:false}).waitFor();
            const item = conditions.locator('.refund-condition-line').filter({hasText:'Split allocation item'}).filter({has:page.locator('.refund-condition-quantity', {hasText:/^2 pieces/})});
            await item.locator('summary').click();
            const form = item.locator('form');
            const target = width === 1440 ? 'restockable' : 'damaged';
            await form.locator('select').selectOption(target);
            await form.locator('[name=correction_reason]').fill(`Manager browser ${width}`);
            if (target === 'restockable') await form.locator('[name=fit_for_sale]').check();
            assert.equal(await form.locator('.refund-condition-preview').innerText(), `Split allocation item — ${target==='restockable'?'Damaged → Restockable. Return':'Restockable → Damaged. Remove'} 2 pieces ${target==='restockable'?'to':'from'} available stock.`);
            assert.equal(await form.locator('.refund-condition-preview').evaluate(el => { const r=el.getBoundingClientRect();return r.left>=0 && r.right<=innerWidth; }),true,'Full product, quantity, conditions and stock effect fit on screen');
            assert.equal(await form.locator('[name=quantity]').count(),0);
            assert.equal(await form.locator('input[type=checkbox]').count(),1,'Only physical confirmation, no execution checkbox');
            await page.screenshot({path:path.join(output,`preview-${width}.png`),fullPage:false});
            await form.getByRole('button',{name:'Save correction'}).click();
            await conditions.getByRole('status').waitFor();
            assert.match(await conditions.innerText(),new RegExp(`Manager browser ${width}`));
            assert.match(await conditions.innerText(),/Inventory Manager/);
            assert.match(await conditions.innerText(),/Stock movement #/);
            await page.screenshot({path:path.join(output,`history-${width}.png`),fullPage:false});
        }
        await page.setExtraHTTPHeaders({'X-Fixture-User':'2','X-Fixture-Workspace':'admin'});
        await page.goto(`${origin}/invoice`);
        await page.locator('[data-receipt-details="102"]').click();
        await page.locator('#receiptOtherTab').click();
        await page.locator('.refund-condition-details').getByText('Manager browser 390',{exact:false}).waitFor();
        assert.equal(await page.locator('.refund-condition-details form').count(),0,'Administrator sees read-only evidence');
        console.log(`Store return signed-in desktop/mobile preview, save and history checks passed (${output})`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
