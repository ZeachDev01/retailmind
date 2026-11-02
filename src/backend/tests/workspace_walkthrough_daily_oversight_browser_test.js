const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Daily Oversight Walkthrough: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-daily-oversight-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};

const fixture = (action, extra) => {
    const args = ['src/backend/tests/support/theme_fixture.php', action, database];
    if (extra) args.push(extra);
    const result = spawnSync(process.env.PHP_BINARY || 'php', args, {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
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

        server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {
            env: {...env, RM_THEME_TEST_DATABASE: database},
            stdio: 'ignore'
        });

        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));

        // Capture initial baseline operational & setup states
        const initialCashier = JSON.parse(fixture('cashier_state'));
        const initialStoreSettings = JSON.parse(fixture('store_settings_state'));
        const initialSetupConfirmations = JSON.parse(fixture('setup_state'));

        // 1. Log in as theme_admin
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // 2. First-visit overview offers Daily oversight with Needs Attention and Store Reports
        const overview = page.locator('#workspace-guidance');
        assert.equal(await overview.count(), 1, 'First-visit overview is present');
        const needsAttentionLink = overview.locator('a[href*="dashboard.php?guidance=dashboard"]');
        assert.ok(await needsAttentionLink.count() >= 1, 'Overview links to Needs Attention walkthrough');
        assert.match(await needsAttentionLink.first().innerText(), /Needs Attention/i);

        const storeReportsLink = overview.locator('a[href*="report_generation.php?guidance=report_generation"]');
        assert.ok(await storeReportsLink.count() >= 1, 'Overview links to Store Reports walkthrough');
        assert.match(await storeReportsLink.first().innerText(), /Store Reports/i);

        // 3. Visit Help: offers Daily oversight with Needs Attention and Store Reports, separate from Get oriented
        await page.goto(origin + '/components/auth/help.php');

        // Verify Get oriented is present
        const getOrientedHeading = page.locator('#get-oriented-title, .guidance-walkthroughs-header h2').filter({hasText: /Get oriented/i});
        assert.equal(await getOrientedHeading.count(), 1, 'Get oriented is present in Help');

        // Verify Daily oversight section is present and separate
        const dailyOversightSection = page.locator('.guidance-walkthroughs-daily, [aria-labelledby="daily-oversight-title"], section').filter({hasText: /Daily oversight/i});
        assert.ok(await dailyOversightSection.count() >= 1, 'Help displays Daily oversight walkthrough section');
        const dailyOversightHeading = page.locator('#daily-oversight-title, h2').filter({hasText: /Daily oversight/i});
        assert.equal(await dailyOversightHeading.count(), 1, 'Daily oversight heading is present in Help');

        // Needs Attention topic card in Help
        const needsAttentionTopic = page.locator('.walkthrough-topic-card').filter({hasText: /Needs Attention/i});
        assert.equal(await needsAttentionTopic.count(), 1, 'Needs Attention topic is present in Help');

        // Store Reports topic card in Help
        const storeReportsTopic = page.locator('.walkthrough-topic-card').filter({hasText: /Store Reports/i});
        assert.equal(await storeReportsTopic.count(), 1, 'Store Reports topic is present in Help');

        // Existing Help capabilities remain intact:
        assert.equal(await page.locator('.workspace-guidance-links h3').count(), 5, 'Admin Help still has 5 workflow headings');
        assert.equal(await page.locator('.help-workflow-details').count(), 5, 'Admin Help still has 5 collapsible details');
        assert.equal(await page.locator('.setup-task-confirm-form').count(), 1, 'Setup checklist remains intact');

        // 4. Test Needs Attention Walkthrough on dashboard.php
        const startNeedsAttention = needsAttentionTopic.locator('a, button').filter({hasText: /Start|Explore|Walkthrough/i}).first();
        await startNeedsAttention.click();
        await page.waitForURL(u => u.pathname.includes('dashboard.php'));

        const dock = page.locator('#guidance-dock');
        await dock.waitFor();
        assert.equal(await dock.isVisible(), true, 'Walkthrough dock appears on Dashboard page');

        // Path badge must show "Daily oversight"
        assert.match(await dock.locator('.guidance-path-badge').innerText(), /Daily oversight/i);

        // Check Step 1 of Needs Attention
        assert.match(await dock.locator('.guidance-step-count').innerText(), /Step 1 of 3/i);
        assert.match(await dock.locator('.guidance-step-title').innerText(), /Review Needs Attention exceptions/i);
        assert.ok(await dock.locator('.guidance-step-desc').isVisible());

        // Target: #store-attention-section highlighted
        const attentionSection = page.locator('#store-attention-section');
        assert.ok(await attentionSection.evaluate(el => el.classList.contains('guidance-spotlight-target')), 'store-attention-section has spotlight class');
        assert.ok(await dock.getByRole('button', {name: 'Back', exact: true}).isDisabled(), 'Back disabled on step 1');

        // Topic checklist reflects Daily oversight topics
        const dockChecklist = dock.locator('.guidance-topic-checklist');
        assert.equal(await dockChecklist.count(), 1, 'Checklist present in dock');
        const dockChecklistText = await dockChecklist.textContent();
        assert.match(dockChecklistText, /Needs Attention/i);
        assert.match(dockChecklistText, /Store Reports/i);
        // Ensure Get oriented topics are not mistakenly shown in Daily oversight checklist
        assert.ok(!dockChecklistText.includes('Fiscal Periods'), 'Daily oversight checklist only includes daily oversight topics');

        // Learn more toggle
        const learnMore = dock.locator('.guidance-learn-more');
        assert.equal(await learnMore.count(), 1, 'Learn more is available');
        await learnMore.locator('summary').click();
        assert.ok(await learnMore.locator('p').isVisible(), 'Learn more expands explanation');
        await learnMore.locator('summary').click();

        // Advance to Step 2: Refresh Store status
        await dock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 3'));
        assert.match(await dock.locator('.guidance-step-title').innerText(), /Refresh Store status/i);
        assert.ok(await page.locator('#dashboardRefreshBtn').evaluate(el => el.classList.contains('guidance-spotlight-target')), 'dashboardRefreshBtn has spotlight');

        // Test Back button
        await dock.getByRole('button', {name: 'Back', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 1 of 3'));
        assert.match(await dock.locator('.guidance-step-title').innerText(), /Review Needs Attention exceptions/i);

        // Advance to Step 2 again
        await dock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 3'));

        // 5. Continuation & Exit test for Needs Attention
        // Exit on Step 2
        await dock.getByRole('button', {name: 'Exit', exact: true}).click();
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Exit dismisses dock');
        assert.equal(await page.locator('.guidance-spotlight-target').count(), 0, 'Exit clears spotlight');

        // Visit Help: offers Continue where you left off for Needs Attention
        await page.goto(origin + '/components/auth/help.php');
        const continueCard = page.locator('.guidance-continue-card');
        await continueCard.waitFor();
        assert.match(await continueCard.innerText(), /Continue where you left off/i);
        assert.match(await continueCard.innerText(), /Needs Attention/i);
        assert.match(await continueCard.innerText(), /Step 2 of 3/i);

        // Log out, log in again as theme_admin to verify continuation across login sessions
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // Help still offers Continue for Needs Attention at Step 2
        await page.goto(origin + '/components/auth/help.php');
        const continueBtn = page.locator('.guidance-continue-card a').first();
        assert.match(await continueBtn.innerText(), /Resume Needs Attention \(Step 2 of 3\)/i);
        await continueBtn.click();
        await page.waitForURL(u => u.pathname.includes('dashboard.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 2 of 3/i, 'Resumes at step 2');

        // Advance to Step 3: Inspect operational indicators
        await page.locator('#guidance-dock').getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 3 of 3'));
        assert.match(await page.locator('#guidance-dock .guidance-step-title').innerText(), /Inspect operational indicators/i);
        assert.ok(await page.locator('#store-status-section').evaluate(el => el.classList.contains('guidance-spotlight-target')), 'store-status-section has spotlight');

        // Last step button says "Finish guidance"
        const finishNeedsAttentionBtn = page.locator('#guidance-dock').getByRole('button', {name: /Finish guidance/i});
        assert.equal(await finishNeedsAttentionBtn.count(), 1, 'Finish guidance button present on last step');

        // Finish Needs Attention guidance
        await finishNeedsAttentionBtn.click();
        const finishStatus = page.locator('.guidance-completion-status');
        await finishStatus.waitFor();
        assert.match(await finishStatus.innerText(), /Guidance finished/i);
        assert.match(await finishStatus.innerText(), /not modified|operational/i, 'Explicitly disclaims operational completion');
        assert.equal(await page.locator('.guidance-checkmark').isVisible(), true, 'Completion displays checkmark');

        // Close completion banner
        await page.locator('#guidance-btn-done').click();
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Dock closed after completion');

        // 6. Check Needs Attention Replay in Help
        await page.goto(origin + '/components/auth/help.php');
        const needsAttentionCardAfter = page.locator('.walkthrough-topic-card').filter({hasText: /Needs Attention/i});
        assert.match(await needsAttentionCardAfter.innerText(), /Guidance finished/i);
        const replayNeedsAttentionBtn = needsAttentionCardAfter.getByRole('link', {name: /Replay/i});
        assert.equal(await replayNeedsAttentionBtn.count(), 1, 'Replay action available for Needs Attention');

        // Click Replay -> restarts at step 1
        await replayNeedsAttentionBtn.click();
        await page.waitForURL(u => u.pathname.includes('dashboard.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 1 of 3/i, 'Replay restarts at step 1');

        // 7. Test Store Reports Walkthrough on report_generation.php
        await page.goto(origin + '/components/auth/help.php');
        const storeReportsCard = page.locator('.walkthrough-topic-card').filter({hasText: /Store Reports/i});
        const startStoreReports = storeReportsCard.locator('a, button').filter({hasText: /Start|Explore|Walkthrough/i}).first();
        await startStoreReports.click();
        await page.waitForURL(u => u.pathname.includes('report_generation.php'));

        const reportsDock = page.locator('#guidance-dock');
        await reportsDock.waitFor();
        assert.equal(await reportsDock.isVisible(), true, 'Walkthrough dock appears on Store Reports page');

        // Path badge must show "Daily oversight"
        assert.match(await reportsDock.locator('.guidance-path-badge').innerText(), /Daily oversight/i);

        // Topic checklist shows 1 of 2 completed (Needs Attention was completed, Store Reports is active)
        const reportsChecklist = reportsDock.locator('.guidance-topic-checklist summary');
        assert.match(await reportsChecklist.innerText(), /1 of 2 completed/i);

        // Step 1: Select Store report (#report)
        assert.match(await reportsDock.locator('.guidance-step-count').innerText(), /Step 1 of 4/i);
        assert.match(await reportsDock.locator('.guidance-step-title').innerText(), /Select Store report/i);
        const reportSelect = page.locator('#report');
        assert.ok(await reportSelect.evaluate(el => el.classList.contains('guidance-spotlight-target')), '#report select has spotlight');
        assert.ok(await reportSelect.isEnabled(), 'Report dropdown remains interactive');

        // Step 2: Set trading date filters (#from)
        await reportsDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 4'));
        assert.match(await reportsDock.locator('.guidance-step-title').innerText(), /Set trading date filters/i);
        assert.ok(await page.locator('#from').evaluate(el => el.classList.contains('guidance-spotlight-target')), '#from date input has spotlight');

        // Step 3: Narrow by product or category (#product_id)
        await reportsDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 3 of 4'));
        assert.match(await reportsDock.locator('.guidance-step-title').innerText(), /Narrow by product or category/i);
        assert.ok(await page.locator('#product_id').evaluate(el => el.classList.contains('guidance-spotlight-target')), '#product_id select has spotlight');

        // Step 4: Run Store report (.report-run-btn)
        await reportsDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 4 of 4'));
        assert.match(await reportsDock.locator('.guidance-step-title').innerText(), /Run Store report/i);
        assert.ok(await page.locator('.report-run-btn').evaluate(el => el.classList.contains('guidance-spotlight-target')), '.report-run-btn has spotlight');

        // Last step button says "Finish guidance"
        const finishStoreReportsBtn = reportsDock.getByRole('button', {name: /Finish guidance/i});
        assert.equal(await finishStoreReportsBtn.count(), 1, 'Finish guidance button present on last step');

        // Finish Store Reports guidance
        await finishStoreReportsBtn.click();
        const finishReportsStatus = page.locator('.guidance-completion-status');
        await finishReportsStatus.waitFor();
        assert.match(await finishReportsStatus.innerText(), /Guidance finished/i);
        assert.match(await finishReportsStatus.innerText(), /not modified|operational/i, 'Explicitly disclaims operational completion');

        // 8. Verify operational records and setup confirmations remain UNCHANGED
        const afterCashier = JSON.parse(fixture('cashier_state'));
        assert.deepEqual(afterCashier, initialCashier, 'Cashier and sales operational records never modified by guidance');

        const afterStoreSettings = JSON.parse(fixture('store_settings_state'));
        assert.deepEqual(afterStoreSettings, initialStoreSettings, 'Store and attention settings never modified by guidance');

        const afterSetup = JSON.parse(fixture('setup_state'));
        assert.deepEqual(afterSetup, initialSetupConfirmations, 'Setup confirmations never modified by guidance');

        // 9. Replay Store Reports from Help
        await page.goto(origin + '/components/auth/help.php');
        const storeReportsCardAfter = page.locator('.walkthrough-topic-card').filter({hasText: /Store Reports/i});
        assert.match(await storeReportsCardAfter.innerText(), /Guidance finished/i);
        const replayStoreReportsBtn = storeReportsCardAfter.getByRole('link', {name: /Replay/i});
        assert.equal(await replayStoreReportsBtn.count(), 1, 'Replay action available for Store Reports');

        // 10. Multi-account / Role Scoping
        // Log in as theme_inventory_manager
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // Inventory manager Help must NOT show Daily oversight
        await page.goto(origin + '/components/auth/help.php');
        assert.equal(await page.locator('#daily-oversight-title').count(), 0, 'No Daily oversight title for inventory manager');
        assert.equal(await page.locator('.walkthrough-topic-card').filter({hasText: /Needs Attention/i}).count(), 0, 'No Needs Attention for inventory manager');

        // Direct navigation to admin dashboard blocked
        const res = await page.goto(origin + '/components/administrator/dashboard.php?guidance=dashboard');
        assert.equal(res.status(), 403, 'Inventory manager blocked with 403 from admin dashboard');

        // 11. Keyboard navigation, Escape key, Reduced Motion, Responsive & Contrast
        // Log back in as admin
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        await page.goto(origin + '/components/administrator/dashboard.php?guidance=dashboard');
        await page.locator('#guidance-dock').waitFor();

        // Keyboard Escape exits dock
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Escape key exits dock');

        // Reduced motion check
        await page.goto(origin + '/components/administrator/dashboard.php?guidance=dashboard');
        await page.locator('#guidance-dock').waitFor();
        await page.emulateMedia({reducedMotion: 'reduce'});
        const animState = await page.locator('.guidance-spotlight-target').evaluate(el => getComputedStyle(el).animationName);
        assert.ok(animState === 'none' || animState === '', 'Reduced motion disables pulse animation: ' + animState);
        await page.emulateMedia({reducedMotion: 'no-preference'});

        // Responsive and theme check across viewports
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 1024, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Walkthrough fits width ' + width);
                for (const btn of await page.locator('#guidance-dock button, #guidance-dock a').all()) {
                    if (await btn.isVisible()) {
                        const box = await btn.boundingBox();
                        if (box) assert.ok(box.height >= 40, 'Button target comfortable height at ' + width);
                    }
                }
            }
        }

        console.log('Daily Oversight Walkthrough: All acceptance criteria passed!');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
