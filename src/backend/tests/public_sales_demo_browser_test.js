'use strict';
// Opt-in real browser workflow against an explicitly isolated, prepared UCI Demo.
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const {chromium, expect} = require('@playwright/test');

assert.equal(process.env.RUN_DB_TESTS, '1', 'Set RUN_DB_TESTS=1 for the isolated Demo workflow');
assert.match(process.env.DB_NAME || '', /^retailmind_demo_[a-z0-9_]+$/);
assert.equal(process.env.DB_HOST, '127.0.0.1');
assert.ok(process.env.DEMO_PASSWORD, 'Set the local Demo account password');
const baseURL = process.env.DEMO_BASE_URL || 'http://127.0.0.1:8091';
assert.equal(new URL(baseURL).hostname, '127.0.0.1');
const output = path.resolve('.scratch/public-sales-demo/workflow');
fs.mkdirSync(output, {recursive: true});

function snapshot() {
    const code = `import json,os,pymysql
c=pymysql.connect(host='127.0.0.1',port=int(os.getenv('DB_PORT','3306')),user=os.getenv('DB_USER','root'),password=os.getenv('DB_PASSWORD',''),database=os.environ['DB_NAME'],cursorclass=pymysql.cursors.DictCursor)
s=c.cursor(); result={}
s.execute("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'"); result['source']=json.loads(s.fetchone()['setting_value'])
s.execute("SELECT p.product_id,p.sku,COALESCE(i.quantity_on_hand,0) AS stock FROM products p LEFT JOIN inventory i ON i.product_id=p.product_id ORDER BY p.product_id"); result['products']=s.fetchall()
s.execute("SELECT job_id FROM ml_jobs WHERE trigger_type='forecast_generation' AND status='succeeded' ORDER BY job_id DESC LIMIT 1"); result['job_id']=s.fetchone()['job_id']
s.execute("SELECT SUM(CASE WHEN product_id=1 AND horizon_day<=7 THEN predicted_units ELSE 0 END) AS f7,SUM(CASE WHEN product_id=1 AND horizon_day<=14 THEN predicted_units ELSE 0 END) AS f14,SUM(CASE WHEN product_id=1 THEN predicted_units ELSE 0 END) AS f28,COUNT(*) AS forecast_rows,SUM(predicted_units) AS units FROM demand_forecasts WHERE job_id=%s",(result['job_id'],)); result['forecast']=s.fetchone()
for key,table in [('jobs','ml_jobs'),('sales','sales'),('receipts','stock_receiving'),('imports','forecast_sales_imports')]:
 s.execute('SELECT COUNT(*) AS n FROM '+table); result[key]=s.fetchone()['n']
c.close(); print(json.dumps(result,default=str))`;
    const run = spawnSync('python', ['-c', code], {encoding: 'utf8'});
    assert.equal(run.status, 0, run.stderr);
    return JSON.parse(run.stdout.trim());
}

async function login(browser, username) {
    const context = await browser.newContext({baseURL, viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce'});
    const page = await context.newPage();
    await page.goto('/?login=1');
    await page.waitForFunction(() => document.activeElement === document.getElementById('landing-login-username'));
    await page.locator('#landing-login-username').fill(username);
    await page.locator('#landing-login-password').fill(process.env.DEMO_PASSWORD);
    await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.locator('#landing-login-password').press('Enter')]);
    return {context, page};
}

