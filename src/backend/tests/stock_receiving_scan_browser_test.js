'use strict';
// Browser-to-HTTP workflow for barcode-assisted Stock Receiving (tickets #81-#84).

const { spawn, spawnSync } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '..', '..', '..');
const fixtureScript = path.join(__dirname, 'support', 'scan_workflow_fixture.php');
const receivingPath = '/components/report/stock_receiving.php';
const phpBinary = process.env.PHP_BINARY || 'php';

function skip(reason) {
    console.log('Stock receiving scan browser workflow: skipped (' + reason + ')');
    process.exit(0);
}

if (process.env.RUN_DB_TESTS !== '1') {
    skip('set RUN_DB_TESTS=1');
}

let playwright;
try {
    playwright = require('playwright');
} catch (error) {
    skip('playwright is not installed');
}

function runFixture(...args) {
    const result = spawnSync(phpBinary, [fixtureScript, ...args], { encoding: 'utf8', cwd: repoRoot });
    if (result.error || result.status !== 0) {
        throw new Error('Fixture failed: ' + (result.error?.message || result.stderr || result.stdout || result.status));
    }
    return JSON.parse(result.stdout.trim().split('\n').at(-1));
}

function freePort() {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.unref();
        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const port = server.address().port;
            server.close(() => resolve(port));
        });
    });
}

async function waitForHttp(url) {
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
        try {
            const response = await fetch(url);
            if (response.status < 500) return;
        } catch (error) {
            // Server is still starting.
        }
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    throw new Error('Application server did not start.');
}

async function login(page, member) {
    await page.goto('/?login=1');
    await page.fill('#landing-login-username', member.username);
    await page.fill('#landing-login-password', member.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        page.locator('#landing-login-password').press('Enter')
    ]);
}

async function dismissBlockingDialog(page) {
    const confirm = page.locator('.swal2-confirm');
    if (await confirm.count() && await confirm.isVisible().catch(() => false)) {
        await confirm.click();
    }
}

async function eventually(page, expression, argument) {
    try {
        await page.waitForFunction(expression, argument, { timeout: 5000, polling: 100 });
        return true;
    } catch (error) {
        return false;
    }
}

async function scan(page, code) {
    const input = page.locator('#product_code_scan');
    await input.fill(code);
    await input.press('Enter');
}

const failures = [];
function check(condition, message) {
    console.log((condition ? '  ok - ' : '  FAIL - ') + message);
    if (!condition) failures.push(message);
}

