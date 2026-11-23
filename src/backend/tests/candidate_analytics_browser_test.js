const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const net = require('node:net');
const { chromium } = require('playwright');
if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Candidate Analytics: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const fixture = (action, settings = false) => {
    const result = spawnSync(php, ['src/backend/tests/support/' + (settings ? 'candidate_analytics_fixture.php' : 'theme_fixture.php'), action, database], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const state = () => JSON.parse(fixture('state', true));
(async () => {
    let browser, server;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...process.env, RM_THEME_TEST_DATABASE: database }, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        const ids = JSON.parse(fixture('setup', true));
        const before = state();
        browser = await chromium.launch({ headless: true });
        for (const role of ['admin', 'super_admin', 'inventory_manager', 'cashier']) {
            const context = await browser.newContext();
            const page = await context.newPage({ viewport: {width: 1440, height: 1000} });
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation(), page.getByRole('button', {name:'Log in', exact:true}).click()]);
            const url = origin + '/components/report/forecast_analytics.php';
            const response = await page.goto(url + '?training_run_id=' + ids.failed);
            if (['inventory_manager','cashier'].includes(role)) {
                assert.equal(response.status(),403, role + ' denied direct Analytics');
                await context.close(); continue;
            }
            assert.equal(response.status(),200);
            assert.equal(await page.locator('.forecast-studio-nav a[href*="forecast_analytics.php"]').count(),1);
            assert.equal(await page.locator('nav[aria-label="Main navigation"] a[href*="forecast_analytics.php"]').count(),1);
            fixture('unapprove',true);
            assert.equal((await page.reload()).status(),200,'Candidate remains reviewable without any approved model or operational forecast');
            fixture('approve_unrelated',true);
            const section = page.locator('section[aria-labelledby="candidate-evidence-title"]');
            assert.match(await section.innerText(), /candidate_failed/);
            assert.match(await section.innerText(), /Qualification: failed/);
            assert.match(await section.innerText(), /External Demo: M5 original daily sales/);
            assert.match(await section.innerText(), /separate from the final candidate/);
            assert.match(await page.locator('main').textContent(), /Gate Failed/);
            assert.equal(await page.getByRole('table', {name:'Candidate held-out daily evidence',exact:true}).locator('tbody tr:not(.empty-row)').first().innerText(), '1\t2016-04-24\t2016-04-25\t17\t28\t16');
            assert.ok(!(await page.locator('main').innerText()).includes('1,234.00 units'));
            for (const width of [1440,390]) {
                await page.setViewportSize({width,height:844});
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Analytics fits ' + width);
                if (role === 'admin') {
                    require('node:fs').mkdirSync('.scratch/candidate-analytics',{recursive:true});
                    await page.screenshot({path:'.scratch/candidate-analytics/' + width + '.png',fullPage:true});
                }
            }
            await page.locator('#analytics-run').selectOption(String(ids.missing));
            await Promise.all([page.waitForNavigation(), page.getByRole('button', {name:'View candidate',exact:true}).click()]);
            assert.match(await section.innerText(), /candidate_missing/);
            assert.match(await section.innerText(), /No held-out daily predictions recorded/);
            assert.match(await page.locator('main').textContent(), /No model checks yet/);
            assert.equal((await page.goto(url + '?training_run_id=999999')).status(),404);
            assert.equal((await page.goto(url + '?model_version=unrelated_approved')).status(),404);
            await page.goto(url);
            assert.equal(await page.locator('#analytics-run').inputValue(),String(ids.missing));
            await context.close();
        }
        assert.deepEqual(state(), before, 'Viewing candidate evidence preserves activation, observations, jobs, stock and sales');
        console.log('Candidate Analytics passed (signed-in selection, failed/missing evidence, provenance, roles, responsive layout and unchanged activation state)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => {console.error(error); process.exitCode=1;});