(async () => {
    const initial = snapshot();
    assert.equal(initial.source.source_kind, 'uci_online_retail');
    assert.equal(initial.imports, 7440);
    assert.equal(initial.sales, 0, 'External daily totals must not fabricate customer receipts');
    const browser = await chromium.launch({headless: process.env.DEMO_HEADFUL !== '1'});
    let page;
    try {
        const technical = await login(browser, 'demo_super_admin');
        page = technical.page;
        await page.goto('/components/system_administrator/ml_settings.php');
        await expect(page.getByRole('heading', {name: 'Train and review a candidate'})).toBeVisible();
        await page.screenshot({path: path.join(output, '01-training-review.png'), fullPage: true});
        const previous = await page.locator('input[name="model_version"]').inputValue();
        await page.getByRole('button', {name: 'Train model', exact: true}).click();
        await expect(page.getByRole('button', {name: 'Training in progress', exact: true})).toBeDisabled();
        await expect(page.getByRole('button', {name: 'Approve demo model', exact: true})).toBeEnabled({timeout: 120000});
        const candidate = await page.locator('input[name="model_version"]').inputValue();
        assert.notEqual(candidate, previous, 'Browser training creates a new candidate');
        await page.screenshot({path: path.join(output, '02-new-candidate.png'), fullPage: true});
        await page.getByRole('button', {name: 'Approve demo model', exact: true}).click();
        await expect(page.getByRole('button', {name: 'Demo model approved', exact: true})).toBeDisabled();
        await page.goto('/components/report/predictions.php');
        const beforeGeneration = snapshot();
        await page.getByRole('button', {name: 'Generate Now', exact: true}).click();
        await expect(page.locator('body')).toContainText(candidate, {timeout: 60000});
        const generated = snapshot();
        assert.ok(generated.job_id > beforeGeneration.job_id);
        assert.equal(Number(generated.forecast.forecast_rows), 560);
        await page.screenshot({path: path.join(output, '03-forecasts.png'), fullPage: true});
        await page.getByRole('link', {name: 'Forecast Analytics', exact: true}).click();
        await expect(page.locator('body')).toContainText('UCI Online Retail');
        await expect(page.locator('body')).toContainText('30.7%');
        await page.screenshot({path: path.join(output, '04-analytics.png'), fullPage: true});
        await page.setViewportSize({width: 390, height: 844});
        await page.goto('/components/system_administrator/ml_settings.php');
        await page.screenshot({path: path.join(output, '05-training-mobile.png'), fullPage: true});
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Training UI fits mobile');
        await technical.context.close();

        const operational = await login(browser, 'demo_inventory_manager');
        page = operational.page;
        await page.goto('/components/report/predictions.php');
        assert.equal(await page.getByRole('button', {name: 'Generate Now', exact: true}).count(), 0);
        const product = generated.products[0];
        const forecastRow = page.getByRole('row').filter({hasText: product.sku});
        const forecastCells = await forecastRow.locator('td').allTextContents();
        assert.deepEqual(forecastCells.slice(3, 6).map(Number), ['f7', 'f14', 'f28'].map(key => Number(generated.forecast[key])));
        await page.getByRole('link', {name: 'Review recommendations', exact: true}).click();
        await page.locator('#queue-search').fill(product.sku);
        const row = page.locator('tr.queue-item').filter({hasText: product.sku});
        const stockBefore = Number(await row.getAttribute('data-on-hand'));
        const quantity = Number(await row.getAttribute('data-quantity'));
        assert.ok(quantity > 0, 'Run against a Demo with an unfilled recommendation for the first product');
        await row.locator('summary').click();
        await expect(row).toContainText('Demand Forecast');
        await page.screenshot({path: path.join(output, '06-buying-recommendation.png'), fullPage: true});
        const beforeReceiving = snapshot();
        await row.getByRole('link', {name: /Add bought goods/}).click();
        await expect(page.locator('#product_id')).toHaveValue(String(product.product_id));
        const packFields = await page.locator('#receiving_form').evaluate(form =>
            [...new FormData(form).keys()].filter(key => /_(packs|loose)\]/.test(key)));
        assert.deepEqual(packFields, [], 'Base units must not submit hidden pack/loose fields');
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill(String(quantity));
        await page.locator('#supplier').fill('Demo shopping scenario');
        const reference = 'UCI-DEMO-' + Date.now();
        await page.locator('#invoice_number').fill(reference);
        await page.locator('#receiving_next').click();
        await expect(page.locator('.receiving-form')).toHaveAttribute('data-step', '3');
        const reviewed = snapshot();
        assert.equal(reviewed.receipts, beforeReceiving.receipts, 'Review alone does not create a receipt');
        await page.screenshot({path: path.join(output, '07-receiving-review.png'), fullPage: true});
        await Promise.all([page.waitForNavigation(), page.locator('.btn-submit').click()]);
        await expect(page.locator('.message.success')).toContainText(quantity + ' accepted units');
        await page.screenshot({path: path.join(output, '08-stock-received.png'), fullPage: true});
        await page.getByRole('link', {name: 'Review replenishment recommendations', exact: true}).click();
        await page.locator('#queue-search').fill(product.sku);
        const updated = page.locator('tr.queue-item').filter({hasText: product.sku});
        assert.equal(Number(await updated.getAttribute('data-on-hand')), stockBefore + quantity);
        assert.equal(Number(await updated.getAttribute('data-quantity')), 0);
        await updated.locator('summary').click();
        await page.screenshot({path: path.join(output, '09-recommendation-updated.png'), fullPage: true});
        await page.goto('/components/report/receiving_history.php?q=' + encodeURIComponent(reference));
        await expect(page.locator('main')).toContainText(reference);
        await page.screenshot({path: path.join(output, '10-receiving-history.png'), fullPage: true});
        const afterReceiving = snapshot();
        assert.equal(afterReceiving.receipts, beforeReceiving.receipts + 1);
        assert.deepEqual(afterReceiving.forecast, beforeReceiving.forecast, 'Receiving preserves the saved forecast');
        assert.equal(afterReceiving.jobs, beforeReceiving.jobs, 'Receiving does not start training or forecasting');
        assert.equal(afterReceiving.imports, 7440);
        assert.equal(afterReceiving.sales, 0);
        const summary = {database: process.env.DB_NAME, candidate, job_id: generated.job_id,
            product: product.sku, stock_before: stockBefore, received: quantity,
            stock_after: stockBefore + quantity, recommendation_after: 0, reference,
            forecast_rows: 560, screenshots: output};
        fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(summary, null, 2));
        console.log(JSON.stringify(summary));
        await operational.context.close();
    } catch (error) {
        await page?.screenshot({path: path.join(output, 'failure.png'), fullPage: true}).catch(() => {});
        throw error;
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