async function main() {
    const token = 'rcv' + Math.random().toString(36).slice(2, 8);
    let server;
    let browser;
    let fixture;

    try {
        fixture = runFixture('--create', '--token', token);
        const port = await freePort();
        const baseURL = 'http://127.0.0.1:' + port;
        server = spawn(phpBinary, ['-S', '127.0.0.1:' + port, '-t', path.join(repoRoot, 'src', 'frontend')], {
            cwd: repoRoot,
            stdio: ['ignore', 'ignore', 'pipe']
        });
        await waitForHttp(baseURL + '/?login=1');

        browser = await playwright.chromium.launch({ headless: true, args: ['--no-sandbox'] });
        const context = await browser.newContext({ baseURL });
        const page = await context.newPage();
        await login(page, fixture.staff.manager);
        await page.goto(receivingPath);

        const before = runFixture('--state', '--token', token);
        const beforeZero = before.products.find((product) => product.sku === fixture.products.zero.sku);

        console.log('Scan-only identification');
        check(await page.locator('#product_code_scan').count() === 1,
            'Stock Receiving renders the scan field');
        await scan(page, fixture.products.zero.barcode);
        check(await eventually(page, (productId) =>
            document.getElementById('product_id').value === String(productId), fixture.products.zero.product_id),
            'a unit barcode selects the zero-stock product');
        check((await page.locator('#scan_status').innerText()).includes(fixture.products.zero.product_name),
            'the matched product is visibly identified');
        check(await page.inputValue('#quantity_mode') === 'units',
            'a scan selects Base units');
        check(await page.inputValue('#received_qty') === '0',
            'a scan does not infer a delivered quantity');

        const after = runFixture('--state', '--token', token);
        const afterZero = after.products.find((product) => product.sku === fixture.products.zero.sku);
        check(Number(afterZero.quantity_on_hand) === Number(beforeZero.quantity_on_hand),
            'a scan alone does not update stock');
        check(after.receipts.length === before.receipts.length,
            'a scan alone creates no receiving record');

        console.log('Explicit eligible-document review');
        check(await page.locator('#scan_document_review .scan-document-choice').count() === 1,
            'the product\'s one eligible document is offered for review');
        check((await page.locator('#scan_document_review').innerText()).includes('8'),
            'the eligible request shows its remaining quantity for review');
        check(await page.inputValue('#replenishment_request_id') === '',
            'the scan does not choose the replenishment request');
        await page.locator('#scan_document_review .scan-document-choice').click();
        check(await page.inputValue('#replenishment_request_id') === String(fixture.receiving_documents.approved_request.request_id),
            'the operator explicitly chooses the replenishment request');
        check(await page.inputValue('#received_qty') === '0',
            'choosing a document still does not copy its remaining quantity');

        console.log('Linked requests reject conflicting rescans');
        await page.fill('#received_qty', '5');
        await page.fill('#supplier', 'Reviewed supplier detail');
        await page.fill('#invoice_number', 'INV-REVIEW-' + token);
        await page.fill('#batch_number', 'BATCH-REVIEW-' + token);
        await page.fill('#discrepancy_notes', 'Preserve this reviewed discrepancy detail.');
        await scan(page, fixture.products.countable.barcode);
        await page.waitForFunction(() => document.getElementById('scan_status').textContent.includes('linked to a replenishment request'));
        check(await page.inputValue('#product_id') === String(fixture.products.zero.product_id)
            && await page.inputValue('#replenishment_request_id') === String(fixture.receiving_documents.approved_request.request_id)
            && await page.inputValue('#received_qty') === '5',
            'a conflicting linked-request scan preserves product, link and quantity');

        console.log('Unknown and ambiguous codes preserve entered delivery details');
        await scan(page, 'UNKNOWN-' + token);
        await page.waitForFunction(() => document.getElementById('scan_status').textContent.includes('No product matches'));
        check(await page.inputValue('#product_id') === String(fixture.products.zero.product_id),
            'an unknown code preserves the selected product');
        check(await page.inputValue('#received_qty') === '5'
            && await page.inputValue('#supplier') === 'Reviewed supplier detail'
            && await page.inputValue('#batch_number') === 'BATCH-REVIEW-' + token,
            'an unknown code leaves the controlled form intact');
        await scan(page, fixture.products.ambiguous_a.barcode);
        await page.waitForFunction(() => document.getElementById('scan_status').textContent.includes('more than one product'));
        check(await page.inputValue('#product_id') === String(fixture.products.zero.product_id)
            && await page.inputValue('#replenishment_request_id') === String(fixture.receiving_documents.approved_request.request_id),
            'an ambiguous code preserves the product and chosen document');

        console.log('Case scanning and multiple eligible documents');
        await page.goto(receivingPath);
        await scan(page, fixture.products.countable.case_barcode);
        check(await eventually(page, (productId) =>
            document.getElementById('product_id').value === String(productId), fixture.products.countable.product_id),
            'a case barcode selects its product');
        check(await page.inputValue('#quantity_mode') === 'packages',
            'a case barcode selects Packages / cases');
        check((await page.locator('#package_conversion').innerText()).includes('1 case = 6 piece(s)'),
            'the product-specific package conversion is visible');
        check(await page.inputValue('#received_packages') === '0',
            'a case scan does not infer a package quantity');
        check(await page.locator('#scan_document_review .scan-document-choice').count() === 3,
            'all eligible PO lines and the standalone request are offered');
        const multipleChoiceText = await page.locator('#scan_document_review').innerText();
        check(multipleChoiceText.includes('Line #' + fixture.receiving_documents.po.purchase_order_item_id)
            && multipleChoiceText.includes('Line #' + fixture.receiving_documents.alternate_po_line.purchase_order_item_id)
            && multipleChoiceText.includes('Request #' + fixture.receiving_documents.standalone_request.request_id),
            'eligible documents expose distinct identities');
        check(!multipleChoiceText.includes('Line #' + fixture.receiving_documents.fully_received_po_line.purchase_order_item_id),
            'a fully received PO line is not offered');
        await scan(page, fixture.products.countable.case_barcode);
        await page.waitForFunction(() => !document.getElementById('scan_status').textContent.includes('Checking'));
        check(await page.inputValue('#received_packages') === '0',
            'repeated case scans do not increment package quantity');

        const firstPoChoice = page.locator(
            `.scan-document-choice[aria-label*="Line #${fixture.receiving_documents.po.purchase_order_item_id}"]`
        );
        await firstPoChoice.click();
        await page.fill('#received_packages', '2');
        await scan(page, fixture.products.zero.barcode);
        await page.waitForFunction(() => document.getElementById('scan_status').textContent.includes('linked to a purchase-order line'));
        check(await page.inputValue('#product_id') === String(fixture.products.countable.product_id)
            && await page.inputValue('#purchase_order_item_id') === String(fixture.receiving_documents.po.purchase_order_item_id)
            && await page.inputValue('#received_packages') === '2',
            'a conflicting linked-PO scan preserves product, link and package quantity');

        console.log('Partly filled unlinked receipts require confirmation');
        await page.goto(receivingPath);
        await page.selectOption('#product_id', String(fixture.products.zero.product_id));
        await page.fill('#received_qty', '4');
        await page.fill('#supplier', 'Rescan supplier ' + token);
        await page.fill('#invoice_number', 'RESCAN-' + token);
        page.once('dialog', (dialog) => dialog.dismiss());
        await scan(page, fixture.products.countable.barcode);
        await page.waitForFunction(() => document.getElementById('scan_status').textContent.includes('Product unchanged'));
        check(await page.inputValue('#product_id') === String(fixture.products.zero.product_id)
            && await page.inputValue('#received_qty') === '4'
            && await page.inputValue('#supplier') === 'Rescan supplier ' + token,
            'cancelling replacement preserves every entered field');
        page.once('dialog', (dialog) => dialog.accept());
        await scan(page, fixture.products.countable.barcode);
        check(await eventually(page, (productId) =>
            document.getElementById('product_id').value === String(productId), fixture.products.countable.product_id),
            'confirming replacement selects the scanned product');
        check(await page.inputValue('#received_qty') === '4'
            && await page.inputValue('#invoice_number') === 'RESCAN-' + token
            && await page.inputValue('#purchase_order_item_id') === '',
            'confirmed replacement preserves details without choosing a document');

        console.log('Ineligible documents are not offered');
        await page.goto(receivingPath);
        await scan(page, fixture.products.short.sku);
        check(await eventually(page, (productId) =>
            document.getElementById('product_id').value === String(productId), fixture.products.short.product_id),
            'a unique SKU selects its product');
        check(await page.locator('#scan_document_review .scan-document-choice').count() === 0,
            'a pending replenishment request is not offered');
        check((await page.locator('#scan_document_review').innerText()).includes('No eligible document'),
            'no eligible document gives a clear routine-receiving message');
        check(await page.inputValue('#replenishment_request_id') === ''
            && await page.inputValue('#purchase_order_item_id') === '',
            'scanning a product without an eligible document clears document references');
        const receiptsBeforeBlockedRoutine = runFixture('--state', '--token', token).receipts.length;
        await page.fill('#received_qty', '1');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.locator('.btn-submit').click()
        ]);
        check((await page.locator('.message.error').innerText()).includes('approved request or purchase order'),
            'an Inventory Manager cannot submit a routine receipt without an eligible document');
        check(runFixture('--state', '--token', token).receipts.length === receiptsBeforeBlockedRoutine,
            'the blocked no-document submission writes no receipt');
        await dismissBlockingDialog(page);

        console.log('CSRF and role boundaries');
        const receiptsBeforeCsrf = runFixture('--state', '--token', token).receipts.length;
        const csrfResponse = await context.request.post(receivingPath, {
            form: {
                csrf_token: 'invalid-token',
                product_id: fixture.products.zero.product_id,
                quantity_mode: 'units',
                received_qty: 1,
                replenishment_request_id: fixture.receiving_documents.approved_request.request_id
            }
        });
        check(csrfResponse.status() === 403, 'a receiving submission with an invalid CSRF token is refused');
        check(runFixture('--state', '--token', token).receipts.length === receiptsBeforeCsrf,
            'the refused CSRF submission creates no receipt');

        const cashierContext = await browser.newContext({ baseURL });
        const cashierPage = await cashierContext.newPage();
        await login(cashierPage, fixture.staff.cashier);
        const cashierResponse = await cashierPage.goto(receivingPath);
        check(cashierResponse.status() === 403 || !cashierPage.url().includes(receivingPath),
            'a Cashier cannot open Stock Receiving');
        await cashierContext.close();

        check(await page.locator('#emergency_reason').count() === 0,
            'the Inventory Manager has no emergency-receiving control');
        const adminContext = await browser.newContext({ baseURL });
        const adminPage = await adminContext.newPage();
        await login(adminPage, fixture.staff.admin);
        await adminPage.goto(receivingPath);
        check(await adminPage.locator('#emergency_reason').count() === 1,
            'the existing Administrator emergency-receiving control remains distinct');
        await adminContext.close();

        console.log('Explicit submission and accepted-stock outcome');
        await page.goto(receivingPath);
        await scan(page, fixture.products.countable.case_barcode);
        check(await eventually(page, (productId) =>
            document.getElementById('product_id').value === String(productId), fixture.products.countable.product_id),
            'the PO product is selected by case barcode');
        check(await page.inputValue('#quantity_mode') === 'packages',
            'the submission path remains in Packages / cases mode');
        check(await page.locator('#scan_document_review .scan-document-choice').count() === 3,
            'all eligible documents remain available for explicit review');
        check(await page.inputValue('#purchase_order_item_id') === '',
            'the scan does not choose the purchase-order line');
        await page.locator(
            `.scan-document-choice[aria-label*="Line #${fixture.receiving_documents.po.purchase_order_item_id}"]`
        ).click();
        check(await page.inputValue('#purchase_order_item_id') === String(fixture.receiving_documents.po.purchase_order_item_id),
            'the operator explicitly chooses the purchase-order line');
        check(await page.inputValue('#received_packages') === '0',
            'the PO remaining quantity is not copied into delivered packages');

        console.log('Newest scan outcome wins');
        let releaseDelayedLookup;
        const delayedLookup = new Promise((resolve) => { releaseDelayedLookup = resolve; });
        await page.route('**/product_code_lookup.php?code=*', async (route) => {
            if (route.request().url().includes(encodeURIComponent(fixture.products.zero.barcode))) {
                await delayedLookup;
            }
            await route.continue();
        });
        const delayedScan = scan(page, fixture.products.zero.barcode);
        await page.waitForTimeout(100);
        await scan(page, 'LATEST-UNKNOWN-' + token);
        await page.waitForFunction(() => document.getElementById('scan_status').textContent.includes('No product matches'));
        releaseDelayedLookup();
        await delayedScan;
        await page.waitForTimeout(250);
        check(await page.inputValue('#product_id') === String(fixture.products.countable.product_id),
            'an older delayed match cannot replace the newer unknown result');
        check(await page.inputValue('#purchase_order_item_id') === String(fixture.receiving_documents.po.purchase_order_item_id),
            'an older delayed match cannot clear the chosen PO line');
        await page.unroute('**/product_code_lookup.php?code=*');

        await page.fill('#received_packages', '2');
        await page.fill('#damaged_qty', '2');
        await page.fill('#invoice_number', 'INV-' + token);
        await page.fill('#batch_number', 'BATCH-' + token);
        await page.fill('#expiration_date', '2027-12-31');
        await page.fill('#discrepancy_notes', 'Two delivered pieces were damaged.');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.locator('.btn-submit').click()
        ]);
        check((await page.locator('.message.success').innerText()).includes('10 accepted units'),
            'the controlled form reports only accepted units added');

        const finalState = runFixture('--state', '--token', token);
        const finalProduct = finalState.products.find((product) => product.sku === fixture.products.countable.sku);
        const receipt = finalState.receipts.find((item) => item.sku === fixture.products.countable.sku);
        const poItem = finalState.purchase_order_items.find((item) =>
            Number(item.purchase_order_item_id) === fixture.receiving_documents.po.purchase_order_item_id);
        check(Number(finalProduct.quantity_on_hand) === Number(fixture.products.countable.quantity_on_hand) + 10,
            'submission increments stock by accepted units only');
        check(receipt && Number(receipt.received_packages) === 2
            && Number(receipt.units_per_package_used) === 6
            && Number(receipt.received_qty) === 12 && Number(receipt.accepted_qty) === 10
            && Number(receipt.damaged_qty) === 2,
            'the receipt converts explicit packages and records accepted and damaged base units');
        check(receipt && receipt.invoice_number === 'INV-' + token
            && receipt.batch_number === 'BATCH-' + token
            && receipt.expiration_date === '2027-12-31',
            'the receipt retains reviewed invoice, batch and expiry details');
        check(poItem && Number(poItem.received_qty) === 12,
            'the existing document validation updates the chosen PO line by accepted units');

        await context.close();
        if (failures.length) {
            throw new Error(failures.join('\n'));
        }
        console.log('Stock receiving scan browser workflow: passed');
    } finally {
        if (browser) await browser.close().catch(() => {});
        if (server) server.kill();
        if (fixture) runFixture('--cleanup', '--token', token);
    }
}

main().catch((error) => {
    console.error(error.stack || error);
    process.exit(1);
});
