const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Forecasting notices browser test: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-notices-'));
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};

const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/notification_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

const query = sql => {
    const script = "require 'src/backend/bootstrap/app.php'; $c=require 'src/backend/config/database.php';"
        + " $p=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname=" + database + "', $c['username'],$c['password']);"
        + " echo json_encode($p->query(base64_decode(getenv('RM_TEST_SQL')))->fetchAll(PDO::FETCH_ASSOC));";
    const result = spawnSync(php, ['-r', script], {encoding: 'utf8', env: {...env, RM_TEST_SQL: Buffer.from(sql).toString('base64')}});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout);
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;

        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }

        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        page.setDefaultTimeout(8000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));

        const login = async username => {
            await page.goto(origin + '/?login=1');
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill(username);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([
                page.waitForURL(u => !u.search.includes('login=1')),
                page.getByRole('button', {name: 'Log in', exact: true}).click()
            ]);
        };

        // --- 1. Login as Administrator ---
        await login('theme_admin');

        // --- 2. Actionable forecasting failure notice ---
        fixture('forecasting_seed_failure');

        await page.goto(origin + '/components/notification/notifications.php');
        const failureTitle = page.locator('text=Demand forecast generation failed').first();
        await failureTitle.waitFor({state: 'visible'});
        assert(await failureTitle.isVisible(), 'Failure notification title is visible');

        // Safe explanation without leaking raw stack trace
        const notifBody = await page.locator('.notifications-page').textContent();
        assert(notifBody.includes('insufficient sales data'), 'Safe explanation is visible to Administrator');
        assert(!notifBody.includes('raw internal trace'), 'Raw technical trace is not exposed');

        // Review condition link pointing to predictions.php
        const reviewLink = page.locator('a:has-text("Review condition")').first();
        assert(await reviewLink.isVisible(), 'Review condition button is rendered');
        const href = await reviewLink.getAttribute('href');
        assert(href.includes('components/report/predictions.php'), 'Destination link coordinates with predictions.php');

        // Click destination link and verify 200 OK and permitted view
        const [response] = await Promise.all([
            page.waitForResponse(r => r.url().includes('predictions.php')),
            reviewLink.click()
        ]);
        assert.equal(response.status(), 200, 'Destination predictions.php responds 200 OK');
        assert(await page.locator('h1, h2').filter({hasText: /Demand Forecast/i}).first().isVisible(), 'Demand forecast page loads for Administrator');

        // --- 3. Qualified candidate awaiting review ---
        fixture('forecasting_seed_candidate');

        await page.goto(origin + '/components/notification/notifications.php');
        const candidateTitle = page.locator('text=Qualified forecast model awaiting review').first();
        await candidateTitle.waitFor({state: 'visible'});
        assert(await candidateTitle.isVisible(), 'Qualified candidate review notice is visible');

        const pageText = await page.locator('.notifications-page').textContent();
        assert(pageText.includes('rf_candidate_v9'), 'Candidate model version is named in notice');

        // --- 4. Deduplication of repeated checks ---
        const notifsBefore = query("SELECT COUNT(*) AS c FROM notifications WHERE workspace = 'admin'");
        fixture('forecasting_check');
        const notifsAfter = query("SELECT COUNT(*) AS c FROM notifications WHERE workspace = 'admin'");
        assert.equal(notifsBefore[0].c, notifsAfter[0].c, 'Repeated check of unchanged conditions does not create duplicate notices');

        // --- 5. Routine success does not create success notifications ---
        const totalBeforeSuccess = query("SELECT COUNT(*) AS c FROM notifications WHERE workspace = 'admin'");
        fixture('forecasting_seed_success');
        const totalAfterSuccess = query("SELECT COUNT(*) AS c FROM notifications WHERE workspace = 'admin'");
        assert.equal(totalBeforeSuccess[0].c, totalAfterSuccess[0].c, 'Routine successful run creates no success notification spam');

        // --- 6. Onboarding completion does NOT suppress operational alerts ---
        fixture('forecasting_complete_onboarding');
        // Trigger a fresh failure
        fixture('forecasting_seed_failure');

        await page.goto(origin + '/components/notification/notifications.php');
        assert(await page.locator('text=Demand forecast generation failed').first().isVisible(), 'Forecasting failure remains visible after onboarding completion');

        // Check Administrator dashboard "Needs attention" section
        await page.goto(origin + '/components/administrator/dashboard.php');
        const attentionSection = page.locator('#store-attention-section');
        await attentionSection.waitFor({state: 'visible'});
        const attentionText = await attentionSection.textContent();
        assert(attentionText.includes('Forecasting failure') || attentionText.includes('Demand forecast'), 'Dashboard Needs attention displays forecasting failure');

        // --- 7. Responsive and Accessibility inspection ---
        for (const width of [320, 390]) {
            await page.setViewportSize({width, height: 800});
            await page.goto(origin + '/components/notification/notifications.php');
            const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
            const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
            assert(scrollWidth <= clientWidth + 2, `No horizontal overflow at ${width}px (scroll: ${scrollWidth}, client: ${clientWidth})`);

            // Verify focus visibility on action link
            const actionBtn = page.locator('.btn:has-text("Review condition")').first();
            if (await actionBtn.isVisible()) {
                await actionBtn.focus();
                const isFocused = await actionBtn.evaluate(el => document.activeElement === el);
                assert(isFocused, `Action button is keyboard focusable at ${width}px`);
            }
        }

        // --- 8. Theme support (light and dark) ---
        await page.setViewportSize({width: 1440, height: 900});
        for (const theme of ['light', 'dark']) {
            await page.evaluate(t => {
                document.documentElement.setAttribute('data-theme', t);
                document.body.className = t + '-theme admin-dashboard-page administrator-workspace';
            }, theme);
            const notifItem = page.locator('.notification-item, .notification-list').first();
            assert(await notifItem.isVisible(), `Notifications render properly in ${theme} theme`);
        }

        // --- 9. Active Workspace isolation ---
        // Inventory manager workspace should NOT see Administrator-scoped forecasting notices
        await page.goto(origin + '/components/auth/workspace.php');
        const invOption = page.locator('.workspace-option:has(input[value="inventory_manager"])');
        if (await invOption.isVisible()) {
            await Promise.all([
                page.waitForNavigation(),
                invOption.getByRole('button', {name: 'Open'}).click()
            ]);
            await page.goto(origin + '/components/notification/notifications.php');
            const invText = await page.locator('.notifications-page').textContent();
            assert(!invText.includes('Qualified forecast model awaiting review'), 'Administrator model review notice not visible in inventory_manager workspace');
        }

        console.log('Forecasting notices browser test: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill('SIGTERM');
        spawnSync(php, ['src/backend/tests/support/theme_fixture.php', 'cleanup', database], {env});
    }
})();
