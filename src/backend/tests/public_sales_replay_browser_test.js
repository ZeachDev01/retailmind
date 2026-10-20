'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium, expect} = require('@playwright/test');

assert.equal(process.env.RUN_DB_TESTS, '1');
assert.match(process.env.DB_NAME || '', /^retailmind_demo_[a-z0-9_]+$/);
assert.equal(process.env.DB_HOST, '127.0.0.1');
assert.ok(process.env.DEMO_PASSWORD);
const baseURL = process.env.DEMO_BASE_URL || 'http://127.0.0.1:8092';
assert.equal(new URL(baseURL).hostname, '127.0.0.1');
const output = path.resolve('.scratch/public-sales-replay-browser');
fs.mkdirSync(output, {recursive: true});

function state() {
    const run = spawnSync('python', ['-c', `import json,os,pymysql
c=pymysql.connect(host='127.0.0.1',user=os.getenv('DB_USER','root'),password=os.getenv('DB_PASSWORD',''),database=os.environ['DB_NAME'],cursorclass=pymysql.cursors.DictCursor)
s=c.cursor(); r={}
s.execute("SELECT setting_value FROM platform_settings WHERE setting_key='demo_replay_result'"); r['replay']=json.loads(s.fetchone()['setting_value'])
s.execute("SELECT MIN(sale_date) AS first,MAX(sale_date) AS last,COUNT(*) AS n FROM forecast_sales_imports"); r['train']=s.fetchone()
s.execute("SELECT MIN(DATE(s.sale_date)) AS first,MAX(DATE(s.sale_date)) AS last,SUM(si.quantity) AS units FROM sales s JOIN sale_items si ON si.sale_id=s.sale_id WHERE s.payment_reference LIKE 'SIMULATED-UCI-%'"); r['test']=s.fetchone()
s.execute("SELECT p.product_id,p.quantity_purchased,p.quantity_sold,i.quantity_on_hand,COALESCE((SELECT SUM(remaining_quantity) FROM product_batches b WHERE b.product_id=p.product_id),0) AS batches,COALESCE((SELECT SUM(change_qty) FROM stock_movements m WHERE m.product_id=p.product_id),0) AS movements FROM products p JOIN inventory i ON i.product_id=p.product_id ORDER BY p.product_id"); r['stock']=s.fetchall()
s.execute("SELECT COUNT(*) AS n FROM platform_settings WHERE setting_key='ml_approved_model_version'"); r['approvals']=s.fetchone()['n']
s.execute("SELECT COUNT(*) AS n FROM ml_jobs WHERE trigger_type='forecast_generation'"); r['operational_runs']=s.fetchone()['n']
s.execute("SELECT COUNT(*) AS n FROM demand_forecasts"); r['predictions']=s.fetchone()['n']
c.close(); print(json.dumps(r,default=str))`], {encoding: 'utf8'});
    assert.equal(run.status, 0, run.stderr);
    return JSON.parse(run.stdout);
}

(async () => {
    const before = state();
    assert.equal(before.train.n, 5760);
    assert.equal(before.train.last, before.replay.training_end);
    assert.ok(before.train.last < before.test.first);
    assert.equal(before.test.last, before.replay.test_end);
    assert.equal(before.replay.test_rows, 1680);
    assert.equal(before.predictions, 2240);
    assert.equal(before.approvals, 0);
    assert.equal(before.operational_runs, 0);
    for (const row of before.stock) {
        const expected = before.replay.stock[row.product_id];
        assert.equal(Number(row.quantity_purchased), expected.opening);
        assert.equal(Number(row.quantity_sold), expected.sold);
        for (const field of ['quantity_on_hand', 'batches', 'movements']) assert.equal(Number(row[field]), expected.closing);
    }
    const browser = await chromium.launch({headless: true});
    let page;
    try {
        const context = await browser.newContext({baseURL, viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce'});
        page = await context.newPage();
        await page.goto('/?login=1');
        await page.waitForFunction(() => document.activeElement === document.getElementById('landing-login-username'));
        await page.fill('#landing-login-username', 'demo_inventory_manager');
        await page.fill('#landing-login-password', process.env.DEMO_PASSWORD);
        await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.locator('#landing-login-password').press('Enter')]);
        await page.goto('/components/report/forecast_replay.php');
        await expect(page.getByRole('heading', {name: 'Train/test replay', exact: true})).toBeVisible();
        await expect(page.locator('main')).toContainText('Training validation: failed');
        await expect(page.locator('#replay-comparison tbody tr')).toHaveCount(28);
        await expect(page.locator('#replay-stock-table tbody tr')).toHaveCount(20);
        const first = await page.locator('#replay-comparison tbody tr').first().locator('td').allTextContents();
        assert.equal(first[0], before.replay.test_end);
        assert.equal(Number(first[4]), Number(first[2]) - Number(first[3]));
        const stock = page.locator('#replay-stock-table tr[data-product-id="1"]');
        assert.equal(Number(await stock.locator('td').nth(1).innerText()), 0);
        assert.ok(Number(await stock.locator('td').nth(5).innerText()) > 0);
        await page.screenshot({path: path.join(output, 'replay-desktop.png'), fullPage: true});
        await page.selectOption('#replay-product', '2');
        await page.getByRole('button', {name: 'Compare product', exact: true}).click();
        await expect(page.locator('#replay-product')).toHaveValue('2');
        await expect(page.locator('#replay-comparison tbody tr')).toHaveCount(28);
        await page.setViewportSize({width: 390, height: 844});
        await page.screenshot({path: path.join(output, 'replay-mobile.png'), fullPage: true});
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Replay fits mobile');
        await page.setViewportSize({width: 1440, height: 1000});
        await page.goto('/components/inventory_management/replenishment_queue.php');
        await expect(page.locator('main')).toContainText('not approved');
        await expect(page.locator('main')).toContainText('Stock rules');
        await page.screenshot({path: path.join(output, 'stock-rules-and-preview-link.png'), fullPage: true});
        await page.goto('/components/inventory_management/inventory_overview.php');
        await expect(page.locator('main')).toContainText('UCI-');
        await page.screenshot({path: path.join(output, 'current-stock.png'), fullPage: true});
        assert.deepEqual(state(), before, 'Viewing the experiment does not change stock, approval, history or predictions');
        fs.writeFileSync(path.join(output, 'verified.json'), JSON.stringify(before, null, 2));
        console.log('PASS: train/test isolation; stock/batch/movement reconciliation; no approval bypass; 28-day actual comparison; 20 product previews; mobile and desktop; read-only navigation.');
        await context.close();
    } catch (error) {
        await page?.screenshot({path: path.join(output, 'failure.png'), fullPage: true}).catch(() => {});
        throw error;
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
