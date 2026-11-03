const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Forecast landing browser test: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-forecastlanding-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
const php = process.env.PHP_BINARY || 'php';

const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        console.log('Test database created: ' + database);

        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;

        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});

        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        page.setDefaultTimeout(15000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));

        const login = async role => {
            await page.goto(origin + '/components/auth/logout.php');
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([
                page.waitForURL(u => !u.search.includes('login=1')),
                page.getByRole('button', {name: 'Log in', exact: true}).click(),
            ]);
            await page.waitForLoadState('domcontentloaded');
        };

        // -------------------------------------------------------------
        // 1. Administrator Persona Checks
        // -------------------------------------------------------------
        console.log('Testing Administrator persona on Demand Forecast landing page...');
        await login('admin');
        await page.goto(origin + '/components/report/predictions.php');
        await page.waitForLoadState('domcontentloaded');

        // Check page title and structure
        assert.match(await page.locator('h1').innerText(), /Demand Forecast/);
        assert.match(await page.locator('.page-subtitle').innerText(), /Expected demand, operational actions, and model performance/);

        // Check contextual navigation chips
        const navChips = page.locator('.forecasting-nav-chips');
        await navChips.waitFor();
        const navText = await navChips.innerText();
        assert.match(navText, /Replenishment Queue/);
        assert.match(navText, /Forecast Exceptions/);
        assert.match(navText, /Data Readiness/);
        assert.match(navText, /Model Review & Approval/);
        assert.match(navText, /Store Calendar/);
        assert.match(navText, /Promotions/);

        // Check Generate Now button is present for Admin
        const generateBtn = page.locator('button[name="action"][value="generate"]');
        assert.equal(await generateBtn.count(), 1, 'Generate Now button is present for Administrator');

        // Check Section 1: "1. Can we use forecasting today?"
        const s1Heading = page.locator('#heading-usability');
        await s1Heading.waitFor();
        assert.match(await s1Heading.innerText(), /1\. Can we use forecasting today\?/);
        const statCards = page.locator('.stat-card');
        assert.equal(await statCards.count(), 4, 'Four key usability stat cards are displayed');
        const s1Text = await page.locator('section[aria-labelledby="heading-usability"]').innerText();
        assert.match(s1Text, /Forecast Coverage/);
        assert.match(s1Text, /Guidance Usability/);
        assert.match(s1Text, /Forecast Origin/);
        assert.match(s1Text, /Model Governance/);

        // Check Section 2: "2. What needs action?"
        const s2Heading = page.locator('#heading-actions');
        await s2Heading.waitFor();
        assert.match(await s2Heading.innerText(), /2\. What needs action\?/);
        const actionCards = page.locator('.action-issue-card');
        assert.ok((await actionCards.count()) >= 1, 'At least one operational issue or healthy status card is shown');
        const firstIssueText = await actionCards.first().innerText();
        assert.match(firstIssueText, /Practical effect:/);
        assert.match(firstIssueText, /Responsible:/);

        // Check Section 3: "3. Is it helping?"
        const s3Heading = page.locator('#heading-performance');
        await s3Heading.waitFor();
        assert.match(await s3Heading.innerText(), /3\. Is it helping\?/);
        const s3Text = await page.locator('section[aria-labelledby="heading-performance"]').innerText();
        assert.ok(s3Text.includes('Still collecting evidence') || s3Text.includes('Evaluation Evidence'),
            'Section 3 provides honest performance evidence status');

        const summaryText = await page.locator('section[aria-labelledby="heading-usability"] [role="status"]').innerText();
        for (const text of [summaryText, 'Still collecting evidence']) {
            assert.equal(await page.locator('#rm-toast-stack .rm-toast').filter({hasText: text}).count(), 0,
                'Persistent forecasting summaries stay inline without duplicate toasts');
        }
        await page.setViewportSize({width: 390, height: 844});
        const prose = page.locator('section[aria-labelledby="heading-performance"] [role="status"] p');
        if (await prose.count()) {
            const dimensions = await prose.evaluate(node => ({paragraph: node.getBoundingClientRect().width,
                summary: node.parentElement.getBoundingClientRect().width}));
            assert.ok(dimensions.paragraph > dimensions.summary * 0.8,
                'Narrow performance explanation uses the summary width instead of a flex column');
        }
        await page.setViewportSize({width: 1440, height: 900});

        // Check Section 4: Product Forecasts Table & Search
        const searchInput = page.locator('input[type="search"][name="search"]');
        await searchInput.waitFor();
        assert.equal(await searchInput.count(), 1, 'Search input is present');

        // Test search interaction
        await searchInput.fill('NonexistentProductQuery12345');
        await page.locator('.search-form button[type="submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        assert.match(await page.locator('.empty-state').innerText(), /No matching products found|No Forecasts Available/);

        // Clear search
        await page.locator('a:has-text("Clear")').click();
        await page.waitForLoadState('domcontentloaded');

        // Check responsive layout across viewports and themes
        for (const theme of ['light', 'dark']) {
            await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
            for (const width of [1440, 640, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.waitForTimeout(50);
                assert.equal(await page.locator('h1').isVisible(), true, `Heading visible on width ${width}px theme ${theme}`);
                assert.equal(await s1Heading.isVisible(), true, `Section 1 visible on width ${width}px theme ${theme}`);
                assert.equal(await s2Heading.isVisible(), true, `Section 2 visible on width ${width}px theme ${theme}`);
                assert.equal(await s3Heading.isVisible(), true, `Section 3 visible on width ${width}px theme ${theme}`);
            }
        }
        await page.setViewportSize({width: 1440, height: 900});

        // -------------------------------------------------------------
        // 2. Inventory Manager Persona Checks (Read-Only Explanations)
        // -------------------------------------------------------------
        console.log('Testing Inventory Manager persona on Demand Forecast landing page...');
        await login('inventory_manager');
        await page.goto(origin + '/components/report/predictions.php');
        await page.waitForLoadState('domcontentloaded');

        // Usability and sections visible
        assert.match(await page.locator('#heading-usability').innerText(), /1\. Can we use forecasting today\?/);
        assert.match(await page.locator('#heading-actions').innerText(), /2\. What needs action\?/);
        assert.match(await page.locator('#heading-performance').innerText(), /3\. Is it helping\?/);

        // Generate Now button MUST NOT be present for Inventory Manager
        assert.equal(await page.locator('button[name="action"][value="generate"]').count(), 0,
            'Inventory Manager cannot see Generate Now mutation button');

        // Unpermitted Model Review & Approval button MUST NOT be present in nav chips
        assert.equal(await page.locator('.forecasting-nav-chips a:has-text("Model Review & Approval")').count(), 0,
            'Inventory Manager does not receive Administrator Model Review link');

        // Any action items show read-only explanations with Administrator responsible
        const imIssues = page.locator('.action-issue-card');
        if (await imIssues.count() > 0) {
            const imText = await imIssues.first().innerText();
            assert.match(imText, /Responsible:/);
        }

        assert.deepEqual(pageErrors, [], 'No unexpected page errors occurred during test');
        console.log('PASS: Forecast landing browser test passed cleanly!');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        try { fixture('destroy'); } catch {}
    }
})();
