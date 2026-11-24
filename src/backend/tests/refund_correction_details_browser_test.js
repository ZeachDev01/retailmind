'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

// Render the real view fragments and controller with synthetic data; no Store writes.
const source = fs.readFileSync('src/frontend/components/cashier/refund_corrections.php', 'utf8');
const table = source.slice(source.indexOf('<div class="correction-table-wrap">'), source.indexOf('<?php $rows = $reviews;'));
const drawer = source.slice(source.indexOf('<dialog id="correction-details-drawer"'), source.indexOf('</dialog>') + 9);
const script = source.slice(source.indexOf('<script>'), source.indexOf('</script>') + 9);
const prelude = String.raw`<?php
require 'src/backend/bootstrap/app.php';
use App\Services\RefundCorrectionReviewService;
function correction_e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function generate_csrf_token() { return 'fixture-token'; }
$role = 'inventory_manager'; $remote = false;
$productMap = [1 => 'Barnats']; $userMap = [2 => 'Admin', 3 => 'Inventory Manager'];
$reviews = [[
    'review_id' => 2, 'refund_id' => 2, 'correction_type' => 'stock_only',
    'status' => 'Reviewed—correction outstanding', 'cash_status' => 'not_required',
    'stock_status' => 'pending', 'reviewed_by' => 2, 'reviewed_at' => '2026-11-24 12:20:50',
    'responsible_inventory_manager_id' => 3,
    'explanation' => 'Returned Barnats was marked damaged but is restockable.',
    'proposed_correction' => json_encode(['product_id' => 1, 'quantity' => 1])
]];
?><?php if (true): ?>`;
const rendered = spawnSync(process.env.PHP_BINARY || 'php', [], {input: prelude + table + drawer + script, encoding: 'utf8'});
assert.equal(rendered.status, 0, rendered.stderr);
const styles = ['style', 'cashier', 'refund-corrections'].map(name => fs.readFileSync(`src/frontend/assets/css/${name}.css`, 'utf8')).join('\n');
const html = `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Refund correction fixture</title><style>${styles}</style></head><body class="cashier-page refund-corrections-page">${rendered.stdout}</body></html>`;
const artifacts = process.env.REFUND_CORRECTION_ARTIFACT_DIR;
if (artifacts) {
    fs.mkdirSync(artifacts, {recursive: true});
    fs.writeFileSync(path.join(artifacts, 'index.html'), html);
}

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const viewport of [{width: 390, height: 650}, {width: 1440, height: 650}, {width: 1440, height: 900}]) {
            await page.setViewportSize(viewport);
            await page.setContent(html);
            await page.locator('.correction-open-drawer').click();
            const product = page.locator('#drawer-product-val');
            assert.match(await product.innerText(), /Barnats/);
            await product.scrollIntoViewIfNeeded();
            const geometry = await product.evaluate(el => {
                const card = el.closest('.drawer-task-card').getBoundingClientRect();
                const text = el.getBoundingClientRect();
                return {cardHeight: card.height, textTop: text.top, textBottom: text.bottom, cardTop: card.top, cardBottom: card.bottom};
            });
            assert(geometry.textTop >= geometry.cardTop && geometry.textBottom <= geometry.cardBottom,
                `Product must not be clipped at ${viewport.width}x${viewport.height}: ${JSON.stringify(geometry)}`);
            assert.match(await page.locator('#drawer-quantity-val').innerText(), /Add 1 base unit to available stock/);
            if (artifacts) await page.screenshot({path: path.join(artifacts, `drawer-${viewport.width}x${viewport.height}.png`)});
            await page.locator('#correction-drawer-dismiss').click();
            assert.match(await page.locator('.correction-table tbody').innerText(), /Barnats/);
        }
        for (const [quantity, expected] of [[2, 'Add 2 base units to available stock'], [-1, 'Remove 1 base unit from available stock']]) {
            await page.locator('.correction-open-drawer').evaluate((button, quantity) => {
                const review = JSON.parse(button.dataset.review);
                const proposal = JSON.parse(review.proposed_correction);
                proposal.quantity = quantity;
                review.proposed_correction = JSON.stringify(proposal);
                button.dataset.review = JSON.stringify(review);
            }, quantity);
            await page.locator('.correction-open-drawer').click();
            assert.equal(await page.locator('#drawer-quantity-val').innerText(), expected);
            await page.locator('#correction-drawer-dismiss').click();
        }
        assert.deepEqual(errors, []);
        console.log('Refund correction details browser: passed (product and exact stock change visible on desktop/mobile)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
