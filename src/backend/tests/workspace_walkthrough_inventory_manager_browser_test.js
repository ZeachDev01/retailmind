const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Inventory Manager Walkthrough: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-im-walkthrough-'));
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

        // Capture initial inventory & operational state
        const initialCashierState = JSON.parse(fixture('cashier_state'));
        const initialProductsCount = initialCashierState.inventory.length;

        const login = async (role) => {
            await page.goto(origin + '/components/auth/logout.php');
            await page.goto(origin + '/?login=1');
            const userField = page.locator('#landing-login-username');
            await userField.waitFor({state: 'visible'});
            await userField.fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            try {
                await Promise.all([
                    page.waitForURL(u => !u.search.includes('login=1')),
                    page.getByRole('button', {name: 'Log in', exact: true}).click()
                ]);
            } catch (err) {
                const alertText = await page.locator('.alert, .tag-warning, .tag-danger').allInnerTexts();
                console.error('Login failed for ' + role + ' at URL: ' + page.url() + ' Alerts: ' + JSON.stringify(alertText));
                throw err;
            }
        };

        // 1. Log in as theme_inventory_manager
        await login('inventory_manager');

        // 2. Dashboard first-visit overview offers "First useful tasks"
        const overview = page.locator('#workspace-guidance');
        assert.equal(await overview.count(), 1, 'First-visit overview is present for Inventory Manager');
        const firstUsefulLink = overview.locator('a[href*="replenishment_queue.php?guidance=replenishment_queue"], a[href*="guidance="]').filter({hasText: /Replenishment|First useful tasks/i});
        assert.ok(await firstUsefulLink.count() >= 1, 'Overview links to First useful tasks / Replenishment guidance');

        // 3. Visit Help: offers First useful tasks with 3 topics
        await page.goto(origin + '/components/auth/help.php');
        const helpWalkthroughs = page.locator('.guidance-walkthroughs');
        assert.equal(await helpWalkthroughs.count(), 1, 'Help displays First useful tasks walkthrough section');
        assert.match(await helpWalkthroughs.innerText(), /First useful tasks/i);

        const replenishmentCard = helpWalkthroughs.locator('.walkthrough-topic-card, .walkthrough-topic-item').filter({hasText: /Replenishment/i});
        assert.equal(await replenishmentCard.count(), 1, 'Replenishment needs topic is present');

        const productCard = helpWalkthroughs.locator('.walkthrough-topic-card, .walkthrough-topic-item').filter({hasText: /Product setup/i});
        assert.equal(await productCard.count(), 1, 'Product setup topic is present');

        const receivingCard = helpWalkthroughs.locator('.walkthrough-topic-card, .walkthrough-topic-item').filter({hasText: /Stock Receiving/i});
        assert.equal(await receivingCard.count(), 1, 'Stock Receiving topic is present');

        // Existing Help capabilities remain intact:
        assert.equal(await page.locator('.workspace-guidance-links h3').count(), 6, 'Inventory Manager Help retains 6 workflow headings');
        assert.equal(await page.locator('.help-workflow-details').count(), 6, 'Inventory Manager Help retains 6 collapsible details');
        assert.equal(await page.locator('.setup-task-confirm-form').count(), 1, 'Setup checklist remains intact');

        // 4. Security & Scoping tests via fetch
        const csrfToken = await page.locator('input[name="csrf_token"]').first().inputValue();
        const postHelp = async (body, cookie) => {
            const headers = {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'};
            if (cookie) headers['Cookie'] = cookie;
            const res = await page.request.post(origin + '/components/auth/help.php', {
                headers,
                data: new URLSearchParams(body).toString(),
            });
            return {status: res.status(), body: await res.text()};
        };

        // Forged user_id rejected
        const forgedUser = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'replenishment_queue',
            walkthrough_step: '1',
            user_id: '9999'
        });
        assert.ok(forgedUser.status === 403 || forgedUser.status === 422, 'Forged user ID must be rejected');

        // Forged workspace rejected
        const forgedWorkspace = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'replenishment_queue',
            walkthrough_step: '1',
            workspace: 'admin'
        });
        assert.ok(forgedWorkspace.status === 403 || forgedWorkspace.status === 422, 'Forged workspace must be rejected');

        // Invalid CSRF rejected
        const badCsrf = await postHelp({
            csrf_token: 'invalid_token_12345',
            walkthrough_action: 'step',
            walkthrough_topic: 'replenishment_queue',
            walkthrough_step: '1'
        });
        assert.equal(badCsrf.status, 403, 'Invalid CSRF must be rejected');

        // Inaccessible admin topic rejected for inventory_manager
        const adminTopicForIM = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'store_settings',
            walkthrough_step: '0'
        });
        assert.equal(adminTopicForIM.status, 422, 'Admin topic must be rejected for Inventory Manager');

        // 5. Test Topic 1: Replenishment needs walkthrough
        const startReplenishmentBtn = replenishmentCard.locator('a, button').filter({hasText: /Start|Explore|Walkthrough/i}).first();
        await startReplenishmentBtn.click();
        await page.waitForURL(u => u.pathname.includes('replenishment_queue.php'));

        const dock = page.locator('#guidance-dock');
        await dock.waitFor();
        assert.equal(await dock.isVisible(), true, 'Walkthrough dock appears on Replenishment Queue');

        // Step counts & shared checklist
        assert.match(await dock.locator('.guidance-step-count').innerText(), /Step 1 of \d+/i);
        const checklist = dock.locator('.guidance-topic-checklist');
        assert.equal(await checklist.count(), 1, 'Checklist present in dock');
        assert.match(await checklist.textContent(), /Replenishment needs/i);
        assert.match(await checklist.textContent(), /Product setup/i);
        assert.match(await checklist.textContent(), /Stock Receiving/i);

        // Explains buying suggestions and advisory nature
        await dock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2'));
        const dockText = await dock.innerText();
        assert.match(dockText, /buying|recommend|suggest|stock/i, 'Explains buying suggestions');

        // Test Back button
        await dock.getByRole('button', {name: 'Back', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 1'));

        // Test Exit
        await dock.getByRole('button', {name: 'Exit', exact: true}).click();
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Exit dismisses dock');
        assert.equal(await page.locator('.guidance-spotlight-target').count(), 0, 'Exit clears spotlight');

        // 6. Test Topic 2: Product setup walkthrough & controls
        await page.goto(origin + '/components/auth/help.php');
        const startProductBtn = page.locator('.walkthrough-topic-card, .walkthrough-topic-item').filter({hasText: /Product setup/i}).locator('a, button').filter({hasText: /Start|Explore|Walkthrough/i}).first();
        await startProductBtn.click();
        await page.waitForURL(u => u.pathname.includes('products.php'));
        await page.locator('#guidance-dock').waitFor();

        // Advance through product guidance steps
        let productStepCount = 0;
        const countText = await page.locator('#guidance-dock .guidance-step-count').innerText();
        const match = countText.match(/Step \d+ of (\d+)/i);
        if (match) productStepCount = parseInt(match[1], 10);
        assert.ok(productStepCount >= 4, 'Product guidance has at least 4 steps');

        // Walk through all steps and verify descriptions cover product details, opening stock, package sizes, stock rules
        let accumulatedProductText = '';
        for (let s = 1; s <= productStepCount; s++) {
            accumulatedProductText += ' ' + await page.locator('#guidance-dock').innerText();
            if (s < productStepCount) {
                await page.locator('#guidance-dock').getByRole('button', {name: 'Next', exact: true}).click();
                await page.waitForFunction((expected) => document.querySelector('.guidance-step-count')?.textContent.includes('Step ' + expected), s + 1);
            }
        }
        assert.match(accumulatedProductText, /product details|name|catalog/i, 'Product guidance covers product details');
        assert.match(accumulatedProductText, /opening stock|starting/i, 'Product guidance covers counted opening stock');
        assert.match(accumulatedProductText, /package|unit/i, 'Product guidance covers package sizes');
        assert.match(accumulatedProductText, /stock rules|reorder/i, 'Product guidance covers stock rules');

        // Exit Product setup guidance
        await page.locator('#guidance-dock').getByRole('button', {name: 'Exit', exact: true}).click();
        assert.equal(await page.locator('#guidance-dock').count(), 0);

        // 7. Test Topic 3: Stock Receiving walkthrough & Completion
        await page.goto(origin + '/components/auth/help.php');
        const startReceivingBtn = page.locator('.walkthrough-topic-card, .walkthrough-topic-item').filter({hasText: /Stock Receiving/i}).locator('a, button').filter({hasText: /Start|Explore|Walkthrough/i}).first();
        await startReceivingBtn.click();
        await page.waitForURL(u => u.pathname.includes('stock_receiving.php'));
        await page.locator('#guidance-dock').waitFor();

        // Check Stock Receiving guidance explains actual arrival and not demand history
        let receivingText = '';
        const receivingMatch = (await page.locator('#guidance-dock .guidance-step-count').innerText()).match(/Step \d+ of (\d+)/i);
        const receivingTotalSteps = receivingMatch ? parseInt(receivingMatch[1], 10) : 4;
        for (let s = 1; s <= receivingTotalSteps; s++) {
            receivingText += ' ' + await page.locator('#guidance-dock').innerText();
            const learnMore = page.locator('#guidance-dock .guidance-learn-more summary');
            if (await learnMore.count() > 0) {
                await learnMore.click();
                receivingText += ' ' + await page.locator('#guidance-dock .guidance-learn-more').innerText();
            }
            if (s < receivingTotalSteps) {
                await page.locator('#guidance-dock').getByRole('button', {name: 'Next', exact: true}).click();
                await page.waitForFunction((expected) => document.querySelector('.guidance-step-count')?.textContent.includes('Step ' + expected), s + 1);
            }
        }
        assert.match(receivingText, /physically arrived|actual goods|arrived/i, 'Explains recording goods that actually arrived');
        assert.match(receivingText, /demand history|sales/i, 'Explains receiving is not customer demand history');

        // Click Finish guidance on last step
        const finishBtn = page.locator('#guidance-dock').getByRole('button', {name: /Finish guidance|Complete/i});
        assert.equal(await finishBtn.count(), 1, 'Finish guidance button present on last step');
        await finishBtn.click();

        const completion = page.locator('.guidance-completion-status');
        await completion.waitFor();
        assert.match(await completion.innerText(), /Guidance finished/i);
        assert.match(await completion.innerText(), /not modified|operational/i, 'Explicitly disclaims operational completion');

        // Close completion
        await page.locator('#guidance-btn-done').click();
        assert.equal(await page.locator('#guidance-dock').count(), 0);

        // 8. Verify operational state: UNCHANGED (no products created, no stock received)
        const afterCashierState = JSON.parse(fixture('cashier_state'));
        assert.equal(afterCashierState.inventory.length, initialProductsCount, 'No products created by guidance navigation');
        assert.deepEqual(afterCashierState.stock_movements, initialCashierState.stock_movements, 'Stock movements unchanged');
        assert.deepEqual(afterCashierState.inventory_adjustments, initialCashierState.inventory_adjustments, 'Inventory adjustments unchanged');

        // 9. Replay Stock Receiving from Help
        await page.goto(origin + '/components/auth/help.php');
        const replayReceivingBtn = page.locator('.walkthrough-topic-card, .walkthrough-topic-item').filter({hasText: /Stock Receiving/i}).getByRole('link', {name: /Replay/i});
        assert.equal(await replayReceivingBtn.count(), 1, 'Replay action available for completed Stock Receiving');
        await replayReceivingBtn.click();
        await page.waitForURL(u => u.pathname.includes('stock_receiving.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 1 of \d+/i, 'Replay restarts at step 1');

        // 10. Resumable progress & Continuation across navigation and login
        // Advance to Step 2 of Stock Receiving replay
        await page.locator('#guidance-dock').getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of '));

        // Navigate away to Dashboard
        await page.goto(origin + '/components/inventory_management/inventory_overview.php');
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Dock not present on general inventory overview');

        // Help offers Continue where you left off
        await page.goto(origin + '/components/auth/help.php');
        const continueCard = page.locator('.guidance-continue-card, .guidance-resume-action');
        await continueCard.waitFor();
        assert.match(await continueCard.innerText(), /Continue where you left off|Resume Stock Receiving/i);
        assert.match(await continueCard.innerText(), /Step 2 of \d+/i);

        // Log out and log in again
        await login('inventory_manager');

        // Continue button resumes at Step 2
        await page.goto(origin + '/components/auth/help.php');
        const continueBtn = page.locator('.guidance-continue-card a, .guidance-resume-action a').first();
        await continueBtn.click();
        await page.waitForURL(u => u.pathname.includes('stock_receiving.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 2 of \d+/i, 'Resumes at step 2 after login');

        // 11. Multi-role Scoping: switch to Administrator
        await login('super_admin');

        // Switch to admin workspace
        await page.goto(origin + '/components/auth/workspace.php');
        const adminWorkspaceForm = page.locator('.workspace-option').filter({has: page.locator('input[value="admin"]')});
        if (await adminWorkspaceForm.count() > 0) {
            await adminWorkspaceForm.locator('button[type="submit"]').click();
            await page.waitForURL(u => u.pathname.includes('administrator'));
            await page.goto(origin + '/components/auth/help.php');
            assert.match(await page.locator('.guidance-walkthroughs h2').innerText(), /Get oriented/i, 'Admin workspace shows Get oriented');
            assert.equal(await page.locator('.guidance-walkthroughs').filter({hasText: /First useful tasks/i}).count(), 0, 'Admin does NOT see First useful tasks');

            // Switch to inventory_manager workspace
            await page.goto(origin + '/components/auth/workspace.php');
            const imWorkspaceForm = page.locator('.workspace-option').filter({has: page.locator('input[value="inventory_manager"]')});
            await imWorkspaceForm.locator('button[type="submit"]').click();
            await page.waitForURL(u => u.pathname.includes('inventory_management'));
            await page.goto(origin + '/components/auth/help.php');
            assert.match(await page.locator('.guidance-walkthroughs h2').innerText(), /First useful tasks/i, 'Inventory Manager workspace shows First useful tasks');
            assert.equal(await page.locator('.guidance-walkthroughs').filter({hasText: /Get oriented/i}).count(), 0, 'Inventory Manager does NOT see Get oriented');
        }

        // 12. Accessibility & Themes & Reduced motion
        await login('inventory_manager');

        await page.goto(origin + '/components/inventory_management/replenishment_queue.php?guidance=replenishment_queue');
        await page.locator('#guidance-dock').waitFor();

        // Keyboard navigation: Escape key exits walkthrough
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Escape exits walkthrough');

        // Reopen for visual & responsive checks
        await page.goto(origin + '/components/inventory_management/replenishment_queue.php?guidance=replenishment_queue');
        await page.locator('#guidance-dock').waitFor();

        // Reduced motion check
        await page.emulateMedia({reducedMotion: 'reduce'});
        const animState = await page.locator('.guidance-spotlight-target').evaluate(el => getComputedStyle(el).animationName);
        assert.ok(animState === 'none' || animState === '', 'Reduced motion disables animation: ' + animState);
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

        console.log('Inventory Manager Walkthrough: All acceptance criteria passed!');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
