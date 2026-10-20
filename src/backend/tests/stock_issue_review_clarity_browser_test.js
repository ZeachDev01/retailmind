const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/stock_issues.php'), 'utf8');
const template = source.slice(source.indexOf('<body')).replace(/<\?php include[^?]+\?>/g, '');
const render = empty => execFileSync(process.env.PHP_BINARY || 'php', [], {encoding: 'utf8', input: `<?php
function app_url($path) { return $path; }
function generate_csrf_token() { return 'test-token'; }
function format_display_datetime($value) { return $value; }
$message = $error = '';
$report = ['adjustment_id' => 1, 'product_id' => 1, 'product_name' => 'Coffee & Milk <250 g>',
    'sku' => str_repeat('LONG-SKU-', 8), 'adjustment_type' => 'other', 'adjustment_qty' => -3,
    'quantity_on_hand' => 12, 'cashier_name' => 'Cathy Cashier', 'shift_id' => 4,
    'reported_at' => '2026-10-20 09:30:00', 'reason' => 'Packaging seal is broken.',
    'approved_at' => '2026-10-20 10:00:00', 'reviewer_name' => 'Manny Manager',
    'review_notes' => 'Please confirm the category.', 'status' => 'approved'];
$shortReport = array_replace($report, ['adjustment_id' => 2, 'adjustment_type' => 'damaged', 'quantity_on_hand' => 1]);
$pending = ${empty ? '[]' : '[$report, $shortReport]'};
$pendingCount = count($pending);
$returned = ${empty ? '[]' : '[$report]'};
$reviews = ${empty ? '[]' : '[$report, array_replace($report, ["adjustment_id" => 3, "status" => "rejected"])]'};
$revisionsFor = static fn($id) => [['revision_id' => 1, 'action' => 'submitted', 'actor_id' => 4,
    'created_at' => '2026-10-20 09:30:00', 'old_status' => '', 'new_status' => 'pending']];
$stockIssueService = new class {
    public function getProductBatches($id) { return [['batch_id' => 1, 'batch_number' => 'COFFEE-01', 'expiration_date' => '2026-11-01', 'remaining_quantity' => 12]]; }
    public function recommendBatchAllocation($id, $qty) { return [1 => 3]; }
    public function getReportBatches($id) { return [['batch_id' => 1, 'batch_number' => 'COFFEE-01', 'expiration_date' => '2026-11-01', 'quantity' => 3]]; }
};
?>${template}`});

(async () => {
    const browser = await chromium.launch({headless: true});
    const output = path.resolve('output/playwright/stock-issue-reviews');
    fs.mkdirSync(output, {recursive: true});
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            await page.setContent('<!doctype html><html><head></head>' + render(false));
            for (const file of ['style.css', 'reports.css', 'stock-issues.css', 'theme.css']) {
                await page.addStyleTag({path: path.join(frontend, 'assets/css', file)});
            }
            assert.equal(await page.locator('.adjustment-card.pending').count(), 2);
            assert.equal(await page.locator('.adjustment-product').first().textContent(), 'Coffee & Milk <250 g>', 'Product text stays escaped');
            const approve = page.getByRole('button', {name: 'Approve & deduct 3', exact: true});
            assert.equal(await approve.first().isEnabled(), true);
            assert.equal(await approve.nth(1).isDisabled(), true, 'Insufficient stock blocks approval');
            assert.equal(await page.getByText('Not enough stock to approve. Reject or return this report.').isVisible(), true);
            assert.equal(await page.getByLabel('Review note', {exact: true}).count(), 2);
            assert.equal(await page.getByLabel('Category when returning (optional)').count(), 1, 'Only Other reports allow recategorization');
            assert.equal(await page.locator('.issue-history[open]').count(), 0, 'Past reports start collapsed');
            const batchSummary = page.getByText('Review batch deductions', {exact: true}).first();
            await batchSummary.focus();
            await page.keyboard.press('Enter');
            assert.equal(await page.getByLabel('Deduct from batch COFFEE-01', {exact: true}).first().isVisible(), true);
            const payload = await page.locator('.review-form').first().evaluate(form => Object.fromEntries(new FormData(form)));
            assert.deepEqual(payload, {csrf_token: 'test-token', adjustment_id: '1', review_notes: '', new_category: '', 'batches[1]': '3'}, 'Collapsed batch choices retain their submitted allocation');
            await page.keyboard.press('Enter');
            assert.equal(await page.getByLabel('Deduct from batch COFFEE-01', {exact: true}).first().isVisible(), false);
            for (const theme of ['light', 'dark']) {
                await page.locator('html').evaluate((el, theme) => { el.dataset.theme = theme; }, theme);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px: no page overflow`);
                await page.screenshot({path: path.join(output, `${theme}-${width}.png`), fullPage: true, animations: 'disabled'});
                if (theme === 'dark') {
                    const secondary = await page.locator('.review-actions .btn-secondary').first().evaluate(node => {
                        const style = getComputedStyle(node);
                        return {background: style.backgroundColor, color: style.color};
                    });
                    assert.deepEqual(secondary, {background: 'rgb(28, 44, 67)', color: 'rgb(229, 237, 248)'}, 'Secondary actions use readable dark theme colors');
                }
                await page.locator('.issue-history > summary, .issue-batches > summary').evaluateAll(nodes => nodes.forEach(node => { node.parentElement.open = true; }));
                assert.equal(await page.getByRole('link', {name: 'Correct via inventory count'}).getAttribute('href'), 'components/inventory_management/inventory_counts.php?correct=1');
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px: expanded content stays within viewport`);
                await page.screenshot({path: path.join(output, `${theme}-${width}-expanded.png`), fullPage: true, animations: 'disabled'});
                await page.locator('.issue-history, .issue-batches').evaluateAll(nodes => nodes.forEach(node => { node.open = false; }));
            }
            await page.setContent('<!doctype html><html><head></head>' + render(true));
            for (const file of ['style.css', 'reports.css', 'stock-issues.css', 'theme.css']) {
                await page.addStyleTag({path: path.join(frontend, 'assets/css', file)});
            }
            assert.equal(await page.locator('.page-subtitle').count(), 0);
            assert.equal(await page.getByRole('heading', {name: 'No reports to review', exact: true}).isVisible(), true);
            assert.equal(await page.locator('.issue-empty-icon[aria-hidden="true"]').isVisible(), true);
            assert.equal(await page.locator('.review-form, .issue-history').count(), 0);
            for (const theme of ['light', 'dark']) {
                await page.locator('html').evaluate((el, theme) => { el.dataset.theme = theme; }, theme);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px: empty state fits viewport`);
                await page.screenshot({path: path.join(output, `empty-${theme}-${width}.png`), fullPage: true, animations: 'disabled'});
            }
            await page.close();
        }
        console.log('Stock issue review clarity: passed (actions, allocations, keyboard disclosure, escaping, empty state, light/dark, desktop/mobile)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
