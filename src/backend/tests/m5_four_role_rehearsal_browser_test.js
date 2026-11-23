'use strict';
// Opt-in, persistent rehearsal: creates one Admin training job, delivery, shift and sale.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium, expect} = require('@playwright/test');

assert.equal(process.env.RUN_M5_REHEARSAL, '1', 'Set RUN_M5_REHEARSAL=1; this writes persistent Demo records');
assert.match(process.env.DB_NAME || '', /^retailmind_demo_m5_[a-z0-9_]+$/);
assert.equal(process.env.DB_HOST, '127.0.0.1');
assert.equal(new URL(process.env.DEMO_BASE_URL).hostname, '127.0.0.1');
for (const key of ['DEMO_PASSWORD', 'M5_PREVIEW_CSV', 'M5_EVIDENCE_DIR', 'M5_PYTHON', 'M5_PROJECT_ROOT', 'M5_MODEL_STORAGE', 'M5_TASK_NAME']) assert.ok(process.env[key], key);
assert.match(process.env.M5_TASK_NAME, /^RetailMind Demo Scheduler 505 /);
const output = process.env.M5_EVIDENCE_DIR;
fs.mkdirSync(output, {recursive: true});
const summary = process.env.M5_RESUME_JOB ? JSON.parse(fs.readFileSync(path.join(output, 'browser-result.json'))) : {database: process.env.DB_NAME, baseURL: process.env.DEMO_BASE_URL, prior_job: 8, prior_run: 4, phases: []};
delete summary.error;
function save() { fs.writeFileSync(path.join(output, 'browser-result.json'), JSON.stringify(summary, null, 2)); }
function state() {
    const code = `import os,json,pymysql
c=pymysql.connect(host=os.environ['DB_HOST'],port=int(os.environ['DB_PORT']),user=os.environ['DB_USER'],password=os.getenv('DB_PASSWORD',''),database=os.environ['DB_NAME'],cursorclass=pymysql.cursors.DictCursor)
s=c.cursor();r={}
for t in ['sales','sale_items','stock_receiving','stock_movements','cashier_shifts','forecast_sales_imports','demand_forecasts']:
 s.execute('SELECT COUNT(*) n FROM '+t);r[t]=s.fetchone()['n']
s.execute('CHECKSUM TABLE forecast_sales_imports EXTENDED');r['import_checksum']=s.fetchone()['Checksum']
s.execute("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'");r['source']=json.loads(s.fetchone()['setting_value'])
s.execute('SELECT unit_price FROM products WHERE product_id=1');r['price']=str(s.fetchone()['unit_price'])
s.execute('SELECT quantity_on_hand FROM inventory WHERE product_id=1');r['stock']=s.fetchone()['quantity_on_hand']
s.execute('SELECT * FROM ml_jobs ORDER BY job_id DESC LIMIT 1');r['job']=s.fetchone()
s.execute('SELECT * FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1');r['run']=s.fetchone()
s.execute('SELECT * FROM sales ORDER BY sale_id DESC LIMIT 1');r['sale']=s.fetchone()
s.execute('SELECT * FROM sale_items ORDER BY sale_id DESC LIMIT 1');r['sale_item']=s.fetchone()
s.execute("SELECT * FROM fiscal_periods WHERE status='open' LIMIT 1");r['fiscal_period']=s.fetchone()
print(json.dumps(r,default=str))`;
    const result = spawnSync(process.env.M5_PYTHON, ['-c', code], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    return JSON.parse(result.stdout);
}
async function shot(page, name) { await page.screenshot({path: path.join(output, name + '.png'), fullPage: true}); }
async function visit(page, url) { await page.goto(url, {waitUntil: 'domcontentloaded'}); }
async function login(browser, username) {
    const context = await browser.newContext({baseURL: process.env.DEMO_BASE_URL, viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce'});
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    await visit(page, '/?login=1');
    await page.locator('#landing-login-username').fill(username);
    await page.locator('#landing-login-password').fill(process.env.DEMO_PASSWORD);
    await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
    return {context, page};
}
(async () => {
    const before = state(); summary.before = before; save();
    assert.equal(before.sales, 0, 'Use an unsold prepared clone; never reset existing sales');
    assert.equal(before.stock_receiving, 0, 'Use an unreceived prepared clone');
    assert.equal(before.forecast_sales_imports, 40700);
    assert.equal(before.source.source_kind, 'm5_daily');
    const browser = await chromium.launch({headless: true});
    let current;
    try {
        const technical = await login(browser, 'demo_super_admin');
        const admin = await login(browser, 'demo_admin');
        const inventory = await login(browser, 'demo_inventory_manager');
        const cashier = await login(browser, 'demo_cashier');
        assert.equal(browser.contexts().length, 4);
        for (const [name, session] of Object.entries({technical, admin, inventory, cashier})) await shot(session.page, '01-' + name);
        summary.phases.push('four independent authenticated contexts'); save();
        current = admin.page;
        await visit(current, '/components/system_administrator/forecast_import.php');
        await current.locator('#forecast-csv').setInputFiles(process.env.M5_PREVIEW_CSV);
        await Promise.all([current.waitForNavigation(), current.getByRole('button', {name: 'Preview and Validate', exact: true}).click()]);
        await expect(current.locator('#preview-heading')).toBeVisible();
        summary.preview = await current.locator('#preview-heading').innerText();
        await shot(current, '02-admin-preview');
        const preview = state();
        for (const key of ['forecast_sales_imports', 'import_checksum', 'stock_receiving', 'sales', 'stock']) assert.equal(preview[key], before[key], 'Preview preserves ' + key);
        await visit(current, '/components/report/data_readiness.php'); await shot(current, '03-admin-readiness');
        await visit(current, '/components/system_administrator/ml_settings.php');
        await expect(current.locator('body')).toContainText('Isolated Demo:');
        await current.locator('#settings-heading').click();
        await expect(current.locator('#model-quality-settings')).toBeVisible();
        summary.settings = await current.locator('#model-quality-settings input[type=number]').evaluateAll(inputs => Object.fromEntries(inputs.map(input => [input.name, input.value])));
        await shot(current, '04-admin-settings-before');
        if (process.env.M5_RESUME_JOB) {
            assert.equal(before.job.job_id, Number(process.env.M5_RESUME_JOB));
            assert.equal(before.job.requester_id, 2);
        } else {
            await Promise.all([current.waitForNavigation(), current.locator('[name=action][value=train]').click()]);
        }
        if (!summary.queued) {
        await expect(current.locator('.ml-job-status').first()).toContainText('Queued');
        summary.queued = state().job;
        assert.equal(summary.queued.requester_id, 2);
        assert.equal(summary.queued.status, 'queued');
        await shot(current, '05-admin-queued'); save();
        }
        if (!summary.running) {
        const install = spawnSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(process.env.M5_PROJECT_ROOT, 'src/backend/scripts/install_forecasting_scheduler.ps1'), '-ProjectRoot', process.env.M5_PROJECT_ROOT, '-PhpPath', 'C:/xampp/php/php.exe', '-PythonPath', process.env.M5_PYTHON, '-ModelStoragePath', process.env.M5_MODEL_STORAGE, '-TaskName', process.env.M5_TASK_NAME, '-Force'], {encoding: 'utf8'});
        fs.writeFileSync(path.join(output, 'task-install.txt'), install.stdout + install.stderr);
        assert.equal(install.status, 0, install.stderr);
        await expect.poll(() => state().job.status, {timeout: 60000}).toBe('running');
        summary.running = state().job; save();
        await visit(current, '/components/system_administrator/ml_settings.php', {waitUntil: 'domcontentloaded'}); await shot(current, '06-admin-running'); save();
        }
        await visit(current, '/components/report/forecast_analytics.php?training_run_id=4');
        await expect(current.locator('body')).toContainText('Training run #4');
        await expect(current.locator('body')).toContainText('No held-out daily predictions recorded for this candidate');
        await shot(current, '07-admin-prior-run-4');
        await visit(current, '/components/administrator/model_review.php?training_run_id=4');
        summary.review_during_training = await current.locator('main').innerText();
        await expect(current.locator('#demo-review-heading')).toBeVisible();
        assert.match(summary.review_during_training, /Training run #4/);
        assert.match(summary.review_during_training, /cannot activate a Store model/);
        await shot(current, '08-admin-review-blocked');
        for (const session of [inventory, cashier]) {
            const denied = await session.context.request.get('/components/system_administrator/ml_settings.php');
            assert.equal(denied.status(), 403);
        }
        if (!state().fiscal_period) {
            await visit(current, '/components/system_administrator/fiscal_periods.php');
            const month = summary.queued.queued_at.slice(0, 7);
            const [year, monthNumber] = month.split('-').map(Number);
            await current.locator('#period_name').fill('M5 rehearsal ' + month);
            await current.locator('#start_date').fill(month + '-01');
            await current.locator('#end_date').fill(month + '-' + new Date(Date.UTC(year, monthNumber, 0)).getUTCDate());
            await Promise.all([current.waitForNavigation(), current.locator('form').filter({has: current.locator('[name=action][value=create]')}).locator('[type=submit]').click()]);
            assert.ok(state().fiscal_period, 'Demo setup needs an open Fiscal Period');
            summary.fiscal_setup = state().fiscal_period; save();
        }
        current = inventory.page;
        await visit(current, '/components/inventory_management/products.php?manage_product=1');
        await current.locator('#product-edit-btn').click();
        await current.locator('#edit-selling-price').fill('10.00');
        await current.locator('#product-save-btn').click();
        await expect(current.locator('#product-edit-form')).toHaveCount(0);
        assert.equal(state().price, '10.00');
        summary.illustrative_setup = {product_id: 1, selling_price: '10.00', source: 'Inventory Manager normal Product workflow; not M5 price'};
        await shot(current, '09-illustrative-price'); save();
        await visit(current, '/components/inventory_management/replenishment_queue.php');
        await current.locator('#queue-search').fill('M5-FOODS_1_122');
        const row = current.locator('tr.queue-item').filter({hasText: 'M5-FOODS_1_122'});
        summary.recommendation = {on_hand: Number(await row.getAttribute('data-on-hand')), stock_rule: Number(await row.getAttribute('data-quantity'))};
        assert.equal(summary.recommendation.on_hand, before.stock); assert.ok(summary.recommendation.stock_rule > 0);
        await expect(current.locator('body')).toContainText('These predictions are unapproved and are not current Store forecasts');
        summary.recommendation.historical_expected_sales = Number(await row.getAttribute('data-expected-sales'));
        summary.recommendation.historical_period = await row.getAttribute('data-forecast-period');
        assert.equal(summary.recommendation.historical_expected_sales, 28);
        await shot(current, '10-stock-rule');
        await visit(current, '/components/report/stock_receiving.php');
        await current.locator('#product_search_scan').fill('M5-FOODS_1_122');
        await current.locator('#product_search_scan').press('Enter');
        await expect(current.locator('#received_qty')).toBeVisible();
        if (await current.locator('#quantity_mode').isVisible()) await current.locator('#quantity_mode').selectOption('units');
        await current.locator('#received_qty').fill(String(summary.recommendation.stock_rule));
        summary.receiving_reference = 'M5-505-' + Date.now();
        await current.locator('#invoice_number').fill(summary.receiving_reference);
        await current.locator('#receiving_next').click();
        await expect(current.locator('#receiving_review')).toBeVisible();
        assert.equal(state().stock_receiving, before.stock_receiving, 'Review alone saves nothing');
        await shot(current, '11-receiving-review');
        await Promise.all([current.waitForNavigation(), current.locator('.btn-submit').click()]);
        await expect(current.locator('body')).toContainText('accepted units');
        summary.received = state();
        assert.equal(summary.received.stock_receiving, before.stock_receiving + 1);
        assert.equal(summary.received.stock, before.stock + summary.recommendation.stock_rule);
        assert.equal(summary.received.sales, 0); await shot(current, '12-received'); save();
        current = cashier.page;
        await visit(current, '/components/cashier/shifts.php');
        await current.locator('#open-register').selectOption({label: 'Demo Front Counter'});
        await current.locator('#opening-float').fill('0');
        await Promise.all([current.waitForNavigation(), current.getByRole('button', {name: 'Open shift', exact: true}).click()]);
        await shot(current, '13-cashier-shift');
        await visit(current, '/components/cashier/pos.php');
        await current.locator('#sku-input').fill('M5-FOODS_1_122'); await current.locator('#add-code-btn').click();
        await current.locator('#cart-body td strong.cart-item-name').waitFor();
        assert.equal(await current.locator('#cart-item-count').innerText(), '1');
        await current.locator('#checkout-button').click(); await current.locator('#checkout-modal.open').waitFor();
        await current.locator('#review-cash').fill('10'); await shot(current, '14-checkout-review');
        await Promise.all([current.waitForNavigation(), current.locator('#confirm-checkout-button').click()]);
        await current.locator('#receiptModal[open] .sale-receipt').waitFor();
        summary.receipt = await current.locator('#receiptModal').innerText();
        assert.match(summary.receipt, /Payment completed/); await shot(current, '15-sale-receipt');
        summary.after_sale = state();
        assert.equal(summary.after_sale.sales, 1); assert.equal(summary.after_sale.sale_item.product_id, 1);
        assert.equal(summary.after_sale.stock, before.stock + summary.recommendation.stock_rule - 1);
        for (const key of ['forecast_sales_imports', 'import_checksum', 'demand_forecasts']) assert.equal(summary.after_sale[key], before[key]);
        await visit(inventory.page, '/components/inventory_management/replenishment_queue.php');
        const afterRow = inventory.page.locator('tr.queue-item[data-sku="M5-FOODS_1_122"]');
        assert.equal(Number(await afterRow.getAttribute('data-on-hand')), summary.after_sale.stock);
        await shot(inventory.page, '18-stock-after-sale');
        save();
        await expect.poll(() => state().job.status, {timeout: 600000, intervals: [5000]}).toBe('succeeded');
        summary.completed = state();
        current = admin.page; await visit(current, '/components/system_administrator/ml_settings.php'); await shot(current, '16-admin-completed');
        await visit(current, '/components/report/forecast_analytics.php?training_run_id=' + summary.completed.run.training_run_id); await shot(current, '17-new-run-evidence');
        summary.phases.push('preview, new Admin task-driven training, prior evidence, stock-rule receiving, same-item sale and completed worker');
        save(); console.log(JSON.stringify({job: summary.queued.job_id, run: summary.completed.run.training_run_id, sale: summary.after_sale.sale.sale_id, output}));
    } catch (error) {
        summary.error = error.stack; save(); if (current) await shot(current, 'failure'); throw error;
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
