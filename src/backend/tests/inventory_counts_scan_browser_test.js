'use strict';
// Browser-to-HTTP workflow for barcode-assisted Inventory Counts (ticket #79).
//
// Boots the real application behind `php -S`, creates disposable Store data
// (products with SKU / unit / case codes, zero-stock and inactive products, and
// one account per role), drives a real Chromium through the authenticated
// count page with keyboard-wedge characters plus Enter, and asserts the
// user-visible outcome together with the submitted count, approval and stock
// records. No test depends on a physical scanner.
//
// Skips (exit 0) when RUN_DB_TESTS is not 1 or no browser automation module is
// installed, matching the opt-in database integration tests.

const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '..', '..', '..');
const fixtureScript = path.join(__dirname, 'support', 'scan_workflow_fixture.php');
const countsPath = '/components/inventory_management/inventory_counts.php';
const lookupPath = '/components/barcodeScanner/apiScanner/product_code_lookup.php';
const phpBinary = process.env.PHP_BINARY || 'php';
let serverLog = '';

function skip(reason) {
    console.log('Inventory counts scan browser workflow: skipped (' + reason + ')');
    process.exit(0);
}

if (process.env.RUN_DB_TESTS !== '1') {
    skip('set RUN_DB_TESTS=1');
}

function resolvePlaywright() {
    const localCandidates = [];
    if (process.env.PLAYWRIGHT_MODULE) {
        localCandidates.push(process.env.PLAYWRIGHT_MODULE);
    }
    localCandidates.push('playwright', 'playwright-core');
    for (const candidate of localCandidates) {
        try {
            return require(candidate);
        } catch (error) {
            // Fall back to known global installation locations.
        }
    }

    const globalRoots = [];
    if (process.env.APPDATA) {
        globalRoots.push(path.join(process.env.APPDATA, 'npm', 'node_modules'));
    }
    if (process.env.NPM_CONFIG_PREFIX) {
        globalRoots.push(path.join(process.env.NPM_CONFIG_PREFIX, 'lib', 'node_modules'));
    }
    try {
        const npmCommand = process.platform === 'win32' ? 'npm.cmd' : 'npm';
        const resolved = spawnSync(npmCommand, ['root', '-g'], {
            encoding: 'utf8',
            shell: process.platform === 'win32'
        }).stdout.trim();
        if (resolved) {
            globalRoots.push(resolved);
        }
    } catch (error) {
        // npm is unavailable; the known prefixes above still get a chance.
    }
    const globalCandidates = [];
    for (const root of globalRoots) {
        for (const name of ['playwright', 'playwright-core']) {
            globalCandidates.push(path.join(root, name));
            globalCandidates.push(path.join(root, '@playwright', 'mcp', 'node_modules', name));
        }
    }
    for (const candidate of globalCandidates) {
        try {
            return require(candidate);
        } catch (error) {
            // keep looking
        }
    }
    return null;
}

const playwright = resolvePlaywright();
if (!playwright) {
    skip('playwright is not installed');
}

function resolveChromiumExecutable() {
    if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE) {
        return process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE;
    }
    try {
        const expected = playwright.chromium.executablePath();
        if (expected && fs.existsSync(expected)) {
            return expected;
        }
    } catch (error) {
        // fall through to scanning the browser registry
    }

    const roots = [];
    if (process.env.LOCALAPPDATA) {
        roots.push(path.join(process.env.LOCALAPPDATA, 'ms-playwright'));
    }
    if (process.env.USERPROFILE) {
        roots.push(path.join(process.env.USERPROFILE, 'AppData', 'Local', 'ms-playwright'));
    }
    if (process.env.HOME) {
        roots.push(path.join(process.env.HOME, '.cache', 'ms-playwright'));
    }
    const relative = process.platform === 'win32'
        ? ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe']
        : process.platform === 'darwin'
            ? ['chrome-mac/Chromium.app/Contents/MacOS/Chromium']
            : ['chrome-linux/chrome', 'chrome-linux/chrome-headless-shell'];

    const versioned = [];
    for (const root of roots) {
        if (!fs.existsSync(root)) {
            continue;
        }
        for (const entry of fs.readdirSync(root)) {
            const match = /^chromium-(\d+)$/.exec(entry);
            if (match) {
                versioned.push({ sortKey: Number(match[1]), dir: path.join(root, entry) });
            }
        }
    }
    versioned.sort((a, b) => b.sortKey - a.sortKey);
    for (const candidate of versioned) {
        for (const rel of relative) {
            const fullPath = path.join(candidate.dir, ...rel.split('/'));
            if (fs.existsSync(fullPath)) {
                return fullPath;
            }
        }
    }
    return null;
}

