'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const source = fs.readFileSync(path.join(frontend, 'components/report/stock_receiving.php'), 'utf8');

    const markupStart = source.indexOf('<div class="receiving-form"');
    const markupEnd = source.indexOf('</dialog>', markupStart) + '</dialog>'.length;
    let markup = source.slice(markupStart, markupEnd);
    markup = markup.replace(/<\?php[\s\S]*?\?>/g, '').replace(/<\?=[\s\S]*?\?>/g, '');

    const firstScriptEnd = source.indexOf('</script>');
    const scriptStart = source.indexOf('<script>', firstScriptEnd) + '<script>'.length;
    const scriptEnd = source.indexOf('</script>', scriptStart);
    let script = source.slice(scriptStart, scriptEnd);
    script = script
        .replace(/<\?=\s*json_encode\(\(bool\)\$canReceive\)\s*\?>/g, 'true')
        .replace(/<\?=\s*json_encode\(\(string\)\$_SESSION\['user_id'\]\)\s*\?>/g, '"1"')
        .replace(/<\?=\s*json_encode\(\$formValues[\s\S]*?\)\s*\?>/g, '{}')
        .replace(/<\?=\s*json_encode\(\$message\s*!==\s*''\)\s*\?>/g, 'false')
        .replace(/<\?=\s*json_encode\(\$_SERVER\['REQUEST_METHOD'\]\s*===\s*'GET'\)\s*\?>/g, 'true');
    const unhandledPhp = script.match(/<\?[\s\S]*?\?>/g);
    if (unhandledPhp) {
        throw new Error('Unhandled PHP tags in script: ' + unhandledPhp.join(', '));
    }

    const testProducts = [
        {
            product_id: 101,
            product_name: 'Chocolate Biscuits 12s',
            sku: 'BISCUIT-12PK',
            barcode: 'BISCUIT-UNIT-BC',
            pack_barcode: 'BISCUIT-PACK-BC',
            case_barcode: 'BISCUIT-CASE-BC',
            pack_size: 12,
            units_per_package: 24,
            base_unit: 'piece',
            receiving_unit: 'case',
            category_id: 1,
            category_name: 'Snacks',
            cost_price: 10.00,
            quantity_on_hand: 50
        }
    ];


    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const errors = [];
        page.on('pageerror', err => errors.push(err.message));

        const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>
            <script id="receiving_products_data" type="application/json">${JSON.stringify(testProducts)}</script>
            ${markup}
        </body></html>`;

        await page.route('http://stock-receiving.test/**', async route => {
            const url = new URL(route.request().url());
            if (url.pathname.includes('product_code_lookup.php')) {
                const code = url.searchParams.get('code');
                if (code === 'BISCUIT-PACK-BC') {
                    return route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({
                            success: true,
                            matched_code_kind: 'pack_barcode',
                            product: testProducts[0]
                        })
                    });
                }
                if (code === 'BISCUIT-UNIT-BC') {
                    return route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({
                            success: true,
                            matched_code_kind: 'unit_barcode',
                            product: testProducts[0]
                        })
                    });
                }
                return route.fulfill({ status: 404, body: JSON.stringify({ success: false }) });
            }
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
        });

        await page.goto('http://stock-receiving.test/');
        await page.evaluate(() => {
            window.RetailMindUI = { toast: () => {} };
        });

        await page.addScriptTag({ content: script });

        assert.equal(errors.length, 0, 'No JavaScript execution errors on load: ' + errors.join('; '));

        // 1. Add line for product with pack_size = 12
        await page.evaluate(() => {
            addReceivingLine({ product_id: 101 });
        });

        const line = page.locator('#receiving_lines .receiving-line').first();
        assert.ok(await line.isVisible(), 'Receiving line is added');
        assert.equal(await line.locator('[data-field="quantity_mode"]').inputValue(), 'packs', 'Default mode for pack-enabled product is packs');

        // 2. Enter 2 packs and 4 loose pieces
        const receivedPacks = line.locator('[data-field="received_packs"]');
        const receivedLoose = line.locator('[data-field="received_loose"]');
        await receivedPacks.fill('2');
        await receivedLoose.fill('4');

        const submitted = await page.evaluate(() => Object.fromEntries(new FormData(document.getElementById('receiving_form'))));
        assert.equal(submitted['lines[0][quantity_mode]'], 'packs', 'Form submits the sealed-pack quantity mode');
        assert.equal(submitted['lines[0][received_packs]'], '2', 'Form submits sealed packs');
        assert.equal(submitted['lines[0][received_loose]'], '4', 'Form submits loose pieces');

        // 3. Verify package conversion element text
        const conversionText = await line.locator('[data-field="package_conversion"]').textContent();
        assert.equal(conversionText, 'Accepted: 28 units (2 packs, 4 loose pieces).', 'Conversion text shows accepted packs and loose pieces');

        // 4. Verify delivery summary reflects 28 accepted units and 28 received units
        assert.equal(await page.locator('#summary_accepted').textContent(), '28', 'Delivery summary shows 28 accepted units');
        assert.equal(await page.locator('#summary_received').textContent(), '28', 'Delivery summary shows 28 received units');

        // 5. Test rejected and damaged fields
        const damagedPacks = line.locator('[data-field="damaged_packs"]');
        const rejectedPacks = line.locator('[data-field="rejected_packs"]');
        await damagedPacks.fill('1');
        await rejectedPacks.fill('1');

        const updatedConversion = await line.locator('[data-field="package_conversion"]').textContent();
        assert.equal(updatedConversion, 'Accepted: 4 units (0 packs, 4 loose pieces).', 'Conversion excludes damaged and rejected packs');
        assert.equal(await page.locator('#summary_accepted').textContent(), '4', 'Delivery summary reflects 4 accepted units');
        assert.equal(await page.locator('#summary_damaged').textContent(), '12', 'Delivery summary reflects 12 damaged units');

        // 6. Test validation error when damaged + rejected > received
        await rejectedPacks.fill('2'); // 1 damaged + 2 rejected = 3 > 2 received
        const isValidOverPacks = await page.evaluate(() => validateQuantities());
        assert.equal(isValidOverPacks, false, 'Validation fails when damaged + rejected > received packs');
        const errorTextPacks = await page.locator('#entry_status').textContent();
        assert.ok(errorTextPacks.includes('Damaged and rejected packs cannot exceed received packs.'), 'Error message for excessive packs');

        // Reset rejected packs to valid
        await rejectedPacks.fill('1');
        assert.equal(await page.evaluate(() => validateQuantities()), true, 'Validation passes when counts are within bounds');

        // Test excessive loose pieces: 1 damaged loose + 4 rejected loose = 5 > 4 received loose
        const damagedLoose = line.locator('[data-field="damaged_loose"]');
        const rejectedLoose = line.locator('[data-field="rejected_loose"]');
        await damagedLoose.fill('1');
        await rejectedLoose.fill('4');
        const isValidOverLoose = await page.evaluate(() => validateQuantities());
        assert.equal(isValidOverLoose, false, 'Validation fails when damaged + rejected > received loose pieces');
        const errorTextLoose = await page.locator('#entry_status').textContent();
        assert.ok(errorTextLoose.includes('Damaged and rejected loose pieces cannot exceed received loose pieces.'), 'Error message for excessive loose pieces');

        // Reset loose pieces
        await rejectedLoose.fill('1');
        assert.equal(await page.evaluate(() => validateQuantities()), true, 'Validation passes after correcting loose pieces');

        // 7. Test barcode scanning and focus routing
        // Clear existing line
        await page.evaluate(() => {
            document.getElementById('receiving_lines').innerHTML = '';
        });

        // Scan pack barcode -> should add line and focus received_packs
        await page.evaluate(async () => {
            await identifyReceivingProduct('BISCUIT-PACK-BC');
        });
        const isPacksFocused = await page.evaluate(() => {
            const active = document.activeElement;
            return active && active.getAttribute('data-field') === 'received_packs';
        });
        assert.ok(isPacksFocused, 'Pack barcode scan focuses received_packs entry field');

        // Scan unit barcode on existing line -> should focus received_loose
        await page.evaluate(async () => {
            await identifyReceivingProduct('BISCUIT-UNIT-BC');
        });
        const isLooseFocused = await page.evaluate(() => {
            const active = document.activeElement;
            return active && active.getAttribute('data-field') === 'received_loose';
        });
        assert.ok(isLooseFocused, 'Unit barcode scan focuses received_loose entry field');

        console.log('Sales pack receiving browser test: all checks passed');
    } finally {
        await browser.close();
    }
})().catch(err => {
    console.error(err);
    process.exitCode = 1;
});
