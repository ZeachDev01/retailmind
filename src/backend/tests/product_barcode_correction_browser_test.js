'use strict';
const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const net = require('node:net');
const { createInterface } = require('node:readline');
const path = require('node:path');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Product barcode correction: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const fixtureScript = path.join(__dirname, 'support/theme_fixture.php');
function fixture(action, ...args) {
    const result = spawnSync('php', [fixtureScript, action, database, ...args], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
}

async function competeForBarcode() {
    const workers = ['pack', 'item'].map(claim => {
        const process = spawn('php', [fixtureScript, 'barcode_correction_claim', database, claim, 'barrier']);
        const lines = createInterface({ input: process.stdout })[Symbol.asyncIterator]();
        let stderr = '';
        process.stderr.on('data', chunk => { stderr += chunk; });
        const exited = new Promise(resolve => process.once('close', resolve));
        return { claim, process, lines, exited, error: () => stderr };
    });
    try {
        for (const worker of workers) assert.equal((await worker.lines.next()).value, 'ready', worker.error());
        for (const worker of workers) worker.process.stdin.end('go\n');
        const results = await Promise.all(workers.map(async worker => {
            const line = await worker.lines.next();
            assert.equal(await worker.exited, 0, worker.error());
            return JSON.parse(line.value);
        }));
        assert.equal(results.filter(result => result === 'saved').length, 1);
        const losing = results.findIndex(result => result !== 'saved');
        assert(['conflict', 'busy'].includes(results[losing]));
        assert.equal(fixture('barcode_correction_claim', workers[losing].claim), 'conflict', 'Retry sees the winning assignment');
        const rows = fixture('barcode_correction_state').identifiers;
        assert.equal(rows.filter(product => [product.barcode, product.pack_barcode].includes('SHARED-CLAIM')).length, 1);
    } finally {
        for (const worker of workers) worker.process.kill();
    }
}

(async () => {
    let server, browser, created = false;
    try {
        const data = fixture('create');
        created = true;
        const ids = fixture('barcode_correction_setup');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...process.env, RM_THEME_TEST_DATABASE: database }, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch();
        const login = async (page, role) => {
            page.setDefaultTimeout(5000);
            await page.route('https://**/*', route => route.fulfill({ body: '' }));
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
        };
        const page = await browser.newPage({ viewport: { width: 390, height: 900 } });
        const products = origin + '/components/inventory_management/products.php';
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));
        await login(page, 'inventory_manager');
        const open = async name => {
            await page.goto(products);
            await page.getByRole('button', { name: 'Manage ' + name, exact: true }).click();
        };
        const correction = page.getByRole('button', { name: 'Correct barcode', exact: true });
        const replacement = page.getByRole('textbox', { name: 'Replacement barcode', exact: true });
        const skuDecision = page.getByLabel('Legacy SKU decision');
        const error = page.locator('#barcode-correction-error');
        const notice = page.locator('#barcode-correction-notice');
        const save = () => page.getByRole('button', { name: 'Save corrected barcode' }).click();
        const launch = async (field = 'barcode') => {
            await correction.focus();
            await correction.press('Enter');
            const type = page.getByRole('combobox', { name: 'Barcode type' });
            assert.equal(await type.evaluate(node => node === document.activeElement), true);
            await type.selectOption(field);
        };
        const lookup = async (code, receiving = false) => {
            const response = await page.request.get(origin + '/components/barcodeScanner/apiScanner/product_code_lookup.php', {
                params: { code, context: receiving ? 'receiving' : '' }
            });
            const result = await response.json();
            assert.equal(result.success, true);
            return result;
        };

        await open('Theme Test Item');
        await launch();
        const geometry = await page.locator('#product-drawer-body').evaluate(el => ({ width: el.clientWidth, scroll: el.scrollWidth }));
        assert(geometry.scroll <= geometry.width + 1, 'Correction controls fit the mobile drawer');
        await replacement.fill('001234000001');
        await replacement.press('Enter');
        assert.equal(await error.isVisible(), false, 'Scanner Enter does not submit the correction');
        await save();
        await error.waitFor();
        assert.match(await error.innerText(), /SKU origin/i);
        assert.equal(await replacement.inputValue(), '001234000001');

        await skuDecision.selectOption('keep');
        for (const conflict of ['ACTIVE-SKU', 'inactive-sku', 'old-pack']) {
            await replacement.fill(conflict);
            await save();
            await error.waitFor();
            assert.match(await error.innerText(), /already assigned|on this product/i);
            assert.equal(await replacement.inputValue(), conflict, 'Keep the rejected draft');
        }
        assert.equal((await lookup('THEME-BARCODE')).product.product_id, 1);
        await replacement.fill('001234000001');
        await save();
        await notice.waitFor();
        assert.match(await notice.innerText(), /Reprint affected labels.*previous barcode still identifies this product as its SKU/);
        await open('Theme Test Item');
        assert.equal(await page.locator('#product-panel-info').getByText('001234000001', { exact: true }).count(), 1);
        assert.equal((await lookup('THEME-BARCODE')).matched_code_kind, 'sku');

        for (const [field, prior, code, kind, receiving] of [
            ['pack_barcode', 'OLD-PACK', 'NEW-PACK', 'pack_barcode', false],
            ['case_barcode', 'OLD-CARTON', 'NEW-CARTON', 'case_barcode', true]
        ]) {
            await launch(field);
            assert.equal(await skuDecision.isVisible(), false);
            await replacement.fill(code);
            await save();
            await notice.waitFor();
            assert.equal((await lookup(code, receiving)).matched_code_kind, kind);
            assert.equal((await lookup(prior, receiving)).outcome, 'unknown');
        }

        for (const [name, prior, code, legacy, tracks] of [
            ['Automatic', '001234000002', '009000000002', false, true],
            ['Custom', '001234000003', '009000000003', false, false],
            ['Legacy', '001234000004', '009000000004', true, true]
        ]) {
            await open(name + ' barcode fixture');
            await launch();
            assert.equal(await skuDecision.isVisible(), legacy, name + ' SKU provenance');
            if (legacy) await skuDecision.selectOption('replace');
            await replacement.fill(code);
            await save();
            await notice.waitFor();
            const message = await notice.innerText();
            assert.match(message, /Reprint affected labels/);
            assert.equal(message.includes('Update CSV files'), tracks);
            const corrected = await lookup(code);
            assert.equal(corrected.product.product_id, ids[name.toLowerCase()]);
            assert.equal(corrected.product.sku, tracks ? code : prior);
            assert.equal((await lookup(prior)).outcome, tracks ? 'unknown' : 'match');
        }

        const state = fixture('barcode_correction_state');
        assert.equal(state.stock, 10);
        assert.deepEqual(state.batch, { quantity: 10, remaining_quantity: 10, sealed_quantity: 1, loose_quantity: 4 });
        assert.deepEqual(state.sale, { product_id: 1, quantity: 1 });
        assert.equal(state.audit.length, 6);
        assert(state.audit.every(record => Number(record.user_id) === 3));
        const automaticAudit = state.audit.find(record => Number(record.record_id) === ids.automatic);
        assert.equal(JSON.parse(automaticAudit.previous_value).sku, '001234000002');
        assert.equal(JSON.parse(automaticAudit.new_value).barcode, '009000000002');
        assert.equal(JSON.parse(automaticAudit.new_value).sku, '009000000002');
        assert.deepEqual(pageErrors, []);

        const admin = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await login(admin, 'admin');
        await admin.goto(products);
        await admin.getByRole('button', { name: 'Manage Theme Test Item', exact: true }).click();
        assert.equal(await admin.getByRole('button', { name: 'Correct barcode', exact: true }).count(), 0);
        const forbidden = await admin.request.post(products, {
            form: {
                action: 'correct_barcode', csrf_token: await admin.locator('#generate-barcode-form [name=csrf_token]').inputValue(), product_id: 1,
                barcode_field: 'case_barcode', previous_barcode: 'NEW-CARTON', replacement_barcode: 'ADMIN-BARCODE'
            }
        });
        assert.equal(forbidden.status(), 403);

        const cashier = await browser.newPage();
        await login(cashier, 'cashier');
        const pos = origin + '/components/cashier/pos.php';
        await cashier.goto(pos);
        const cashierToken = await cashier.evaluate(() => {
            const script = Array.from(document.scripts).find(entry => entry.textContent.includes('const csrfToken = '));
            return JSON.parse(script.textContent.match(/const csrfToken = (.+);/)[1]);
        });
        const scan = async code => {
            const response = await cashier.request.post(pos, { form: { action: 'scan_lookup', csrf_token: cashierToken, code } });
            return response.json();
        };
        assert.match((await scan('NEW-CARTON')).message, /for receiving only/);
        assert.equal((await scan('NEW-PACK')).product.selling_form, 'pack');
        assert.match((await scan('001234000002')).message, /Product not found/);

        await competeForBarcode();
        console.log('Product barcode correction: Manage Product, conflicts, provenance, audit and concurrent claims passed.');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        if (created) fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