const chromiumExecutable = resolveChromiumExecutable();
if (!chromiumExecutable) {
    skip('no Chromium build is installed');
}

const failures = [];
function check(condition, message) {
    if (condition) {
        console.log('  ok - ' + message);
    } else {
        failures.push(message);
        console.log('  FAIL - ' + message);
    }
    return condition;
}

function runFixture(...args) {
    const result = spawnSync(phpBinary, [fixtureScript, ...args], { encoding: 'utf8', cwd: repoRoot });
    if (result.error) {
        throw new Error('Fixture could not start: ' + result.error.message);
    }
    if (result.status !== 0) {
        throw new Error('Fixture failed: ' + (result.stderr || result.stdout || result.status));
    }
    const lines = (result.stdout || '').trim().split('\n');
    const last = lines[lines.length - 1];
    if (!last) {
        throw new Error('Fixture produced no JSON on stdout (stderr: ' + (result.stderr || '') + ')');
    }
    return JSON.parse(last);
}

function freePort() {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.unref();
        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const { port } = server.address();
            server.close(() => resolve(port));
        });
    });
}

async function waitForHttp(url, timeoutMs) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        try {
            const response = await fetch(url);
            if (response.status < 500) {
                return true;
            }
        } catch (error) {
            // server not up yet
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }
    return false;
}

async function eventually(page, expression, argument, timeoutMs = 4000) {
    try {
        await page.waitForFunction(expression, argument, { timeout: timeoutMs, polling: 100 });
        return true;
    } catch (error) {
        return false;
    }
}

async function dismissBlockingDialogs(page) {
    // A fresh session can carry a one-off "database was restored" notice that
    // ui.js raises as a modal; it sits above the page and eats clicks.
    await page.waitForTimeout(500);
    for (let attempt = 0; attempt < 4; attempt += 1) {
        const confirm = page.locator('.swal2-confirm');
        if (!await confirm.count()) {
            return;
        }
        if (!await confirm.isVisible().catch(() => false)) {
            return;
        }
        await confirm.click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(250);
    }
}

async function login(page, member) {
    await page.goto('/?login=1');
    await page.waitForSelector('#landing-login-username');
    await dismissBlockingDialogs(page);
    await page.fill('#landing-login-username', member.username);
    await page.fill('#landing-login-password', member.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        page.locator('#landing-login-password').press('Enter')
    ]);
}

async function scan(page, code) {
    const input = page.locator('#product_code_scan');
    await input.fill('');
    await page.keyboard.type(code, { delay: 8 });
    await page.keyboard.press('Enter');
}

async function readJson(response) {
    try {
        return await response.json();
    } catch (error) {
        return null;
    }
}

