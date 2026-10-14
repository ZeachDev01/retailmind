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

async function receivingForm(page, fields = {}) {
    await page.goto(receivingPath, { waitUntil: 'domcontentloaded' });
    return {
        csrf_token: await page.inputValue('input[name="csrf_token"]'),
        receiving_nonce: await page.inputValue('input[name="receiving_nonce"]'),
        product_id: '', quantity_mode: 'units', received_qty: '1',
        received_packages: '0', damaged_qty: '0', discrepancy_qty: '0',
        ...fields
    };
}

async function shoppingForm(page, lines, entry = {}) {
    const form = await receivingForm(page, { entry_mode: 'shopping_trip', ...entry });
    lines.forEach((line, index) => {
        for (const [name, value] of Object.entries(line)) form[`lines[${index}][${name}]`] = String(value);
    });
    return form;
}

const failures = [];
function check(condition, message) {
    console.log((condition ? '  ok - ' : '  FAIL - ') + message);
    if (!condition) failures.push(message);
}

async function main() {
    const token = 'rcv' + Math.random().toString(36).slice(2, 8);
    let server;
    let secondServer;
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
        server.stderr.resume();
        await waitForHttp(baseURL + '/?login=1');

        browser = await playwright.chromium.launch({ headless: true, args: ['--no-sandbox'] });
        const context = await browser.newContext({ baseURL });
        const page = await context.newPage();
        await login(page, fixture.staff.manager);
        await page.goto(receivingPath);

        console.log('Shopping trip entry');
        check(await page.locator('#add_receiving_line').count() === 1,
            'Stock Receiving offers another product line before one reviewed save');
        if (!await page.locator('#add_receiving_line').count()) throw new Error('Multi-product receiving is unavailable.');

        const before = runFixture('--state', '--token', token);
        const historicalReceipt = before.receipts.find(item => item.invoice_number === 'HISTORICAL-' + token);
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
        check((await page.locator('#scan_document_review').innerText()).includes('optional'),
            'receiving documents are explicitly optional');
        check(await page.inputValue('#replenishment_request_id') === ''
            && await page.inputValue('#purchase_order_item_id') === '',
            'scanning a product without an eligible document clears document references');
        const receiptsBeforeBlockedRoutine = runFixture('--state', '--token', token).receipts.length;
        await page.fill('#received_qty', '1');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.locator('.btn-submit').click()
        ]);
        check((await page.locator('.message.success').innerText()).includes('1 accepted units'),
            'an Inventory Manager can receive directly without an approved document');
        const directState = runFixture('--state', '--token', token);
        check(directState.receipts.length === receiptsBeforeBlockedRoutine + 1,
            'direct receiving writes one receipt');
        await dismissBlockingDialog(page);

        console.log('CSRF and role boundaries');
        const receiptsBeforeCsrf = runFixture('--state', '--token', token).receipts.length;
        const csrfResponse = await context.request.post(receivingPath, {
            form: {
                csrf_token: 'invalid-token',
                product_id: fixture.products.zero.product_id,
                quantity_mode: 'units',
                received_qty: 1
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
        check(await adminPage.locator('#emergency_reason').count() === 0
            && await adminPage.locator('.btn-submit').isDisabled(),
            'Administrator receiving view has no emergency bypass and cannot submit');
        await adminContext.close();

        console.log('Direct bought stock without purchasing history');
        await page.goto(receivingPath);
        await scan(page, fixture.products.direct.case_barcode);
        check(await eventually(page, id => document.getElementById('product_id').value === String(id),
            fixture.products.direct.product_id), 'a case barcode selects a product without purchasing history');
        check(await page.inputValue('#cost_price') === '', 'unknown purchase cost stays blank');
        await page.fill('#received_packages', '2');
        check((await page.locator('#package_conversion').innerText()).includes('2 × 24 = 48'),
            'two saved cases display 48 units before saving');
        const duplicateForm = {
            csrf_token: await page.inputValue('input[name="csrf_token"]'),
            receiving_nonce: await page.inputValue('input[name="receiving_nonce"]'),
            product_id: fixture.products.direct.product_id, quantity_mode: 'packages',
            received_packages: '2', received_qty: '0'
        };
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.btn-submit').click()]);
        let state = runFixture('--state', '--token', token);
        let directProduct = state.products.find(item => item.sku === fixture.products.direct.sku);
        let directReceipt = state.receipts.find(item => item.sku === fixture.products.direct.sku);
        check(Number(directProduct.quantity_on_hand) === 56, '8 + two cases of 24 = 56 units');
        check(directReceipt.cost_price === null && directReceipt.supplier === null
            && directReceipt.invoice_number === null && directReceipt.replenishment_request_id === null
            && directReceipt.purchase_order_item_id === null && Number(directProduct.cost_price) === 5
            && directProduct.preferred_supplier === 'Original preferred supplier',
            'missing optional details preserve cost and preferred supplier without purchasing links');
        check(state.purchase_history.filter(item => item.sku === fixture.products.direct.sku).length === 0,
            'unknown cost creates no invented purchase-cost history');
        const historyRow = page.locator('.history-table tbody tr').filter({ hasText: fixture.products.direct.sku });
        check((await historyRow.innerText()).includes('Scan WF Manager')
            && (await historyRow.innerText()).includes(directReceipt.received_at),
            'history identifies recording staff and time');
        const successText = await page.locator('.message.success').innerText();
        check(successText.includes(fixture.products.direct.product_name)
            && successText.includes('Scan WF Manager') && successText.includes(directReceipt.received_at),
            'success identifies product, recording staff and time');
        await context.request.post(receivingPath, { form: duplicateForm });
        check(JSON.stringify(runFixture('--state', '--token', token)) === JSON.stringify(state),
            'replaying the same form cannot record a second receipt');

        console.log('Supplied cost and damage');
        const costForm = await receivingForm(page, {
            product_id: fixture.products.direct.product_id, received_qty: '5', damaged_qty: '2',
            cost_price: '7.25', supplier: 'Shopping store', invoice_number: 'DIRECT-' + token,
            batch_number: 'DIRECT-BATCH-' + token, expiration_date: '2027-12-31'
        });
        const costResponse = await context.request.post(receivingPath, { form: costForm });
        check((await costResponse.text()).includes('3 accepted units'), 'direct damage excludes two of five bought units');
        state = runFixture('--state', '--token', token);
        directProduct = state.products.find(item => item.sku === fixture.products.direct.sku);
        directReceipt = state.receipts.filter(item => item.sku === fixture.products.direct.sku).at(-1);
        const purchase = state.purchase_history.find(item => item.sku === fixture.products.direct.sku);
        const batch = state.product_batches.find(item => Number(item.receiving_id) === Number(directReceipt.receiving_id));
        const damage = state.inventory_adjustments.find(item => item.sku === fixture.products.direct.sku);
        check(Number(directProduct.quantity_on_hand) === 59 && Number(directProduct.cost_price) === 7.25
            && directProduct.preferred_supplier === 'Original preferred supplier'
            && directProduct.supplier === 'Original supplier', 'accepted units add to stock; cost updates without replacing supplier');
        check(Number(purchase.quantity_purchased) === 3 && Number(purchase.cost_price) === 7.25
            && Number(purchase.total_cost) === 21.75 && purchase.supplier === 'Shopping store',
            'supplied cost uses the displayed base-unit basis and retains purchase history');
        check(directReceipt.supplier === 'Shopping store' && directReceipt.invoice_number === 'DIRECT-' + token
            && Number(batch.remaining_quantity) === 3 && batch.expiration_date === '2027-12-31',
            'receipt and saleable batch retain shopping, receipt, batch and expiry details');
        check(Number(damage.adjustment_qty) === -2 && damage.status === 'cancelled'
            && Number(damage.reported_by) === fixture.staff.manager.user_id
            && damage.review_notes.includes('excluded from accepted stock'),
            'bought damage is attributable and closed without a pending deduction');
        check(state.stock_movements.filter(item => item.sku === fixture.products.direct.sku)
            .reduce((sum, item) => sum + Number(item.change_qty), 0) === 51,
            'movements account only for accepted units');

        console.log('Strict validation saves nothing');
        const invalidEntries = [
            { received_qty: '0' }, { received_qty: '-1' }, { received_qty: '1.5' },
            { received_qty: 'abc' }, { received_qty: '1e2' }, { received_qty: '2147483648' },
            { received_qty: '2147483640' },
            { quantity_mode: 'invalid' }, { damaged_qty: '-1' }, { damaged_qty: '1.5' },
            { damaged_qty: '2' }, { cost_price: '-1' }, { cost_price: 'oops' },
            { cost_price: '1.234' }, { expiration_date: '2027-02-30' },
            { supplier: 's'.repeat(151) }, { invoice_number: 'r'.repeat(101) },
            { discrepancy_qty: '1x' }, { discrepancy_type: 'invalid' },
            { product_id: fixture.products.inactive.product_id },
            { product_id: fixture.products.invalid_pack.product_id, quantity_mode: 'packages', received_packages: '2' },
            { quantity_mode: 'packages', received_packages: '0' },
            { quantity_mode: 'packages', received_packages: '1.5' },
            { received_qty: '2147483647', cost_price: '99999999.99' }
        ];
        const beforeInvalid = JSON.stringify(state);
        for (const invalid of invalidEntries) {
            const form = await receivingForm(page, { product_id: fixture.products.direct.product_id, ...invalid });
            const response = await context.request.post(receivingPath, { form });
            check((await response.text()).includes('message error'), 'invalid entry rejected: ' + JSON.stringify(invalid));
            check(JSON.stringify(runFixture('--state', '--token', token)) === beforeInvalid,
                'invalid entry rolls back every stock, cost, batch and history change');
        }
        await page.goto(receivingPath);
        await page.selectOption('#product_id', String(fixture.products.direct.product_id));
        await page.fill('#received_qty', 'bad quantity');
        await page.fill('#supplier', 'Keep entered store');
        await page.evaluate(() => {
            document.getElementById('receiving_form').noValidate = true;
            const expiry = document.getElementById('expiration_date');
            expiry.type = 'text';
            expiry.value = '2027-02-30';
        });
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.btn-submit').click()]);
        check(await page.inputValue('#received_qty') === 'bad quantity'
            && await page.inputValue('#supplier') === 'Keep entered store'
            && await page.inputValue('#expiration_date') === '2027-02-30'
            && await page.inputValue('#product_id') === String(fixture.products.direct.product_id),
            'validation errors preserve product, malformed quantity, invalid date and optional details');

        const failedPurchase = await receivingForm(page, {
            product_id: fixture.products.direct.product_id, received_qty: '3', cost_price: '8.50'
        });
        try {
            runFixture('--fail-purchase', '--token', token);
            const response = await context.request.post(receivingPath, { form: failedPurchase });
            check((await response.text()).includes('message error'), 'a late purchase-history DB failure reports a failed save');
            check(JSON.stringify(runFixture('--state', '--token', token)) === beforeInvalid,
                'late DB failure rolls back receipt, inventory, batches, movement and product cost together');
        } finally {
            runFixture('--allow-purchase', '--token', token);
        }

        console.log('Fiscal Period and Store write gates');
        const gatedForm = await receivingForm(page, { product_id: fixture.products.direct.product_id });
        try {
            runFixture('--close-period', '--token', token);
            const response = await context.request.post(receivingPath, { form: gatedForm });
            check((await response.text()).includes('fiscal period'), 'a closed Fiscal Period blocks direct receiving');
        } finally {
            runFixture('--open-period', '--token', token);
        }
        const gate = spawn(phpBinary, [fixtureScript, '--hold-gate', '--token', token], { cwd: repoRoot });
        try {
            await new Promise((resolve, reject) => {
                gate.stdout.once('data', resolve);
                gate.once('exit', code => reject(new Error('Gate fixture exited: ' + code)));
            });
            const response = await context.request.post(receivingPath, { form: gatedForm });
            check((await response.text()).includes('message error'), 'the locked Store write gate blocks direct receiving');
        } finally {
            gate.stdin.end('\n');
            await new Promise(resolve => gate.once('exit', resolve));
        }
        check(JSON.stringify(runFixture('--state', '--token', token)) === beforeInvalid,
            'Fiscal Period and Store rejection leave all receiving data unchanged');

        console.log('Direct HTTP workspace boundaries');
        for (const role of ['cashier', 'admin', 'super_admin']) {
            const deniedContext = await browser.newContext({ baseURL });
            const deniedPage = await deniedContext.newPage();
            await login(deniedPage, fixture.staff[role]);
            await deniedPage.goto('/components/auth/workspace.php');
            const csrf = await deniedPage.inputValue('input[name="csrf_token"]');
            const response = await deniedContext.request.post(receivingPath, {
                form: { ...gatedForm, csrf_token: csrf, emergency_reason: 'Must not bypass workspace policy' }
            });
            check(response.status() === 403, role + ' workspace cannot mutate receiving through HTTP');
            if (role === 'super_admin') {
                await deniedContext.request.post('/components/auth/workspace.php', {
                    form: { csrf_token: csrf, workspace: 'inventory_manager' }
                });
                const privilegedForm = await receivingForm(deniedPage, {
                    product_id: fixture.products.direct.product_id, received_qty: '2'
                });
                const privilegedResponse = await deniedContext.request.post(receivingPath, { form: privilegedForm });
                check((await privilegedResponse.text()).includes('2 accepted units'),
                    'privileged identity can receive when operating in Inventory Manager workspace');
            }
            await deniedContext.close();
        }

        const concurrentPage = await context.newPage();
        const firstForm = await receivingForm(page, { product_id: fixture.products.direct.product_id, received_qty: '3' });
        const secondForm = await receivingForm(concurrentPage, { product_id: fixture.products.direct.product_id, received_qty: '4' });
        const simultaneous = await Promise.all([
            context.request.post(receivingPath, { form: firstForm }),
            context.request.post(receivingPath, { form: secondForm })
        ]);
        check((await simultaneous[0].text()).includes('3 accepted units')
            && (await simultaneous[1].text()).includes('4 accepted units'),
            'two distinct forms submitted together both record their quantities');
        check(Number(runFixture('--state', '--token', token).products.find(item => item.sku === fixture.products.direct.sku).quantity_on_hand) === 68,
            'distinct receipts add to existing stock rather than overwriting it');
        await concurrentPage.close();

        // Capture both device classes during the same browser verification pass.
        await page.goto(receivingPath);
        check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
            'receiving history stays inside the desktop viewport');
        await page.screenshot({ path: path.join(repoRoot, '.scratch', 'receiving-177-desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
            'receiving history scrolls within the mobile viewport');
        await page.screenshot({ path: path.join(repoRoot, '.scratch', 'receiving-177-mobile.png'), fullPage: true });
        await page.setViewportSize({ width: 1280, height: 720 });

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
        const receipt = finalState.receipts.filter((item) => item.sku === fixture.products.countable.sku).at(-1);
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
        check(historicalReceipt && JSON.stringify(finalState.receipts.find(item =>
            Number(item.receiving_id) === Number(historicalReceipt.receiving_id))) === JSON.stringify(historicalReceipt),
            'historical linked receiving records retain their quantities, cost, staff, time and document references');

        console.log('Reviewed mixed-product shopping trip');
        await page.goto(receivingPath);
        const beforeTrip = runFixture('--state', '--token', token);
        await page.selectOption('#product_id', String(fixture.products.bulk_units.product_id));
        await page.fill('#received_qty', '5');
        await page.fill('#damaged_qty', '2');
        await page.fill('#cost_price', '7.25');
        await page.fill('#batch_number', 'SHOP-UNITS-' + token);
        await page.fill('#expiration_date', '2028-12-31');
        await page.fill('#supplier', 'Shopping trip store');
        await page.fill('#invoice_number', 'SHOP-' + token);
        await page.locator('#add_receiving_line').focus();
        await page.keyboard.press('Enter');
        check(await page.locator('.receiving-line').count() === 2, 'keyboard action adds a second product line');
        await scan(page, fixture.products.bulk_pack.case_barcode);
        check(await eventually(page, id => document.getElementById('product_id_1').value === String(id),
            fixture.products.bulk_pack.product_id), 'case lookup selects the active second line without replacing the first');
        await page.fill('#received_packages_1', '2');
        check((await page.locator('#package_conversion_1').innerText()).includes('2 × 24 = 48')
            && (await page.locator('#package_conversion').innerText()).includes('3 accepted units'),
            'every line displays its pack basis and actual accepted units before saving');
        check(await page.locator('.receiving-line').nth(1).getByLabel('Product', { exact: true }).inputValue()
            === String(fixture.products.bulk_pack.product_id), 'cloned line labels remain associated with their fields');
        await scan(page, fixture.products.bulk_units.barcode);
        check(await eventually(page, () => document.getElementById('scan_status').textContent.includes('already in the shopping entry'))
            && await page.inputValue('#product_id_1') === String(fixture.products.bulk_pack.product_id)
            && await page.inputValue('#received_packages_1') === '2',
            'scanning an existing product explains duplicates and preserves both reviewed lines');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.evaluate(() => { const button = document.querySelector('.btn-submit'); button.click(); button.click(); })
        ]);
        const tripState = runFixture('--state', '--token', token);
        const tripReceipts = tripState.receipts.filter(item => item.invoice_number === 'SHOP-' + token);
        const tripSuccess = await page.locator('.message.success').innerText();
        check(tripSuccess.includes(fixture.products.bulk_units.product_name) && tripSuccess.includes('3 accepted units')
            && tripSuccess.includes(fixture.products.bulk_pack.product_name) && tripSuccess.includes('48 accepted units'),
            'one success summary identifies every product and actual accepted quantity');
        check(tripState.receipts.length === beforeTrip.receipts.length + 2 && tripReceipts.length === 2,
            'ordinary repeated clicks record one two-line shopping entry');
        check(Number(tripState.products.find(item => item.sku === fixture.products.bulk_pack.sku).quantity_on_hand) === 56
            && Number(tripState.products.find(item => item.sku === fixture.products.bulk_units.sku).quantity_on_hand) === 11,
            'mixed unit/package lines add accepted units; eight plus two cases of 24 is 56');
        check(tripReceipts.every(item => item.supplier === 'Shopping trip store'
            && Number(item.received_by) === fixture.staff.manager.user_id)
            && Number(tripReceipts.find(item => item.sku === fixture.products.bulk_pack.sku).units_per_package_used) === 24,
            'shared shopping details, staff attribution and saved pack size persist on each receiving line');
        check(tripReceipts.find(item => item.sku === fixture.products.bulk_pack.sku).cost_price === null
            && tripState.products.filter(item => [fixture.products.bulk_units.sku, fixture.products.bulk_pack.sku].includes(item.sku))
                .every(item => item.preferred_supplier === 'Original preferred supplier'),
            'mixed optional costs preserve unknown cost and preferred suppliers');

        console.log('Add/remove and empty shopping entries');
        await page.goto(receivingPath);
        await page.selectOption('#product_id', String(fixture.products.bulk_units.product_id));
        await page.fill('#received_qty', '7');
        await page.locator('#add_receiving_line').click();
        await page.selectOption('#product_id_1', String(fixture.products.bulk_units.product_id));
        check((await page.locator('#entry_status').innerText()).includes('Duplicate product'),
            'manual duplicate selection gives a clear correction message');
        await page.locator('.receiving-line').nth(1).getByRole('button', { name: 'Remove product' }).click();
        check(await page.locator('.receiving-line').count() === 1 && await page.inputValue('#received_qty') === '7',
            'removing a line preserves the other entered product and quantity');
        await page.locator('.remove-receiving-line').click();
        await page.locator('.btn-submit').click();
        check((await page.locator('#entry_status').innerText()).includes('Add at least one product'),
            'empty shopping entries show a correction message');
        await page.locator('summary').filter({ hasText: 'Optional: receive against' }).click();
        await page.getByRole('button', { name: 'Receive PO', exact: true }).first().click();
        check(await page.locator('.receiving-line').count() === 1
            && await page.inputValue('#purchase_order_item_id') !== '',
            'choosing a legacy PO creates a product line after all lines were removed');
        await page.locator('.remove-receiving-line').click();
        await page.getByRole('button', { name: 'Receive Now', exact: true }).first().click();
        check(await page.locator('.receiving-line').count() === 1
            && await page.inputValue('#replenishment_request_id') !== '',
            'choosing an approved request creates a line after all lines were removed');

        console.log('Later-line validation retains the complete entry');
        await page.goto(receivingPath);
        await page.selectOption('#product_id', String(fixture.products.bulk_units.product_id));
        await page.fill('#received_qty', '7');
        await page.fill('#damaged_qty', '1');
        await page.fill('#cost_price', '11.25');
        await page.fill('#batch_number', 'RETAIN-FIRST-' + token);
        await page.fill('#expiration_date', '2029-01-31');
        await page.fill('#supplier', 'Retain trip store');
        await page.fill('#invoice_number', 'RETAIN-' + token);
        await page.locator('#add_receiving_line').click();
        await page.selectOption('#product_id_1', String(fixture.products.bulk_pack.product_id));
        await page.selectOption('#quantity_mode_1', 'packages');
        await page.fill('#received_packages_1', '1.5');
        await page.fill('#batch_number_1', 'RETAIN-SECOND-' + token);
        await page.evaluate(() => document.getElementById('receiving_form').noValidate = true);
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.btn-submit').click()]);
        check((await page.locator('.message.error').innerText()).includes('Line 2')
            && await page.inputValue('#received_packages_1') === '1.5'
            && await page.inputValue('#received_qty') === '7' && await page.inputValue('#damaged_qty') === '1'
            && await page.inputValue('#cost_price') === '11.25'
            && await page.inputValue('#batch_number_1') === 'RETAIN-SECOND-' + token
            && await page.inputValue('#supplier') === 'Retain trip store'
            && await page.inputValue('#invoice_number') === 'RETAIN-' + token,
            'invalid later line identifies the line and retains all lines and shared optional details');
        check(JSON.stringify(runFixture('--state', '--token', token)) === JSON.stringify(tripState),
            'invalid later line rolls back earlier inventory, batch, cost, damage, history and movement writes');

        console.log('Later-line database failure rolls back the entire entry');
        await dismissBlockingDialog(page);
        await page.fill('#received_packages_1', '2');
        await page.fill('#cost_price_1', '3.25');
        const invalidControls = await page.evaluate(() => Array.from(document.getElementById('receiving_form').elements)
            .filter(field => field.willValidate && !field.validity.valid)
            .map(field => ({ name: field.name, value: field.value, message: field.validationMessage })));
        if (invalidControls.length) throw new Error('Corrected shopping form remains invalid: ' + JSON.stringify(invalidControls));
        try {
            runFixture('--fail-purchase', '--purchase-product', 'bulk_pack', '--token', token);
            await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.btn-submit').click()]);
            check(await page.locator('.message.error').count() === 1
                && await page.inputValue('#received_qty') === '7' && await page.inputValue('#received_packages_1') === '2'
                && await page.inputValue('#cost_price_1') === '3.25', 'later DB failure retains both reviewed lines for correction');
            check(JSON.stringify(runFixture('--state', '--token', token)) === JSON.stringify(tripState),
                'later persistence failure rolls back all earlier cost, damage, batch, history, movement and inventory effects');
        } finally {
            runFixture('--allow-purchase', '--token', token);
        }

        const validFirst = { product_id: fixture.products.bulk_units.product_id, received_qty: '4', cost_price: '8.50', damaged_qty: '1', batch_number: 'ROLLBACK' };
        for (const invalidSecond of [
            { product_id: fixture.products.bulk_units.product_id, received_qty: '2' },
            { product_id: fixture.products.bulk_pack.product_id, received_qty: 'malformed' },
            { product_id: fixture.products.bulk_pack.product_id, received_qty: '1.5' },
            { product_id: fixture.products.inactive.product_id, received_qty: '2' },
            { product_id: fixture.products.invalid_pack.product_id, quantity_mode: 'packages', received_packages: '1' }
        ]) {
            const invalidForm = await shoppingForm(page, [validFirst, invalidSecond], { supplier: 'Keep shopping details' });
            const response = await context.request.post(receivingPath, { form: invalidForm });
            check((await response.text()).includes('message error'), 'invalid shopping line refused: ' + JSON.stringify(invalidSecond));
            check(JSON.stringify(runFixture('--state', '--token', token)) === JSON.stringify(tripState),
                'invalid shopping entry saves nothing');
        }
        const emptyForm = await shoppingForm(page, []);
        check((await (await context.request.post(receivingPath, { form: emptyForm })).text()).includes('Add at least one product line'),
            'direct HTTP cannot save an empty shopping entry');

        console.log('Overlapping shopping receipts across independent HTTP servers');
        const secondPort = await freePort();
        const secondURL = 'http://127.0.0.1:' + secondPort;
        secondServer = spawn(phpBinary, ['-S', '127.0.0.1:' + secondPort, '-t', path.join(repoRoot, 'src', 'frontend')], {
            cwd: repoRoot, stdio: ['ignore', 'ignore', 'pipe']
        });
        secondServer.stderr.resume();
        await waitForHttp(secondURL + '/?login=1');
        const concurrentContext = await browser.newContext({ baseURL: secondURL });
        const concurrentPage2 = await concurrentContext.newPage();
        await login(concurrentPage2, fixture.staff.manager_two);
        const firstTrip = await shoppingForm(page, [
            { product_id: fixture.products.bulk_units.product_id, received_qty: '3', cost_price: '8.50' },
            { product_id: fixture.products.bulk_pack.product_id, received_qty: '4' }
        ], { invoice_number: 'CONCURRENT-A-' + token });
        const reverseTrip = await shoppingForm(concurrentPage2, [
            { product_id: fixture.products.bulk_pack.product_id, received_qty: '5' },
            { product_id: fixture.products.bulk_units.product_id, received_qty: '2', cost_price: '8.50' }
        ], { invoice_number: 'CONCURRENT-B-' + token });
        try {
            runFixture('--delay-purchase', '--purchase-product', 'bulk_units', '--token', token);
            const responses = await Promise.all([
                context.request.post(receivingPath, { form: firstTrip }),
                concurrentContext.request.post(receivingPath, { form: reverseTrip })
            ]);
            check((await responses[0].text()).includes('Shopping entry saved')
                && (await responses[1].text()).includes('Shopping entry saved'),
                'simultaneous overlapping receipts in different product orders both save');
        } finally {
            runFixture('--allow-purchase', '--token', token);
        }
        const concurrentState = runFixture('--state', '--token', token);
        check(Number(concurrentState.products.find(item => item.sku === fixture.products.bulk_units.sku).quantity_on_hand) === 16
            && Number(concurrentState.products.find(item => item.sku === fixture.products.bulk_pack.sku).quantity_on_hand) === 65,
            'both simultaneous receipts contribute to current stock without overwriting quantities');
        await context.request.post(receivingPath, { form: firstTrip });
        check(JSON.stringify(runFixture('--state', '--token', token)) === JSON.stringify(concurrentState),
            'replaying a multi-line shopping entry records no additional lines');
        await concurrentContext.close();

        await page.goto(receivingPath);
        await page.selectOption('#product_id', String(fixture.products.bulk_units.product_id));
        await page.fill('#received_qty', '5');
        await page.locator('#add_receiving_line').click();
        await page.selectOption('#product_id_1', String(fixture.products.bulk_pack.product_id));
        await page.selectOption('#quantity_mode_1', 'packages');
        await page.fill('#received_packages_1', '2');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: path.join(repoRoot, '.scratch', 'receiving-178-desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
            'multi-line entry fits the mobile viewport');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: path.join(repoRoot, '.scratch', 'receiving-178-mobile.png'), fullPage: true });

        await context.close();
        if (failures.length) {
            throw new Error(failures.join('\n'));
        }
        console.log('Stock receiving scan browser workflow: passed');
    } finally {
        if (browser) await browser.close().catch(() => {});
        if (server) server.kill();
        if (secondServer) secondServer.kill();
        if (fixture) runFixture('--cleanup', '--token', token);
    }
}

main().catch((error) => {
    console.error(error.stack || error);
    process.exit(1);
});
