const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const net = require('node:net');
const { chromium } = require('playwright');
const { expect } = require('@playwright/test');
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
            const context = await browser.newContext({deviceScaleFactor:2});
            const page = await context.newPage({ viewport: {width: 1440, height: 1000} });
            const pageErrors = [];
            page.on('pageerror', error => pageErrors.push(error.message));
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
            const notNow = page.getByRole('button', {name:'Not now', exact:true});
            if (await notNow.isVisible()) await notNow.click();
            const follow = async selector => {
                const link = page.locator(selector);
                const href = new URL(await link.getAttribute('href'), page.url()).href;
                await link.click();
                await page.waitForURL(href);
                await page.waitForFunction(() => document.querySelector('.main-content')?.getAttribute('aria-busy') !== 'true');
            };
            const capture = async path => {
                await page.evaluate(() => window.scrollTo(0,0));
                await page.screenshot({path,fullPage:true,animations:'disabled'});
            };
            fixture('unapprove',true);
            assert.equal((await page.reload()).status(),200,'Candidate remains reviewable without any approved model or operational forecast');
            fixture('approve_unrelated',true);
            const section = page.locator('section[aria-labelledby="candidate-evidence-title"]');
            assert.match(await section.textContent(), /candidate_failed/);
            assert.match(await section.textContent(), /Qualification[\s\S]*failed/);
            assert.match(await section.innerText(), /External Demo: M5 original daily sales/);
            await page.getByRole('button', {name:'About this evidence source',exact:true}).click();
            await expect(page.locator('[role="tooltip"]:visible')).toContainText('separate from the final candidate');
            await page.keyboard.press('Escape');
            await expect(page.locator('[role="tooltip"]:visible')).toHaveCount(0);
            assert.equal(await page.locator('nav[aria-label="Analytics views"] a').count(),4);
            assert.equal(await page.locator('main canvas').count(),1);
            assert.equal(await page.locator('main table').count(),1);
            const table = page.getByRole('table', {name:'Candidate held-out daily evidence',exact:true});
            assert.deepEqual(await table.locator('thead th').allTextContents(), ['Product','Date','Forecast','Actual sales']);
            assert.deepEqual((await table.locator('tbody tr').first().locator('td').allTextContents()).slice(1), ['2016-04-25','28','17']);
            await table.locator('summary').first().click();
            await expect(table.locator('details').first()).toContainText('2016-04-24');
            await expect(table.locator('details').first()).toContainText('16');
            await table.locator('summary').first().click();
            assert.ok(!(await page.locator('main').innerText()).includes('1,234.00 units'));
            assert.equal(await page.locator('#analytics-evidence').inputValue(),'historical');
            assert.equal(await page.locator('#actualChart').count(),1,'Historical comparison feeds selected evidence');
            await follow('#analytics-tab-errors');
            assert.equal(await page.locator('tbody tr').count(),3,'All Product rankings');
            await follow('#analytics-tab-best');
            await follow('#analytics-tab-model');
            assert.equal(await page.locator('#trendChart').count(),1,'Recorded candidate WAPE');
            await follow('#analytics-tab-features');
            assert.equal(await page.locator('#featureChart').count(),1,'Recorded input influence');
            await follow('#analytics-tab-checks');
            await expect(page.locator('.analytics-checks')).toContainText('Gate Failed');
            await follow('#analytics-tab-actual');
            await page.locator('#analytics-group').selectOption('week');
            await page.locator('#analytics-order').selectOption('desc');
            await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Apply view',exact:true}).click()]);
            await expect(page.locator('#historical-chart-summary')).toContainText('2 weekly totals');
            await page.locator('#analytics-group').selectOption('month');
            await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Apply view',exact:true}).click()]);
            await expect(page.locator('#historical-chart-summary')).toContainText('2 monthly totals');
            await page.locator('#analytics-evidence').selectOption('store');
            await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'View evidence',exact:true}).click()]);
            assert.equal(await page.locator('#actualChart').count(),0,'M5 comparisons never appear as Store outcomes');
            assert.match(await page.locator('#analytics-panel-actual').innerText(),/Ready for your first comparison/);
            assert.equal(await page.locator('main canvas').count(),0,'Historical chart is absent for Store sales');
            assert.equal(await page.locator('.analytics-provenance').count(),0);
            await follow('#analytics-tab-logs');
            await expect(page.locator('#analytics-panel-logs')).toContainText('No prediction history yet');
            await follow('#analytics-tab-model');
            assert.equal(await page.locator('#analytics-tab-features').count(),0);
            await follow('#analytics-tab-checks');
            await expect(page.locator('#analytics-panel-model')).toContainText('Performance on Store sales');
            await page.locator('#analytics-evidence').selectOption('historical');
            await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'View evidence',exact:true}).click()]);
            await follow('#analytics-tab-actual');
            await page.locator('#analytics-product').selectOption('3');
            await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Apply view',exact:true}).click()]);
            assert.equal(await page.locator('tbody tr').count(),10);
            await follow('.analytics-pagination a[rel="next"]');
            assert.equal(await page.locator('tbody tr').count(),10);
            await follow('.analytics-pagination a[rel="next"]');
            assert.equal(await page.locator('tbody tr').count(),6);
            await page.locator('#analytics-search').fill('2016-04-01');
            await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Search',exact:true}).click()]);
            assert.equal(await page.locator('tbody tr').count(),2,'Search across pages preserves both origins');
            await page.locator('#analytics-search').fill('<script>missing</script>');
            await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Search',exact:true}).click()]);
            await expect(page.locator('.analytics-no-results')).toContainText('No matching records');
            assert.equal(await page.locator('#analytics-search').inputValue(),'<script>missing</script>');
            await follow('.analytics-search a');
            assert.equal(await page.locator('tbody tr').count(),10);
            await page.locator('#analytics-group').selectOption('day');
            await page.locator('#analytics-order').selectOption('asc');
            await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Apply view',exact:true}).click()]);
            for (const theme of ['light','dark']) {
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                for (const width of [1440,640,390,320]) {
                    await page.setViewportSize({width,height:844});
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Analytics fits ' + width);
                    assert.equal(await page.locator('.analytics-tab.is-active').evaluate(el => getComputedStyle(el).color),'rgb(255, 255, 255)','Active tab text stays readable in ' + theme);
                    assert.ok(await page.locator('#actualChart').evaluate(canvas=>Math.abs(canvas.width/devicePixelRatio-canvas.getBoundingClientRect().width)<2),'Canvas stays sized to its container on retina and resize');
                    if (role === 'admin' && [1440,390].includes(width)) {
                        require('node:fs').mkdirSync('.impeccable/review/forecast-analytics',{recursive:true});
                        await capture('.impeccable/review/forecast-analytics/comparison-' + theme + '-' + width + '.png');
                        if (width === 390) {
                            await page.locator('.analytics-row-details summary').first().click();
                            await expect(page.locator('.analytics-row-details').first()).toHaveAttribute('open','');
                            await capture('.impeccable/review/forecast-analytics/row-details-' + theme + '-390.png');
                            await page.locator('.analytics-row-details summary').first().click();
                            await page.getByRole('button',{name:'About the sales comparison',exact:true}).focus();
                            await expect(page.locator('[role="tooltip"]:visible')).toHaveCount(1);
                            assert.ok(await page.locator('[role="tooltip"]:visible').evaluate(el => {
                                const r=el.getBoundingClientRect();
                                return r.left >= 8 && r.right <= document.documentElement.getBoundingClientRect().right - 8;
                            }),'Mobile help retains both painted viewport gutters');
                            await page.screenshot({path:'.impeccable/review/forecast-analytics/help-' + theme + '-390.png',animations:'disabled'});
                            await page.keyboard.press('Escape');
                        }
                    }
                }
            }
            await page.setViewportSize({width:1440,height:900});
            await page.evaluate(() => document.documentElement.dataset.theme = 'light');
            for (const tab of ['errors','model','logs']) {
                await follow('#analytics-tab-' + tab);
                for (const theme of ['light','dark']) {
                  await page.evaluate(mode => document.documentElement.dataset.theme = mode,theme);
                  for (const width of [1440,640,390,320]) {
                    await page.setViewportSize({width,height:900});
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),tab + ' fits ' + width);
                    assert.ok(await page.locator('.analytics-tab[aria-current="page"]').evaluate(el => {
                        const tab=el.getBoundingClientRect(), list=el.parentElement.getBoundingClientRect();
                        return tab.left >= list.left-1 && tab.right <= list.right+1;
                    }),'Selected main view stays fully visible after navigation and resize');
                    if (role === 'admin' && [1440,390].includes(width)) await capture('.impeccable/review/forecast-analytics/' + tab + '-' + theme + '-' + width + '.png');
                  }
                }
            }
            await page.goto(url + '?training_run_id=' + ids.store + '&evidence=store');
            assert.equal(await page.locator('#actualChart').count(),1,'Matched Store sales create a Store chart');
            assert.equal(await page.locator('tbody tr').count(),10);
            assert.ok((await page.locator('tbody tr').first().innerText()).includes('Theme Test Item'));
            assert.equal(await page.locator('tbody tr').first().locator('td').nth(3).innerText(),'1');
            await follow('.analytics-pagination a[rel="next"]');
            assert.equal(await page.locator('tbody tr').first().locator('td').nth(3).innerText(),'Unknown','Missing Store sales never become zero');
            await follow('#analytics-tab-logs');
            await expect(page.locator('.analytics-pagination')).toContainText('25 records');
            if (role === 'admin') {
                await capture('.impeccable/review/forecast-analytics/store-logs-mobile.png');
                await follow('#analytics-tab-model');
                await follow('#analytics-tab-checks');
                await page.locator('.analytics-checks summary').first().click();
                await capture('.impeccable/review/forecast-analytics/store-performance-mobile.png');
            }
            await page.goto(url + '?training_run_id=' + ids.failed);
            await page.locator('#analytics-run').selectOption(String(ids.missing));
            await Promise.all([page.waitForNavigation(), page.getByRole('button', {name:'View evidence',exact:true}).click()]);
            assert.match(await section.textContent(), /candidate_missing/);
            await follow('#analytics-tab-actual');
            assert.match(await page.locator('#analytics-panel-actual').innerText(), /No held-out daily predictions recorded/);
            if (role === 'admin') {
                await page.setViewportSize({width:390,height:900});
                await page.evaluate(() => document.documentElement.dataset.theme = 'dark');
                await capture('.impeccable/review/forecast-analytics/empty-dark-390.png');
            }
            await follow('#analytics-tab-model');
            await follow('#analytics-tab-checks');
            assert.match(await page.locator('main').textContent(), /No model checks yet/);
            assert.equal((await page.goto(url + '?training_run_id=999999')).status(),404);
            assert.equal((await page.goto(url + '?model_version=unrelated_approved')).status(),404);
            await page.goto(url);
            assert.equal(await page.locator('#analytics-run').inputValue(),String(ids.missing));
            assert.equal(await page.locator('#analytics-evidence').inputValue(),'historical','Historical remains default without evidence');
            assert.deepEqual(pageErrors, [], 'Analytics navigation has no script errors');
            const noJS = await browser.newContext({javaScriptEnabled:false, storageState:await context.storageState()});
            const plain = await noJS.newPage();
            await plain.goto(url + '?training_run_id=' + ids.failed);
            await plain.locator('#analytics-tab-logs').click();
            await expect(plain.locator('tbody tr')).toHaveCount(10);
            await plain.locator('.analytics-pagination a[rel="next"]').click();
            await expect(plain.locator('tbody tr')).toHaveCount(10);
            await plain.locator('#analytics-search').fill('2016-04-01');
            await Promise.all([plain.waitForNavigation(),plain.getByRole('button',{name:'Search',exact:true}).click()]);
            await expect(plain.locator('tbody tr')).toHaveCount(2);
            await noJS.close();
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