async function main() {
    const token = 'wf' + Math.random().toString(36).slice(2, 8);
    let fixture = null;
    let server = null;
    let browser = null;

    try {
        fixture = runFixture('--create', '--token', token);
        const products = fixture.products;
        const staff = fixture.staff;
        const approvedIssue = fixture.approved_issue;

        const port = await freePort();
        const baseUrl = 'http://127.0.0.1:' + port;
        server = spawn(
            phpBinary,
            ['-S', '127.0.0.1:' + port, '-t', path.join(repoRoot, 'src', 'frontend')],
            { cwd: repoRoot, stdio: ['ignore', 'ignore', 'pipe'] }
        );
        serverLog = '';
        server.stderr.on('data', (chunk) => {
            serverLog += chunk.toString();
            if (process.env.BROWSER_TEST_DEBUG === '1') {
                process.stderr.write('[server] ' + chunk.toString());
            }
        });
        server.on('error', (error) => { serverLog += String(error); });

        if (!await waitForHttp(baseUrl + '/?login=1', 20000)) {
            throw new Error('Application server did not start:\n' + serverLog);
        }

        browser = await playwright.chromium.launch({
            executablePath: chromiumExecutable,
            headless: true,
            args: ['--no-sandbox', '--disable-dev-shm-usage']
        });

        const lookupUrl = baseUrl + lookupPath;
        const countsUrl = baseUrl + countsPath;
        const state = () => runFixture('--state', '--token', token);

        console.log('Anonymous boundaries');
        const anonymous = await browser.newContext({ baseURL: baseUrl });
        let response = await anonymous.request.get(lookupUrl + '?code=' + encodeURIComponent(products.countable.barcode));
        let body = await readJson(response);
        check(response.status() === 401, 'anonymous lookup is refused with 401');
        check(body && body.success === false && !('product' in body) && !('outcome' in body),
            'anonymous lookup exposes no product or outcome');
        response = await anonymous.request.post(lookupUrl, { form: { code: products.countable.barcode } });
        check(response.status() === 401, 'anonymous lookup cannot be reached with a write method');

        console.log('Cashier role boundary');
        const cashierContext = await browser.newContext({ baseURL: baseUrl });
        const cashierPage = await cashierContext.newPage();
        await login(cashierPage, staff.cashier);
        response = await cashierContext.request.get(lookupUrl + '?code=' + encodeURIComponent(products.countable.barcode));
        check(response.status() === 403, 'a role outside Inventory Counts cannot use the lookup');
        await cashierContext.close();
        await anonymous.close();

        console.log('Authenticated lookup outcomes');
        const context = await browser.newContext({ baseURL: baseUrl });
        const page = await context.newPage();
        if (process.env.BROWSER_TEST_DEBUG === '1') {
            page.on('console', (message) => console.log('  [console]', message.type(), message.text()));
            page.on('pageerror', (error) => console.log('  [pageerror]', error.message));
            page.on('dialog', (dialog) => {
                console.log('  [dialog]', dialog.type(), dialog.message());
                dialog.dismiss().catch(() => {});
            });
            page.on('framenavigated', (frame) => {
                if (frame === page.mainFrame()) {
                    console.log('  [nav]', frame.url());
                }
            });
            page.on('requestfailed', (request) => console.log('  [reqfail]', request.url()));
            page.on('request', (request) => console.log('  [req]', request.method(), request.url()));
        }
        await login(page, staff.manager);

        response = await context.request.get(lookupUrl + '?code=' + encodeURIComponent(products.countable.barcode));
        body = await readJson(response);
        check(response.status() === 200 && body.outcome === 'match',
            'unit barcode lookup reports a match');
        check(body.matched_code_kind === 'unit_barcode',
            'unit barcode reports the unit_barcode match kind');
        check(body.product && body.product.product_id === products.countable.product_id,
            'unit barcode resolves the labelled product');

        response = await context.request.get(lookupUrl + '?code=' + encodeURIComponent(products.countable.sku));
        body = await readJson(response);
        check(body.outcome === 'match' && body.matched_code_kind === 'sku',
            'SKU lookup reports a sku match kind');

        response = await context.request.get(lookupUrl + '?code=' + encodeURIComponent(products.countable.case_barcode));
        body = await readJson(response);
        check(body.outcome === 'match' && body.matched_code_kind === 'case_barcode',
            'case barcode reports the case_barcode match kind');

        response = await context.request.get(lookupUrl + '?code=' + encodeURIComponent(products.zero.barcode));
        body = await readJson(response);
        check(body.outcome === 'match' && body.product.quantity_on_hand === 0,
            'a zero-stock product is still resolved');

        response = await context.request.get(lookupUrl + '?code=' + encodeURIComponent(products.inactive.sku));
        body = await readJson(response);
        check(body.outcome === 'unknown' && body.product === null,
            'an inactive product is not resolved');

        response = await context.request.get(lookupUrl + '?code=' + encodeURIComponent('NOPE' + token + 'ZZ'));
        body = await readJson(response);
        check(body.outcome === 'unknown' && body.product === null,
            'an unknown code reports unknown without a product');

        const sharedCode = products.ambiguous_a.barcode;
        response = await context.request.get(lookupUrl + '?code=' + encodeURIComponent(sharedCode));
        body = await readJson(response);
        check(body.outcome === 'ambiguous' && body.product === null,
            'an ambiguous code refuses to choose a product');
        check(Array.isArray(body.candidates) && body.candidates.length === 2,
            'an ambiguous code lists both candidates');

        response = await context.request.get(lookupUrl + '?code=');
        check(response.status() === 400, 'a blank code is refused with 400');

        response = await context.request.post(lookupUrl, { form: { code: sharedCode } });
        check(response.status() === 405, 'the lookup endpoint refuses write methods with 405');

        await page.goto(countsPath);
        check(await eventually(page, () => document.getElementById('product_code_scan') !== null),
            'Inventory Counts renders the scan field');

        console.log('Partly filled counts protect entered work from conflicting scans');
        await page.selectOption('#product_id', String(products.countable.product_id));
        await page.fill('#physical_quantity', '12');
        await page.fill('#discrepancy_reason', 'Counted shelf and back-room units twice.');
        const previewBeforeReplacement = await page.locator('.quantity-preview').innerText();
        let replacementPrompt = '';
        page.once('dialog', async (dialog) => {
            replacementPrompt = dialog.message();
            await dialog.dismiss();
        });
        await scan(page, products.zero.barcode);
        await page.waitForTimeout(250);
        check(replacementPrompt.includes(products.zero.product_name),
            'a different matched product asks before replacing a partly filled count');
        check(await page.inputValue('#product_id') === String(products.countable.product_id),
            'cancelling the replacement preserves the selected product');
        check(await page.inputValue('#physical_quantity') === '12',
            'cancelling the replacement preserves the counted total');
        check(await page.inputValue('#discrepancy_reason') === 'Counted shelf and back-room units twice.',
            'cancelling the replacement preserves the discrepancy reason');
        check(await page.locator('.quantity-preview').innerText() === previewBeforeReplacement,
            'cancelling the replacement preserves the quantity preview');

        page.once('dialog', (dialog) => dialog.accept());
        await scan(page, products.zero.barcode);
        check(await eventually(page, (id) => document.getElementById('product_id').value === String(id),
            products.zero.product_id),
            'confirming the replacement selects the newly scanned product');

        console.log('Linked correction counts accept only their Stock Issue product');
        await page.goto(countsPath + '?correct=' + approvedIssue.adjustment_id);
        check(await eventually(page, (id) =>
            document.getElementById('product_id').value === String(id), approvedIssue.product_id),
            'the linked correction starts on the approved Stock Issue product');
        await page.fill('#physical_quantity', '11');
        await page.fill('#discrepancy_reason', 'Separate recount correcting the approved Stock Issue.');
        const linkedPreview = await page.locator('.quantity-preview').innerText();
        const linkedAdjustmentId = await page.locator('input[name="related_adjustment_id"]').inputValue();
        await scan(page, products.zero.barcode);
        await eventually(page, () => document.getElementById('scan_status').textContent.indexOf('linked Stock Issue') !== -1);
        check(await page.inputValue('#product_id') === String(approvedIssue.product_id),
            'a conflicting linked scan does not change the selected product');
        check(await page.inputValue('#physical_quantity') === '11',
            'a conflicting linked scan preserves the counted total');
        check(await page.inputValue('#discrepancy_reason') === 'Separate recount correcting the approved Stock Issue.',
            'a conflicting linked scan preserves the discrepancy reason');
        check(await page.locator('.quantity-preview').innerText() === linkedPreview,
            'a conflicting linked scan preserves the quantity preview');
        check(await page.locator('input[name="related_adjustment_id"]').inputValue() === linkedAdjustmentId,
            'a conflicting linked scan preserves the approved Stock Issue association');

        await scan(page, products.countable.sku);
        check(await eventually(page, () => document.activeElement && document.activeElement.id === 'physical_quantity'),
            'a matching linked scan may return focus to the counted total');
        check(await page.inputValue('#physical_quantity') === '11',
            'a matching linked scan never sets the counted total');

        check(await page.locator('.count-form').evaluate((form) => form.checkValidity()),
            'the protected linked correction remains valid for submission');
        const linkedCsrf = await page.locator('.count-form input[name="csrf_token"]').inputValue();
        response = await context.request.post(countsUrl + '?correct=' + approvedIssue.adjustment_id, {
            form: {
                csrf_token: linkedCsrf,
                action: 'record',
                related_adjustment_id: String(approvedIssue.adjustment_id),
                product_id: String(products.zero.product_id),
                physical_quantity: '11',
                discrepancy_reason: 'A tampered linked correction must be refused.'
            }
        });
        check(response.status() === 200, 'the mismatched linked submission is handled without an unsafe write');
        check(state().counts.length === 0, 'server-side mismatch validation refuses the other product');

        await dismissBlockingDialogs(page);
        const linkedNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
        await page.locator('.count-form button[type="submit"]').click();
        await linkedNavigation;
        check(await eventually(page, () => document.body.innerText.indexOf('sent for approval') !== -1),
            'the linked correction records a separate pending count');
        let linkedSnapshot = state();
        const linkedCount = linkedSnapshot.counts.find((row) =>
            Number(row.related_adjustment_id) === approvedIssue.adjustment_id);
        check(linkedCount && Number(linkedCount.product_id) === approvedIssue.product_id,
            'the pending correction keeps the approved Stock Issue product and association');
        check(linkedCount && linkedCount.status === 'pending',
            'the separate linked correction remains pending');

        response = await context.request.post(countsUrl + '?correct=' + approvedIssue.adjustment_id, {
            form: {
                csrf_token: linkedCsrf,
                action: 'reject',
                count_id: String(linkedCount.count_id)
            }
        });
        linkedSnapshot = state();
        check(response.status() === 200
            && linkedSnapshot.counts.find((row) => row.count_id === linkedCount.count_id).status === 'rejected',
            'the existing decision lifecycle can reject the separate linked count');

        console.log('Enter-terminated scanning on the count form');
        await page.goto(countsPath);
        await scan(page, products.countable.barcode);
        check(await eventually(page, (id) => document.getElementById('product_id').value === String(id),
            products.countable.product_id),
            'unit barcode scan selects the product');
        check(await eventually(page, () => document.activeElement && document.activeElement.id === 'physical_quantity'),
            'a match focuses the physical quantity field');
        check(await page.locator('#scan_match').isVisible(), 'the matched product identity is visible');
        const identity = await page.locator('#scan_match_name').textContent();
        check(identity.includes(products.countable.sku), 'the identity shows the scanned SKU');
        check(await page.locator('#systemQty').textContent() === '10',
            'the system-versus-physical preview shows the system quantity');
        check(await page.inputValue('#physical_quantity') === '',
            'a scan never writes a counted total');

        await scan(page, products.zero.sku);
        check(await eventually(page, (id) => document.getElementById('product_id').value === String(id),
            products.zero.product_id),
            'SKU scan of a zero-stock product selects it');
        check(await page.locator('#systemQty').textContent() === '0',
            'the preview reports a zero system quantity');
        check(await page.inputValue('#physical_quantity') === '',
            'a zero-stock scan still leaves the counted total empty');

        await scan(page, products.countable.case_barcode);
        check(await eventually(page, (id) => document.getElementById('product_id').value === String(id),
            products.countable.product_id),
            'case barcode scan selects the product');

        console.log('Unknown and ambiguous codes leave the form alone');
        await page.selectOption('#product_id', String(products.zero.product_id));
        await page.fill('#physical_quantity', '7');
        await page.fill('#discrepancy_reason', 'Preserve this entered explanation.');
        const beforeUnknown = await page.inputValue('#product_id');
        const previewBeforeUnknown = await page.locator('.quantity-preview').innerText();
        await scan(page, 'NOPE' + token + 'ZZ');
        await eventually(page, () => document.getElementById('scan_status').textContent.indexOf('No product matches') !== -1);
        const unknownStatus = await page.locator('#scan_status').textContent();
        check(unknownStatus.indexOf('No product matches') !== -1, 'an unknown code shows a clear retry message');
        check(await page.inputValue('#product_id') === beforeUnknown, 'an unknown code does not change the product');
        check(await page.inputValue('#physical_quantity') === '7', 'an unknown code does not change the counted total');
        check(await page.inputValue('#discrepancy_reason') === 'Preserve this entered explanation.',
            'an unknown code does not change the discrepancy reason');
        check(await page.locator('.quantity-preview').innerText() === previewBeforeUnknown,
            'an unknown code does not change the quantity preview');

        await scan(page, sharedCode);
        await eventually(page, () => document.getElementById('scan_status').textContent.indexOf('more than one product') !== -1);
        const ambiguousStatus = await page.locator('#scan_status').textContent();
        check(ambiguousStatus.indexOf('more than one product') !== -1, 'an ambiguous code shows a clear message');
        check(await page.inputValue('#product_id') === beforeUnknown, 'an ambiguous code does not change the product');
        check(await page.inputValue('#physical_quantity') === '7', 'an ambiguous code does not change the counted total');
        check(await page.inputValue('#discrepancy_reason') === 'Preserve this entered explanation.',
            'an ambiguous code does not change the discrepancy reason');
        check(await page.locator('.quantity-preview').innerText() === previewBeforeUnknown,
            'an ambiguous code does not change the quantity preview');

        await scan(page, products.inactive.sku);
        await eventually(page, () => document.getElementById('scan_status').textContent.indexOf('No product matches') !== -1);
        check(await page.inputValue('#product_id') === beforeUnknown, 'an inactive product is not selected by scan');

        console.log('Repeated scans never touch the counted total');
        await page.selectOption('#product_id', String(products.zero.product_id));
        page.once('dialog', (dialog) => dialog.accept());
        await scan(page, products.countable.barcode);
        check(await eventually(page, (expected) =>
            document.getElementById('product_id').value === String(expected)
                && document.getElementById('scan_status').textContent.indexOf('Matched by') !== -1,
            products.countable.product_id),
            'a repeated scan re-selects the product');
        check(await page.inputValue('#physical_quantity') === '7',
            'a repeated scan leaves the counted total at 7');

        // The wedge types into whatever holds focus; after a match that is the
        // physical quantity field. The burst must be read as a scan and the
        // operator's total restored, not overwritten by the barcode digits.
        // A different product is selected first, so the assertion can only pass
        // when this scan's own answer - not the previous selection - arrives.
        await page.selectOption('#product_id', String(products.zero.product_id));
        page.once('dialog', (dialog) => dialog.accept());
        await page.keyboard.type(products.countable.barcode, { delay: 6 });
        await page.keyboard.press('Enter');
        check(await eventually(page, (expected) =>
            document.getElementById('product_id').value === String(expected)
                && document.getElementById('scan_status').textContent.indexOf('Matched by') !== -1,
            products.countable.product_id),
            'a scan landing in the focused quantity field still selects the product');
        check(await page.inputValue('#physical_quantity') === '7',
            'a scan landing in the focused quantity field never sets the total');

        // A short code cannot lean on the burst-length threshold: any buffer
        // that is not a plain counted total must still be read as a scan.
        console.log('A short code in the counted total field is still a scan');
        page.once('dialog', (dialog) => dialog.accept());
        await page.keyboard.type(products.short.sku, { delay: 6 });
        await page.keyboard.press('Enter');
        check(await eventually(page, (expected) =>
            document.getElementById('product_id').value === String(expected)
                && document.getElementById('scan_status').textContent.indexOf('Matched by') !== -1,
            products.short.product_id),
            'a short code in the counted total field still selects the product');
        check(await page.inputValue('#physical_quantity') === '7',
            'a short code still never sets the counted total');

        console.log('A short numeric code in the counted total field is still a scan');
        await page.selectOption('#product_id', String(products.zero.product_id));
        page.once('dialog', (dialog) => dialog.accept());
        await page.keyboard.type(products.short_numeric.sku, { delay: 6 });
        await page.keyboard.press('Enter');
        check(await eventually(page, (expected) =>
            document.getElementById('product_id').value === String(expected)
                && document.getElementById('scan_status').textContent.indexOf('Matched by') !== -1,
            products.short_numeric.product_id),
            'a short numeric code in the counted total field still selects the product');
        check(await page.inputValue('#physical_quantity') === '7',
            'a short numeric code never sets the counted total');

        console.log('The newest scan result wins when lookups overlap');
        const delayedCode = products.countable.sku;
        const newerUnknownCode = 'LATER' + token + 'NOPE';
        const delayedLookupPattern = '**/product_code_lookup.php?code=*';
        await page.route(delayedLookupPattern, async (route) => {
            const code = new URL(route.request().url()).searchParams.get('code');
            if (code === delayedCode) {
                await new Promise((resolve) => setTimeout(resolve, 350));
            }
            await route.continue();
        });
        await page.selectOption('#product_id', String(products.zero.product_id));
        const delayedResponse = page.waitForResponse((candidate) =>
            new URL(candidate.url()).searchParams.get('code') === delayedCode);
        const newerResponse = page.waitForResponse((candidate) =>
            new URL(candidate.url()).searchParams.get('code') === newerUnknownCode);
        await scan(page, delayedCode);
        await scan(page, newerUnknownCode);
        await Promise.all([delayedResponse, newerResponse]);
        await page.waitForTimeout(150);
        check((await page.locator('#scan_status').textContent()).indexOf('No product matches') !== -1,
            'a stale earlier match cannot replace the newer unknown result');
        check(await page.inputValue('#product_id') === String(products.zero.product_id),
            'a stale earlier match cannot change the newer scan form state');
        await page.unroute(delayedLookupPattern);

        console.log('Manual dropdown selection still works');
        const lookupSettled = await eventually(page,
            () => document.getElementById('scan_status').textContent.indexOf('Checking') === -1);
        check(lookupSettled, 'the scan lookup settled before the manual selection');
        await page.selectOption('#product_id', String(products.zero.product_id));
        const dropdownPreviewReady = await eventually(page,
            () => document.getElementById('systemQty').textContent === '0');
        const dropdownSystemQty = await page.locator('#systemQty').textContent();
        check(dropdownPreviewReady,
            'the dropdown still drives the preview (got ' + JSON.stringify(dropdownSystemQty) + ')');
        check(await page.inputValue('#physical_quantity') === '7',
            'changing the dropdown does not alter the counted total');

        console.log('A scan alone records nothing');
        let snapshot = state();
        check(snapshot.counts.length === 1, 'scanning alone adds no count beyond the linked correction');

        console.log('Count submission and approval');
        await page.selectOption('#product_id', String(products.countable.product_id));
        await page.fill('#physical_quantity', '15');
        await page.fill('#discrepancy_reason', 'Scan workflow reconciliation of the counted shelf.');
        await dismissBlockingDialogs(page);
        const submitNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
        let submitClickError = null;
        await page.locator('.count-form button[type="submit"]').click({ timeout: 15000 })
            .catch((error) => { submitClickError = error; });
        if (submitClickError) {
            throw submitClickError;
        }
        await submitNavigation;
        check(await eventually(page, () => document.body.innerText.indexOf('sent for approval') !== -1),
            'the count is recorded and stays pending');

        snapshot = state();
        check(snapshot.counts.length === 2, 'the ordinary submission adds exactly one count');
        const recorded = snapshot.counts.find((row) => !row.related_adjustment_id);
        check(recorded.status === 'pending', 'the submitted count is pending');
        check(Number(recorded.system_quantity) === 10, 'the count captured the system quantity on submission');
        check(Number(recorded.physical_quantity) === 15, 'the count stored the entered physical total');
        check(Number(recorded.difference_qty) === 5, 'the count stored the difference');
        const countableState = snapshot.products.find((row) => row.sku === products.countable.sku);
        check(Number(countableState.quantity_on_hand) === 10,
            'stock is unchanged until the existing approval runs');

        const pendingRow = page.locator('tbody tr', { hasText: products.countable.sku }).first();
        await dismissBlockingDialogs(page);
        const approveButton = pendingRow.locator('button', { hasText: 'Approve' }).first();
        // The waiter is armed before the click so a fast local response cannot
        // slip past it, and a click failure is reported instead of surfacing
        // as an unrelated navigation timeout.
        const approveNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
        let approveClickError = null;
        await approveButton.click({ timeout: 15000 }).catch((error) => { approveClickError = error; });
        if (approveClickError) {
            throw approveClickError;
        }
        await approveNavigation;
        check(await eventually(page, () => document.body.innerText.indexOf('approved and stock reconciled') !== -1),
            'the existing approval control reconciles the count');

        snapshot = state();
        check(recorded && state().counts.find((row) => row.count_id === recorded.count_id).status === 'approved',
            'the approved count is recorded as approved');
        const approvedState = snapshot.products.find((row) => row.sku === products.countable.sku);
        check(Number(approvedState.quantity_on_hand) === 15,
            'approval reconciles stock to the counted total');
        await context.close();

        console.log('Administrator read-only access');
        const adminContext = await browser.newContext({ baseURL: baseUrl });
        const adminPage = await adminContext.newPage();
        await login(adminPage, staff.admin);
        await adminPage.goto(countsPath);
        check(await eventually(adminPage, () => document.getElementById('product_code_scan') !== null),
            'Administrators can still open Inventory Counts');

        response = await adminContext.request.get(lookupUrl + '?code=' + encodeURIComponent(products.zero.barcode));
        body = await readJson(response);
        check(response.status() === 200 && body.outcome === 'match',
            'Administrator read-only lookup still identifies products');

        await scan(adminPage, products.zero.barcode);
        check(await eventually(adminPage, (id) => document.getElementById('product_id').value === String(id),
            products.zero.product_id),
            'Administrator scanning selects a product without gaining a submit path');

        check(await adminPage.locator('.count-form button[type="submit"]').count() === 0,
            'Administrator read-only access renders no Record Count control');
        check(await adminPage.locator('.count-actions button').count() === 0,
            'Administrator read-only access renders no approval or rejection controls');

        await adminPage.goto(countsPath + '?correct=' + approvedIssue.adjustment_id);
        check((await adminPage.locator('.message').first().innerText()).includes('read-only'),
            'the linked correction view explains Administrator read-only access');
        check(await adminPage.locator('input[name="related_adjustment_id"]').count() === 0,
            'the Administrator linked view exposes no correction submission association');

        response = await adminContext.request.post(countsUrl, {
            form: {
                csrf_token: 'administrator-has-no-mutation-form',
                action: 'record',
                product_id: String(products.zero.product_id),
                physical_quantity: '3',
                discrepancy_reason: 'Administrator must not create counts.'
            }
        });
        check(response.status() === 403, 'Administrator count submission is refused with 403');
        snapshot = state();
        check(snapshot.counts.length === 2, 'Administrator access created no count');
        await adminContext.close();
    } finally {
        if (browser) {
            try { await browser.close(); } catch (error) { /* ignore */ }
        }
        if (server) {
            server.kill();
        }
        try { runFixture('--cleanup', '--token', token); } catch (error) {
            failures.push('disposable Store data could not be cleaned up: ' + error.message);
        }
    }

    if (failures.length) {
        writeFailures(failures);
        process.exit(1);
    }
    console.log('Inventory counts scan browser workflow: passed');
}

function writeFailures(messages) {
    process.stderr.write(
        'Inventory counts scan browser workflow failed:\n- ' + messages.join('\n- ') + '\n'
    );
}

main().catch((error) => {
    process.stderr.write('Inventory counts scan browser workflow failed: ' + (error && error.stack ? error.stack : error) + '\n');
    const lines = serverLog.trim().split(/\r?\n/);
    if (lines.length > 1 || lines[0]) {
        process.stderr.write('Server log tail:\n' + lines.slice(-40).join('\n') + '\n');
    }
    process.exit(1);
});
