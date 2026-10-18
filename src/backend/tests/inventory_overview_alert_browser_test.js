const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        const frontend = path.resolve(__dirname, '../../frontend');
        const modal = fs.readFileSync(path.join(frontend, 'components/modals/product_overview/viewBatches.php'), 'utf8')
            .replace(/<\?php[\s\S]*?\?>/g, '');
        await page.setContent('<main class="main-content"></main>' + modal);
        await page.evaluate(() => {
            window.capturedAlerts = [];
            window.Swal = {fire(options) { capturedAlerts.push(options); return Promise.resolve({}); }};
        });
        await page.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
        await page.evaluate(() => RetailMindUI.initPageContent());
        assert.deepEqual(await page.evaluate(() => capturedAlerts), [],
            'Entering Inventory Overview must not turn a hidden batch reconciliation notice into an Attention popup');
        await page.evaluate(() => {
            document.querySelector('#view-batches-modal').setAttribute('aria-hidden', 'false');
            document.querySelector('#view-batches-reconciliation-alert').style.display = 'block';
            RetailMindUI.initPageContent();
        });
        assert.equal(await page.locator('#view-batches-reconciliation-alert').isVisible(), true,
            'The reconciliation notice must remain available inside View Batches');
        assert.deepEqual(await page.evaluate(() => capturedAlerts), [], 'Inline batch notice must not become a global alert');
        await page.evaluate(() => {
            const success = document.createElement('div');
            success.className = 'message success';
            success.textContent = 'Supplier pullout recorded.';
            document.querySelector('main').append(success);
            RetailMindUI.initPageContent();
        });
        await page.locator('.rm-toast.success').waitFor();
        assert.equal(await page.locator('.rm-toast.success span').textContent(), 'Supplier pullout recorded.', 'Actual success feedback appears as a toast');
        assert.deepEqual(await page.evaluate(() => capturedAlerts), [], 'Routine feedback must not open SweetAlert');
        console.log('Inventory Overview alerts: passed (no false Attention popup; inline reconciliation and success feedback preserved)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
