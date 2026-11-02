const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Staff and Calendar Walkthrough: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
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

        // Snapshot initial operational state
        const initialGovernance = JSON.parse(fixture('governance_state'));
        const initialSetupConfirmations = JSON.parse(fixture('setup_state'));

        // 1. Log in as theme_admin
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // 2. Dashboard first-visit overview exposes Staff accounts and Store calendar
        const overview = page.locator('#workspace-guidance');
        assert.equal(await overview.count(), 1, 'First-visit overview is present');
        assert.ok(await overview.locator('a[href*="user_manager.php"]').count() >= 1, 'Overview links to Staff accounts');
        assert.ok(await overview.locator('a[href*="store_calendar.php"]').count() >= 1, 'Overview links to Store calendar');

        // 3. Visit Help: offers Get oriented with Staff accounts and Store calendar
        await page.goto(origin + '/components/auth/help.php');
        const helpOrientedSection = page.locator('.guidance-walkthroughs');
        assert.equal(await helpOrientedSection.count(), 1, 'Help displays Get oriented walkthrough section');

        const staffTopic = helpOrientedSection.locator('.walkthrough-topic-card').filter({hasText: /Staff accounts/i});
        assert.equal(await staffTopic.count(), 1, 'Staff accounts topic is present in Help');
        assert.match(await staffTopic.innerText(), /Maintain operational staff accounts/i);
        const staffStartLink = staffTopic.locator('a').filter({hasText: /Start walkthrough|Continue/i});
        assert.equal(await staffStartLink.count(), 1, 'Staff accounts has active walkthrough start link');

        const calendarTopic = helpOrientedSection.locator('.walkthrough-topic-card').filter({hasText: /Store calendar/i});
        assert.equal(await calendarTopic.count(), 1, 'Store calendar topic is present in Help');
        assert.match(await calendarTopic.innerText(), /Review trading days and planned closures/i);
        const calendarStartLink = calendarTopic.locator('a').filter({hasText: /Start walkthrough|Continue/i});
        assert.equal(await calendarStartLink.count(), 1, 'Store calendar has active walkthrough start link');

        // Existing Help capabilities remain intact:
        assert.equal(await page.locator('.workspace-guidance-links h3').count(), 5, 'Admin Help still has 5 workflow headings');
        assert.equal(await page.locator('.help-workflow-details').count(), 5, 'Admin Help still has 5 collapsible details');
        assert.equal(await page.locator('.setup-task-confirm-form').count(), 1, 'Setup checklist remains intact');

        // 4. Security & Scoping tests
        const csrfToken = await page.locator('input[name="csrf_token"]').first().inputValue();
        const postHelp = async (body) => {
            const res = await page.request.post(origin + '/components/auth/help.php', {
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                data: new URLSearchParams(body).toString(),
            });
            return {status: res.status(), body: await res.text()};
        };

        // Forged user ID rejected
        const forgedUser = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'user_manager',
            walkthrough_step: '1',
            user_id: '9999'
        });
        assert.ok(forgedUser.status === 403 || forgedUser.status === 422, 'Forged user ID must be rejected');

        // Forged workspace rejected
        const forgedWorkspace = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'store_calendar',
            walkthrough_step: '1',
            workspace: 'cashier'
        });
        assert.ok(forgedWorkspace.status === 403 || forgedWorkspace.status === 422, 'Forged workspace must be rejected');

        // Invalid step index rejected
        const badStep = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'user_manager',
            walkthrough_step: '99'
        });
        assert.equal(badStep.status, 422, 'Invalid step index must be rejected');

        // 5. Staff Accounts Walkthrough
        await staffStartLink.click();
        await page.waitForURL(u => u.pathname.includes('user_manager.php'));

        const staffDock = page.locator('#guidance-dock');
        await staffDock.waitFor();
        assert.equal(await staffDock.isVisible(), true, 'Walkthrough dock appears on user_manager.php');

        // Step 1
        assert.match(await staffDock.locator('.guidance-step-count').innerText(), /Step 1 of 3/i);
        assert.match(await staffDock.locator('.guidance-step-title').innerText(), /Review staff accounts/i);

        // Checklist in dock shows Get oriented topics with Staff accounts current
        const staffChecklist = staffDock.locator('.guidance-topic-checklist');
        assert.equal(await staffChecklist.count(), 1, 'Checklist present in dock');
        assert.match(await staffChecklist.textContent(), /Staff accounts/i);
        assert.match(await staffChecklist.textContent(), /Store calendar/i);

        // Target spotlight on .users-table or #usersPanel
        const usersTable = page.locator('.users-table');
        assert.ok(await usersTable.evaluate(el => el.classList.contains('guidance-spotlight-target')), 'users-table has spotlight');
        assert.equal(await staffDock.getByRole('button', {name: 'Back', exact: true}).isEnabled(), false, 'Back disabled on step 1');

        // Learn more toggle
        const staffLearnMore = staffDock.locator('.guidance-learn-more');
        assert.equal(await staffLearnMore.count(), 1, 'Learn more available');
        await staffLearnMore.locator('summary').click();
        assert.ok(await staffLearnMore.locator('p').isVisible());
        assert.match(await staffLearnMore.locator('p').innerText(), /fixed operational role templates|protected/i);
        await staffLearnMore.locator('summary').click();

        // Advance to Step 2
        await staffDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 3'));
        assert.match(await staffDock.locator('.guidance-step-title').innerText(), /Add staff accounts/i);
        assert.ok(await page.locator('#openUserModal').evaluate(el => el.classList.contains('guidance-spotlight-target')), 'openUserModal has spotlight');

        // Step 2 Learn more explains Mandatory Password Change & access minimums
        await staffLearnMore.locator('summary').click();
        assert.match(await staffLearnMore.locator('p').innerText(), /Mandatory Password Change|minimum/i);
        await staffLearnMore.locator('summary').click();

        // 6. Test Resumable Progress across navigation & later login
        await page.goto(origin + '/components/administrator/dashboard.php');
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Dock not present on Dashboard');

        // Help offers Continue where you left off
        await page.goto(origin + '/components/auth/help.php');
        const continueCard = page.locator('.guidance-continue-card');
        await continueCard.waitFor();
        assert.match(await continueCard.innerText(), /Resume Staff accounts.*Step 2 of 3/i);

        // Log out, log in again
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // Go to Help, click Resume
        await page.goto(origin + '/components/auth/help.php');
        await page.locator('.guidance-continue-card a').click();
        await page.waitForURL(u => u.pathname.includes('user_manager.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 2 of 3/i, 'Resumes at step 2');

        // Test Exit
        await page.locator('#guidance-dock').getByRole('button', {name: 'Exit', exact: true}).click();
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Exit dismisses dock');
        assert.equal(await page.locator('.guidance-spotlight-target').count(), 0, 'Exit clears spotlight');
        assert.ok(await page.locator('#openUserModal').isEnabled(), 'Add user button remains functional');

        // Reopen walkthrough to finish
        await page.goto(origin + '/components/user_manager/user_manager.php?guidance=user_manager');
        await page.locator('#guidance-dock').waitFor();

        // Advance to Step 3
        await page.locator('#guidance-dock').getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 3 of 3'));
        assert.match(await page.locator('#guidance-dock .guidance-step-title').innerText(), /Inspect staff lifecycle and access/i);

        // Finish guidance
        const finishStaffBtn = page.locator('#guidance-dock').getByRole('button', {name: /Finish guidance|Complete/i});
        assert.equal(await finishStaffBtn.count(), 1, 'Finish guidance button present on last step');
        await finishStaffBtn.click();

        const staffCompletion = page.locator('.guidance-completion-status');
        await staffCompletion.waitFor();
        assert.match(await staffCompletion.innerText(), /Guidance finished/i);
        assert.match(await staffCompletion.innerText(), /not modified|operational/i, 'Disclaims operational completion');

        // Verify operational state and setup state: UNCHANGED
        const midGovernance = JSON.parse(fixture('governance_state'));
        assert.deepEqual(midGovernance.users, initialGovernance.users, 'Users table never modified by guidance');
        assert.deepEqual(midGovernance.user_roles, initialGovernance.user_roles, 'User roles never modified by guidance');
        const midSetup = JSON.parse(fixture('setup_state'));
        assert.deepEqual(midSetup, initialSetupConfirmations, 'Setup confirmations never modified by guidance');

        // Check Replay in Help for Staff accounts
        await page.goto(origin + '/components/auth/help.php');
        const staffReplayTopic = page.locator('.walkthrough-topic-card').filter({hasText: /Staff accounts/i});
        assert.match(await staffReplayTopic.innerText(), /Guidance finished/i);
        const staffReplayBtn = staffReplayTopic.getByRole('link', {name: /Replay/i});
        assert.equal(await staffReplayBtn.count(), 1, 'Replay link available for Staff accounts');

        // 7. Store Calendar Walkthrough
        const calendarTopicCard = page.locator('.walkthrough-topic-card').filter({hasText: /Store calendar/i});
        await calendarTopicCard.getByRole('link', {name: /Start walkthrough/i}).click();
        await page.waitForURL(u => u.pathname.includes('store_calendar.php'));

        const calDock = page.locator('#guidance-dock');
        await calDock.waitFor();
        assert.equal(await calDock.isVisible(), true, 'Walkthrough dock appears on store_calendar.php');

        // Step 1: Review trading days
        assert.match(await calDock.locator('.guidance-step-count').innerText(), /Step 1 of 3/i);
        assert.match(await calDock.locator('.guidance-step-title').innerText(), /Review trading days/i);
        const calGrid = page.locator('.calendar-scroll, .calendar-grid').first();
        assert.ok(await calGrid.evaluate(el => el.classList.contains('guidance-spotlight-target')), 'calendar-grid has spotlight');

        // Checklist in dock shows Staff accounts completed and Store calendar current
        const calChecklist = calDock.locator('.guidance-topic-checklist');
        assert.match(await calChecklist.textContent(), /Staff accounts/i);
        assert.match(await calChecklist.textContent(), /Store calendar/i);

        // Advance to Step 2: Configure weekly schedule
        await calDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 3'));
        assert.match(await calDock.locator('.guidance-step-title').innerText(), /Configure weekly schedule/i);
        assert.ok(await page.locator('#weekly-schedule-btn').evaluate(el => el.classList.contains('guidance-spotlight-target')), 'weekly-schedule-btn has spotlight');

        // Step 2 Learn more emphasizes that calendar does NOT qualify forecasting or create demand history
        const calLearnMore = calDock.locator('.guidance-learn-more');
        await calLearnMore.locator('summary').click();
        assert.match(await calLearnMore.locator('p').innerText(), /does not qualify forecasting|customer sales history/i);
        await calLearnMore.locator('summary').click();

        // Advance to Step 3: Record planned closures
        await calDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 3 of 3'));
        assert.match(await calDock.locator('.guidance-step-title').innerText(), /Record planned closures and holidays/i);
        assert.ok(await page.locator('#record-date-btn').evaluate(el => el.classList.contains('guidance-spotlight-target')), 'record-date-btn has spotlight');

        // Step 3 Learn more reiterates actual customer transactions vs calendar entries
        await calLearnMore.locator('summary').click();
        assert.match(await calLearnMore.locator('p').innerText(), /actual transactions|genuine customer demand/i);
        await calLearnMore.locator('summary').click();

        // Finish guidance for Store calendar
        await calDock.getByRole('button', {name: /Finish guidance|Complete/i}).click();
        const calCompletion = page.locator('.guidance-completion-status');
        await calCompletion.waitFor();
        assert.match(await calCompletion.innerText(), /Guidance finished/i);
        assert.match(await calCompletion.innerText(), /not modified|operational/i, 'Disclaims operational completion');

        // Operational state check after Calendar walkthrough: UNCHANGED
        const afterGovernance = JSON.parse(fixture('governance_state'));
        assert.deepEqual(afterGovernance.store_calendar, initialGovernance.store_calendar, 'Store calendar records never modified by guidance');
        assert.deepEqual(afterGovernance.store_weekly_schedule, initialGovernance.store_weekly_schedule, 'Store weekly schedules never modified by guidance');
        const afterSetup = JSON.parse(fixture('setup_state'));
        assert.deepEqual(afterSetup, initialSetupConfirmations, 'Setup confirmations never modified by guidance');

        // Check Help displays Guidance finished for both topics
        await page.goto(origin + '/components/auth/help.php');
        const finalStaffCard = page.locator('.walkthrough-topic-card').filter({hasText: /Staff accounts/i});
        assert.match(await finalStaffCard.innerText(), /Guidance finished/i);
        const finalCalCard = page.locator('.walkthrough-topic-card').filter({hasText: /Store calendar/i});
        assert.match(await finalCalCard.innerText(), /Guidance finished/i);

        // 8. Multi-role Scoping: Inventory Manager cannot access or see these topics
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        await page.goto(origin + '/components/auth/help.php');
        assert.equal(await page.locator('.walkthrough-topic-card').filter({hasText: /Staff accounts/i}).count(), 0, 'Inventory Manager Help does not show Staff accounts');
        assert.equal(await page.locator('.walkthrough-topic-card').filter({hasText: /Store calendar/i}).count(), 0, 'Inventory Manager Help does not show Store calendar');

        // Attempting direct navigation to user_manager or store_calendar
        const userMgrResp = await page.goto(origin + '/components/user_manager/user_manager.php?guidance=user_manager');
        assert.equal(userMgrResp.status(), 403, 'Inventory Manager cannot access user_manager');
        assert.match(await page.innerText('body'), /access denied/i);

        const calResp = await page.goto(origin + '/components/administrator/store_calendar.php?guidance=store_calendar');
        assert.equal(calResp.status(), 403, 'Inventory Manager cannot access store_calendar');
        assert.match(await page.innerText('body'), /access denied/i);

        // 9. Reduced Motion & Responsive & Themes on user_manager and store_calendar
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        for (const testPath of [
            '/components/user_manager/user_manager.php?guidance=user_manager',
            '/components/administrator/store_calendar.php?guidance=store_calendar'
        ]) {
            await page.goto(origin + testPath);
            await page.locator('#guidance-dock').waitFor();

            // Reduced motion test
            await page.emulateMedia({reducedMotion: 'reduce'});
            const anim = await page.locator('.guidance-spotlight-target').evaluate(el => getComputedStyle(el).animationName);
            assert.ok(anim === 'none' || anim === '', 'Reduced motion disables pulse animation on ' + testPath);
            await page.emulateMedia({reducedMotion: 'no-preference'});

            // Viewports and Themes
            for (const theme of ['light', 'dark']) {
                for (const width of [1440, 1024, 390, 320]) {
                    await page.setViewportSize({width, height: 900});
                    await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Walkthrough fits width ' + width + ' on ' + testPath);
                    for (const btn of await page.locator('#guidance-dock button, #guidance-dock a').all()) {
                        if (await btn.isVisible()) {
                            const box = await btn.boundingBox();
                            if (box) assert.ok(box.height >= 40, 'Button target comfortable height at ' + width);
                        }
                    }
                }
            }
        }

        console.log('Staff and Calendar Walkthrough: All acceptance criteria passed!');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
