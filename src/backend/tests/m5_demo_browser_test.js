const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium, expect } = require('@playwright/test');

assert.equal(process.env.RUN_DB_TESTS, '1');
assert.match(process.env.DB_NAME || '', /^retailmind_demo_m5_[a-z0-9_]+$/);
assert.equal(process.env.DB_HOST, '127.0.0.1');
assert.ok(process.env.DEMO_PASSWORD);
const baseURL = process.env.DEMO_BASE_URL || 'http://127.0.0.1:8093';
assert.equal(new URL(baseURL).hostname, '127.0.0.1');
const output = path.resolve('.scratch/m5-demo-browser');
fs.mkdirSync(output, { recursive: true });

function state() {
    const run = spawnSync('python', ['-c', `
import json,os,pymysql
c=pymysql.connect(host='127.0.0.1',port=int(os.environ['DB_PORT']),user=os.getenv('DB_USER','root'),password=os.getenv('DB_PASSWORD',''),database=os.environ['DB_NAME'],cursorclass=pymysql.cursors.DictCursor)
s=c.cursor();r={}
s.execute("SELECT setting_value FROM platform_settings WHERE setting_key='demo_replay_result'");r['preview']=json.loads(s.fetchone()['setting_value'])
s.execute("SELECT COUNT(*) n,MIN(sale_date) first,MAX(sale_date) last FROM forecast_sales_imports");r['history']=s.fetchone()
for table in ('products','sales','stock_movements','demand_forecasts','ml_jobs'):
 s.execute('SELECT COUNT(*) n FROM '+table);r[table]=s.fetchone()['n']
s.execute("SELECT COUNT(*) n FROM platform_settings WHERE setting_key='ml_approved_model_version' AND setting_value<>''");r['approvals']=s.fetchone()['n']
s.execute('SELECT SUM(quantity_on_hand) quantity FROM inventory');r['stock']=s.fetchone()['quantity']
s.execute("SELECT SUM(predicted_units) expected FROM demand_forecasts WHERE job_id=%s AND product_id=1",(r['preview']['preview_job'],));r['product_1_expected']=s.fetchone()['expected']
c.close();print(json.dumps(r,default=str))
`], { encoding: 'utf8' });
    assert.equal(run.status, 0, run.stderr);
    return JSON.parse(run.stdout);
}

(async () => {
    const before = state();
    assert.equal(before.history.n, 40700);
    assert.equal(before.history.first, '2014-03-01');
    assert.equal(before.history.last, '2016-05-22');
    assert.equal(before.products, 50);
    assert.equal(before.sales, 0);
    assert.equal(before.stock_movements, 0);
    assert.equal(before.approvals, 0);
    assert.equal(before.demand_forecasts, 4200);
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
        await page.goto(baseURL + '/?login=1');
        await page.fill('#landing-login-username', 'demo_inventory_manager');
        await page.fill('#landing-login-password', process.env.DEMO_PASSWORD);
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('#landing-login-password').press('Enter')]);
        await page.goto(baseURL + '/components/report/forecast_replay.php');
        await expect(page.getByRole('heading', { name: 'M5 historical demonstration', exact: true })).toBeVisible();
        await expect(page.locator('main')).toContainText('Original dates are unchanged');
        await expect(page.locator('main')).toContainText('No Store model or purchasing guidance was approved');
        await expect(page.locator('#replay-comparison tbody tr')).toHaveCount(28);
        await expect(page.locator('#replay-stock-table tbody tr')).toHaveCount(50);
        assert.equal(await page.locator('#replay-stock-table th').count(), 4);
        const product = page.locator('#replay-stock-table tr[data-product-id="1"]');
        await expect(product).toContainText('M5-FOODS_');
        assert.equal((await product.locator('td').nth(1).innerText()).trim(), '0 units');
        assert.equal((await product.locator('td').nth(2).innerText()).trim(), '30 units');
        assert.equal((await product.locator('td').nth(3).innerText()).trim(), before.product_1_expected + ' units');
        await page.screenshot({ path: path.join(output, 'm5-desktop.png'), fullPage: true });
        const secondProduct = await page.locator('#replay-product option[value="2"]').textContent();
        await page.getByRole('combobox', { name: 'Product', exact: true }).fill(secondProduct);
        await page.getByRole('option', { name: secondProduct, exact: true }).click();
        await page.getByRole('button', { name: 'Compare product', exact: true }).click();
        await expect(page.locator('#replay-product')).toHaveValue('2');
        await expect(page.locator('#replay-comparison tbody tr')).toHaveCount(28);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(output, 'm5-mobile.png'), fullPage: true });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'M5 preview fits mobile');
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(baseURL + '/components/inventory_management/replenishment_queue.php');
        await expect(page.locator('main > .tag-warning')).toContainText('Historical M5 demo');
        assert.equal(await page.locator('.queue-list th').count(), 4);
        const expectedRow = page.locator('.queue-item').filter({ hasText: 'M5-FOODS_1_122' });
        assert.equal(await expectedRow.getAttribute('data-expected-sales'), String(before.product_1_expected), 'M5 Demo replenishment must show its saved historical expected sales');
        await expect(expectedRow.locator('td').nth(3)).toContainText('Historical demo');
        await expect(expectedRow.locator('td').nth(3)).toContainText('unapproved');
        await page.screenshot({ path: path.join(output, 'stock-rules.png'), fullPage: true });
        assert.deepEqual(state(), before, 'Reviewing the Demo must not change stock, jobs, evidence or approval');
        fs.writeFileSync(path.join(output, 'verification.json'), JSON.stringify(before, null, 2));
        console.log('M5 Demo browser checks passed: 50 products, original dates, actual-versus-predicted, four columns, mobile, no stock/job/approval changes.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
