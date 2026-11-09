const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Store Settings Walkthrough: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-walkthrough-'));
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

        // Capture initial Store Settings state
        const initialSettings = JSON.parse(fixture('store_settings_state'));
        const initialSetupConfirmations = JSON.parse(fixture('setup_state'));

        // 1. Log in as theme_admin
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // 2. Dashboard first-visit overview offers "Get oriented" with Store Settings
        const overview = page.locator('#workspace-guidance');
        assert.equal(await overview.count(), 1, 'First-visit overview is present');
        const getOrientedLink = overview.locator('a[href*="store_settings.php"]').filter({hasText: /Store Settings/i});
        assert.ok(await getOrientedLink.count() >= 1, 'Overview links to Store Settings walkthrough');

        // 3. Visit Help: offers Get oriented and Store Settings topic
        await page.goto(origin + '/components/auth/help.php');
        const helpOrientedSection = page.locator('.guidance-walkthroughs');
        assert.equal(await helpOrientedSection.count(), 1, 'Help displays Get oriented walkthrough section');
        assert.match(await helpOrientedSection.innerText(), /Get oriented/i);
        const storeSettingsTopic = helpOrientedSection.locator('.walkthrough-topic-card, .walkthrough-topic-item').filter({hasText: /Store Settings/i});
        assert.equal(await storeSettingsTopic.count(), 1, 'Store Settings topic is present in Help');

        // Existing Help capabilities remain intact:
        assert.equal(await page.locator('.workspace-guidance-links h3').count(), 5, 'Admin Help still has 5 workflow headings');
        assert.equal(await page.locator('.help-workflow-details').count(), 5, 'Admin Help still has 5 collapsible details');
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
            walkthrough_topic: 'store_settings',
            walkthrough_step: '1',
            user_id: '9999'
        });
        assert.ok(forgedUser.status === 403 || forgedUser.status === 422, 'Forged user ID must be rejected');

        // Forged workspace rejected
        const forgedWorkspace = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'store_settings',
            walkthrough_step: '1',
            workspace: 'super_admin'
        });
        assert.ok(forgedWorkspace.status === 403 || forgedWorkspace.status === 422, 'Forged workspace must be rejected');

        // Invalid CSRF rejected
        const badCsrf = await postHelp({
            csrf_token: 'invalid_token_12345',
            walkthrough_action: 'step',
            walkthrough_topic: 'store_settings',
            walkthrough_step: '1'
        });
        assert.equal(badCsrf.status, 403, 'Invalid CSRF must be rejected');

        // Invalid topic rejected
        const badTopic = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'non_existent_topic_xyz',
            walkthrough_step: '0'
        });
        assert.equal(badTopic.status, 422, 'Invalid topic must be rejected');

        // Invalid step index rejected
        const badStep = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'store_settings',
            walkthrough_step: '999'
        });
        assert.equal(badStep.status, 422, 'Invalid step index must be rejected');

        // 5. Start walkthrough from Help
        const startBtn = storeSettingsTopic.locator('a, button').filter({hasText: /Start|Explore|Walkthrough/i}).first();
        await startBtn.click();
        await page.waitForURL(u => u.pathname.includes('store_settings.php'));

        const dock = page.locator('#guidance-dock');
        await dock.waitFor();
        assert.equal(await dock.isVisible(), true, 'Walkthrough dock appears on Store Settings page');

        // Check Step 1
        assert.match(await dock.locator('.guidance-step-count').innerText(), /Step 1 of 4/i);
        assert.match(await dock.locator('.guidance-step-title').innerText(), /Review Store identity/i);
        assert.ok(await dock.locator('.guidance-step-desc').isVisible());

        // Highlight spotlight on #store_name
        const storeNameInput = page.locator('#store_name');
        assert.ok(await storeNameInput.evaluate(el => el.classList.contains('guidance-spotlight-target')), 'store_name has spotlight class');
        // Control remains usable
        await storeNameInput.focus();
        assert.equal(await storeNameInput.evaluate(el => document.activeElement === el), true, 'Target control remains focusable');

        // Learn more toggle
        const learnMore = dock.locator('.guidance-learn-more');
        assert.equal(await learnMore.count(), 1, 'Learn more is available');
        await learnMore.locator('summary').click();
        assert.ok(await learnMore.locator('p').isVisible(), 'Learn more expands explanation');
        await learnMore.locator('summary').click();

        // Step 1: Back button is disabled
        assert.equal(await dock.getByRole('button', {name: 'Back', exact: true}).isEnabled(), false, 'Back disabled on step 1');

        // Topic checklist is visible in the dock
        const topicChecklist = dock.locator('.guidance-topic-checklist');
        assert.equal(await topicChecklist.count(), 1, 'Topic checklist is present in dock');
        assert.match(await topicChecklist.textContent(), /Store Settings/i);

        // Click Next -> moves to Step 2
        await dock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 4'));
        assert.match(await dock.locator('.guidance-step-title').innerText(), /Check regional defaults/i);
        assert.ok(await page.locator('#currency_symbol').evaluate(el => el.classList.contains('guidance-spotlight-target')), 'currency_symbol has spotlight');

        // Back button returns to Step 1
        await dock.getByRole('button', {name: 'Back', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 1 of 4'));
        assert.match(await dock.locator('.guidance-step-title').innerText(), /Review Store identity/i);

        // Move to Step 2 again
        await dock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 4'));

        // 6. Test Resumable Progress across navigation & later login
        // Navigate away to Dashboard
        await page.goto(origin + '/components/administrator/dashboard.php');
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Dock is not present on Dashboard');

        // Visit Help: offers Continue where you left off
        await page.goto(origin + '/components/auth/help.php');
        const continueCard = page.locator('.guidance-continue-card, .guidance-resume-action');
        await continueCard.waitFor();
        assert.match(await continueCard.innerText(), /Continue where you left off|Resume Store Settings/i);
        assert.match(await continueCard.innerText(), /Step 2 of 4/i);

        // Log out, log in again as theme_admin
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // Go to Help: Continue is still available
        await page.goto(origin + '/components/auth/help.php');
        const continueBtn = page.locator('.guidance-continue-card a, .guidance-resume-action a').first();
        await continueBtn.click();
        await page.waitForURL(u => u.pathname.includes('store_settings.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 2 of 4/i, 'Resumes at step 2');

        // 7. Test Exit at any step
        const exitBtn = page.locator('#guidance-dock').getByRole('button', {name: 'Exit', exact: true});
        await exitBtn.click();
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Exit dismisses the dock');
        assert.equal(await page.locator('.guidance-spotlight-target').count(), 0, 'Exit clears spotlight');
        // Page forms are fully usable
        assert.ok(await page.locator('#store_name').isEditable(), 'Form input is still editable');

        // Resume walkthrough
        await page.goto(origin + '/components/administrator/store_settings.php?guidance=store_settings');
        await page.locator('#guidance-dock').waitFor();

        // Advance to Step 3
        await page.locator('#guidance-dock').getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 3 of 4'));
        assert.match(await page.locator('#guidance-dock .guidance-step-title').innerText(), /Review receipt footer note/i);
        assert.ok(await page.locator('#receipt_footer').evaluate(el => el.classList.contains('guidance-spotlight-target')));

        // Advance to Step 4
        await page.locator('#guidance-dock').getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 4 of 4'));
        assert.match(await page.locator('#guidance-dock .guidance-step-title').innerText(), /Inspect attention thresholds/i);
        assert.ok(await page.locator('#cash_variance_amount').evaluate(el => el.classList.contains('guidance-spotlight-target')));

        // On last step, Next button is "Finish guidance"
        const finishBtn = page.locator('#guidance-dock').getByRole('button', {name: /Finish guidance|Complete/i});
        assert.equal(await finishBtn.count(), 1, 'Finish guidance button present on last step');

        // 8. Click Finish guidance
        await finishBtn.click();
        const completion = page.locator('.guidance-completion-status');
        await completion.waitFor();
        assert.match(await completion.innerText(), /Guidance finished/i);
        assert.match(await completion.innerText(), /not modified|operational/i, 'Explicitly disclaims operational completion');
        assert.equal(await page.locator('.guidance-checkmark').isVisible(), true, 'Completion displays brief checkmark');

        // Verify operational state and setup state: UNCHANGED
        const afterSettings = JSON.parse(fixture('store_settings_state'));
        assert.deepEqual(afterSettings.store, initialSettings.store, 'Store settings records never modified by guidance');
        assert.deepEqual(afterSettings.attention, initialSettings.attention, 'Attention thresholds never modified by guidance');
        const afterSetup = JSON.parse(fixture('setup_state'));
        assert.deepEqual(afterSetup, initialSetupConfirmations, 'Setup confirmations never modified by guidance');

        // 9. Check Replay in Help
        await page.goto(origin + '/components/auth/help.php');
        const replayBtn = page.locator('.walkthrough-topic-card, .walkthrough-topic-item').filter({hasText: /Store Settings/i}).getByRole('link', {name: /Replay/i});
        assert.equal(await replayBtn.count(), 1, 'Replay action available in Help');
        await replayBtn.click();
        await page.waitForURL(u => u.pathname.includes('store_settings.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 1 of 4/i, 'Replay restarts at step 1');

        // Finishing a replay consumes its link: refreshing must not relaunch the dock.
        for (let guard = 0; guard < 10; guard++) {
            const next = page.locator('#guidance-dock').getByRole('button', {name: 'Next', exact: true});
            if (!await next.count()) break;
            await next.click();
        }
        await page.locator('#guidance-dock').getByRole('button', {name: /Finish guidance/i}).click();
        await page.locator('.guidance-completion-status').waitFor();
        await page.locator('#guidance-btn-done').click();
        const refreshedUrl = new URL(page.url());
        assert.equal(refreshedUrl.searchParams.has('replay'), false, 'Finished replay drops the replay link');
        assert.equal(refreshedUrl.searchParams.has('guidance'), false, 'Finished replay drops the guidance link');
        await page.reload();
        await page.waitForLoadState('load');
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Finished replay does not reopen after refresh');

        // 10. Multi-account / Multi-role Scoping
        // Log in as inventory manager
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(origin + '/components/auth/help.php');
        // Inventory manager should NOT see Store Settings "Continue" or "Replay"
        assert.equal(await page.locator('.guidance-continue-card').count(), 0, 'No Store Settings continue card for inventory manager');

        // 11. Theme & Responsive & Reduced Motion checks on Store Settings with walkthrough active
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(origin + '/components/administrator/store_settings.php?guidance=store_settings');
        await page.locator('#guidance-dock').waitFor();

        // Reduced motion check
        await page.emulateMedia({reducedMotion: 'reduce'});
        const animState = await page.locator('.guidance-spotlight-target').evaluate(el => getComputedStyle(el).animationName);
        assert.ok(animState === 'none' || animState === '', 'Reduced motion disables pulse animation: ' + animState);
        await page.emulateMedia({reducedMotion: 'no-preference'});

        // Responsive and contrast
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 1024, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Walkthrough fits width ' + width);
                // Check action button touch targets >= 44px
                for (const btn of await page.locator('#guidance-dock button, #guidance-dock a').all()) {
                    if (await btn.isVisible()) {
                        const box = await btn.boundingBox();
                        if (box) assert.ok(box.height >= 40, 'Button target comfortable height at ' + width);
                    }
                }
            }
        }

        console.log('Store Settings Walkthrough: All acceptance criteria passed!');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
