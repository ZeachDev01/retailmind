const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Registers and Fiscal Periods Walkthrough: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const root = path.resolve(__dirname, '../../..');
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-walkthrough-reg-fp-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};

const fixture = (action, extra) => {
    const args = ['src/backend/tests/support/theme_fixture.php', action, database];
    if (extra) args.push(extra);
    const result = spawnSync(process.env.PHP_BINARY || 'php', args, {
        cwd: root,
        env,
        encoding: 'utf8'
    });
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
            cwd: root,
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

        // Capture initial governance state and setup confirmations
        const initialGovernance = JSON.parse(fixture('governance_state'));
        const initialSetupConfirmations = JSON.parse(fixture('setup_state'));

        // 1. Log in as theme_admin
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // 2. Dashboard first-visit overview exposes Register and Fiscal Period topics
        const overview = page.locator('#workspace-guidance');
        await overview.waitFor();
        const registersOverviewLink = overview.locator('a[href*="registers.php"]').filter({hasText: /Registers/i});
        assert.ok(await registersOverviewLink.count() >= 1, 'Overview links to Registers walkthrough');
        const fiscalOverviewLink = overview.locator('a[href*="fiscal_periods.php"]').filter({hasText: /Fiscal Periods/i});
        assert.ok(await fiscalOverviewLink.count() >= 1, 'Overview links to Fiscal Periods walkthrough');

        // 3. Visit Help: exposes Get oriented with Registers and Fiscal Periods topics
        await page.goto(origin + '/components/auth/help.php');
        const helpOrientedSection = page.locator('.guidance-walkthroughs');
        await helpOrientedSection.waitFor();
        assert.match(await helpOrientedSection.innerText(), /Get oriented/i);

        const registersTopic = helpOrientedSection.locator('.walkthrough-topic-card').filter({hasText: /Registers/i});
        assert.equal(await registersTopic.count(), 1, 'Registers topic is present in Help');
        assert.match(await registersTopic.innerText(), /Review or create named till records and receipt-width settings/i);

        const fiscalTopic = helpOrientedSection.locator('.walkthrough-topic-card').filter({hasText: /Fiscal Periods/i});
        assert.equal(await fiscalTopic.count(), 1, 'Fiscal Periods topic is present in Help');
        assert.match(await fiscalTopic.innerText(), /Find and understand the existing.*period workflow/i);

        // Security & CSRF validation
        const csrfToken = await page.locator('input[name="csrf_token"]').first().inputValue();
        const postHelp = async (body) => {
            const res = await page.request.post(origin + '/components/auth/help.php', {
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                data: new URLSearchParams(body).toString(),
            });
            return {status: res.status(), body: await res.text()};
        };

        const forgedUser = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'registers',
            walkthrough_step: '1',
            user_id: '9999'
        });
        assert.ok(forgedUser.status === 403 || forgedUser.status === 422, 'Forged user ID must be rejected');

        const forgedWorkspace = await postHelp({
            csrf_token: csrfToken,
            walkthrough_action: 'step',
            walkthrough_topic: 'fiscal_periods',
            walkthrough_step: '1',
            workspace: 'cashier'
        });
        assert.ok(forgedWorkspace.status === 403 || forgedWorkspace.status === 422, 'Forged workspace must be rejected');

        const badCsrf = await postHelp({
            csrf_token: 'invalid_token_xyz',
            walkthrough_action: 'step',
            walkthrough_topic: 'registers',
            walkthrough_step: '1'
        });
        assert.equal(badCsrf.status, 403, 'Invalid CSRF must be rejected');

        // ==========================================
        // 4. REGISTER WALKTHROUGH
        // ==========================================
        const startRegistersBtn = registersTopic.locator('a, button').filter({hasText: /Start|Explore|Walkthrough/i}).first();
        await startRegistersBtn.click();
        await page.waitForURL(u => u.pathname.includes('registers.php'));

        const regDock = page.locator('#guidance-dock');
        await regDock.waitFor();
        assert.equal(await regDock.isVisible(), true, 'Walkthrough dock appears on Registers page');

        // Step 1: Named till records
        assert.match(await regDock.locator('.guidance-step-count').innerText(), /Step 1 of 3/i);
        assert.match(await regDock.locator('.guidance-step-title').innerText(), /Review named till records/i);
        assert.match(await regDock.locator('.guidance-step-desc').innerText(), /checkout|till/i);

        // Target control spotlight: #register-name
        const regNameInput = page.locator('#register-name');
        assert.ok(await regNameInput.evaluate(el => el.classList.contains('guidance-spotlight-target')), 'register-name has spotlight');
        await regNameInput.focus();
        assert.equal(await regNameInput.evaluate(el => document.activeElement === el), true, 'register-name remains usable');

        // Learn more disclaims printer-driver / hardware installation
        const regLearnMore = regDock.locator('.guidance-learn-more');
        await regLearnMore.locator('summary').click();
        const regLearnMoreText = await regLearnMore.locator('p').innerText();
        assert.match(regLearnMoreText, /hardware|driver|operating system/i, 'Explains no hardware driver installation needed');
        await regLearnMore.locator('summary').click();

        // Shared topic checklist shows 5 topics
        const regChecklist = regDock.locator('.guidance-topic-checklist');
        assert.match(await regChecklist.textContent(), /Registers/i);

        // Step 2: Receipt paper width
        await regDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 3'));
        assert.match(await regDock.locator('.guidance-step-title').innerText(), /Configure receipt width/i);
        const paperWidthSelect = page.locator('#register-paper-width');
        assert.ok(await paperWidthSelect.evaluate(el => el.classList.contains('guidance-spotlight-target')), 'register-paper-width has spotlight');

        // Learn more disclaims Platform Settings authority
        await regLearnMore.locator('summary').click();
        assert.match(await regLearnMore.locator('p').innerText(), /Platform Settings|width/i);
        await regLearnMore.locator('summary').click();

        // Step 3: Review existing tills
        await regDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 3 of 3'));
        assert.match(await regDock.locator('.guidance-step-title').innerText(), /Review existing till availability/i);
        assert.match(await regDock.locator('.guidance-step-desc').innerText(), /without creating duplicate records/i);

        // Next button is "Finish guidance"
        const finishRegBtn = regDock.getByRole('button', {name: /Finish guidance|Complete/i});
        assert.equal(await finishRegBtn.count(), 1, 'Finish guidance button present on last step');

        // Test Exit and Continuation for Registers
        const exitRegBtn = regDock.getByRole('button', {name: 'Exit', exact: true});
        await exitRegBtn.click();
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Exit dismisses dock');
        assert.equal(await page.locator('.guidance-spotlight-target').count(), 0, 'Exit clears spotlight');

        // Visit Help: offers Continue where you left off for Registers
        await page.goto(origin + '/components/auth/help.php');
        const continueCard = page.locator('.guidance-continue-card');
        await continueCard.waitFor();
        assert.match(await continueCard.innerText(), /Continue where you left off/i);
        assert.match(await continueCard.innerText(), /Registers.*Step 3 of 3/i);

        // Resume Registers
        await continueCard.locator('a').first().click();
        await page.waitForURL(u => u.pathname.includes('registers.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 3 of 3/i);

        // Finish guidance for Registers
        await page.locator('#guidance-dock').getByRole('button', {name: /Finish guidance/i}).click();
        const regCompletion = page.locator('.guidance-completion-status');
        await regCompletion.waitFor();
        assert.match(await regCompletion.innerText(), /Guidance finished/i);
        assert.match(await regCompletion.innerText(), /Registers walkthrough completed.*not modified/i);
        assert.equal(await page.locator('.guidance-checkmark').isVisible(), true, 'Brief checkmark displayed');

        // Verify operational records and setup confirmations unchanged
        const midGovernance = JSON.parse(fixture('governance_state'));
        assert.deepEqual(midGovernance.registers, initialGovernance.registers, 'Registers records unchanged by guidance navigation and completion');
        assert.deepEqual(JSON.parse(fixture('setup_state')), initialSetupConfirmations, 'Setup confirmations unchanged');

        // Help now shows Registers finished, offers Replay
        await page.goto(origin + '/components/auth/help.php');
        const registersTopicFinished = page.locator('.walkthrough-topic-card').filter({hasText: /Registers/i});
        assert.match(await registersTopicFinished.innerText(), /Guidance finished/i);
        const replayRegBtn = registersTopicFinished.getByRole('link', {name: /Replay/i});
        assert.equal(await replayRegBtn.count(), 1, 'Replay action available for Registers in Help');

        // Replay restarts at Step 1
        await replayRegBtn.click();
        await page.waitForURL(u => u.pathname.includes('registers.php'));
        await page.locator('#guidance-dock').waitFor();
        assert.match(await page.locator('#guidance-dock .guidance-step-count').innerText(), /Step 1 of 3/i, 'Replay restarts Registers at step 1');

        // ==========================================
        // 5. FISCAL PERIOD WALKTHROUGH
        // ==========================================
        await page.goto(origin + '/components/auth/help.php');
        const fiscalTopicHelp = page.locator('.walkthrough-topic-card').filter({hasText: /Fiscal Periods/i});
        const startFiscalBtn = fiscalTopicHelp.locator('a, button').filter({hasText: /Start|Explore|Walkthrough/i}).first();
        await startFiscalBtn.click();
        await page.waitForURL(u => u.pathname.includes('fiscal_periods.php'));

        const fpDock = page.locator('#guidance-dock');
        await fpDock.waitFor();
        assert.equal(await fpDock.isVisible(), true, 'Walkthrough dock appears on Fiscal Periods page');

        // Checklist in dock shows Registers completed
        const fpChecklist = fpDock.locator('.guidance-topic-checklist');
        assert.match(await fpChecklist.textContent(), /1 of 5 completed/i);

        // Step 1: Name fiscal accounting periods
        assert.match(await fpDock.locator('.guidance-step-count').innerText(), /Step 1 of 3/i);
        assert.match(await fpDock.locator('.guidance-step-title').innerText(), /Name fiscal accounting periods/i);
        const periodNameInput = page.locator('#period_name');
        assert.ok(await periodNameInput.evaluate(el => el.classList.contains('guidance-spotlight-target')), 'period_name has spotlight');
        await periodNameInput.focus();
        assert.equal(await periodNameInput.evaluate(el => document.activeElement === el), true, 'period_name remains usable');

        // Learn more explains Store calendar distinction
        const fpLearnMore = fpDock.locator('.guidance-learn-more');
        await fpLearnMore.locator('summary').click();
        assert.match(await fpLearnMore.locator('p').innerText(), /Store calendar/i);
        await fpLearnMore.locator('summary').click();

        // Step 2: Set period date range
        await fpDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 2 of 3'));
        assert.match(await fpDock.locator('.guidance-step-title').innerText(), /Set period date range/i);
        const startDateInput = page.locator('#start_date');
        assert.ok(await startDateInput.evaluate(el => el.classList.contains('guidance-spotlight-target')), 'start_date has spotlight');

        // Step 3: Review period workflow and status
        await fpDock.getByRole('button', {name: 'Next', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.guidance-step-count')?.textContent.includes('Step 3 of 3'));
        assert.match(await fpDock.locator('.guidance-step-title').innerText(), /Review period workflow and status/i);
        assert.match(await fpDock.locator('.guidance-step-desc').innerText(), /without creating duplicate periods/i);

        // Learn more explains open, closed, locked under existing authorization rules
        await fpLearnMore.locator('summary').click();
        assert.match(await fpLearnMore.locator('p').innerText(), /open.*clos.*lock.*authorization rules/i);
        await fpLearnMore.locator('summary').click();

        // Test Escape key exits walkthrough
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Escape dismisses dock');

        // Help offers Continue for Fiscal Periods
        await page.goto(origin + '/components/auth/help.php');
        const fpContinueCard = page.locator('.guidance-continue-card');
        await fpContinueCard.waitFor();
        assert.match(await fpContinueCard.innerText(), /Fiscal Periods.*Step 3 of 3/i);

        // Resume Fiscal Periods
        await fpContinueCard.locator('a').first().click();
        await page.waitForURL(u => u.pathname.includes('fiscal_periods.php'));
        await page.locator('#guidance-dock').waitFor();

        // Finish guidance for Fiscal Periods
        await page.locator('#guidance-dock').getByRole('button', {name: /Finish guidance/i}).click();
        const fpCompletion = page.locator('.guidance-completion-status');
        await fpCompletion.waitFor();
        assert.match(await fpCompletion.innerText(), /Guidance finished/i);
        assert.match(await fpCompletion.innerText(), /Fiscal Periods walkthrough completed.*not modified/i);

        // Verify operational records and setup confirmations unchanged
        const finalGovernance = JSON.parse(fixture('governance_state'));
        assert.deepEqual(finalGovernance.fiscal_periods, initialGovernance.fiscal_periods, 'Fiscal periods unchanged by guidance navigation and completion');
        assert.deepEqual(JSON.parse(fixture('setup_state')), initialSetupConfirmations, 'Setup confirmations unchanged');

        // Help now shows both finished
        await page.goto(origin + '/components/auth/help.php');
        const fpTopicFinished = page.locator('.walkthrough-topic-card').filter({hasText: /Fiscal Periods/i});
        assert.match(await fpTopicFinished.innerText(), /Guidance finished/i);
        const replayFpBtn = fpTopicFinished.getByRole('link', {name: /Replay/i});
        assert.equal(await replayFpBtn.count(), 1, 'Replay action available for Fiscal Periods');

        // A completed topic stays closed; only Replay reopens its dock.
        await page.goto(origin + '/components/system_administrator/fiscal_periods.php?guidance=fiscal_periods');
        await page.waitForLoadState('networkidle');
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Completed topic does not reopen its dock');

        await page.goto(origin + '/components/system_administrator/fiscal_periods.php?guidance=fiscal_periods&replay=1');
        await page.locator('#guidance-dock').waitFor();
        assert.equal(await page.locator('#guidance-dock').isVisible(), true, 'Replay explicitly reopens the dock');

        // Fiscal Periods actions redirect after POST so the dock cannot return on refresh.
        await page.goto(origin + '/components/system_administrator/fiscal_periods.php');
        await page.locator('#prefix').fill('FY');
        await page.locator('#count').fill('2');
        await page.getByRole('button', {name: /Generate periods/i}).click();
        await page.waitForURL(u => u.pathname.endsWith('/fiscal_periods.php') && u.search === '');
        assert.match(await page.locator('.message.success').innerText(), /Generated 2 monthly fiscal periods/i);
        assert.equal(await page.locator('#guidance-dock').count(), 0, 'Generated schedule does not reopen the guidance dock');

        // 6. Non-admin isolation
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // Attempting to visit registers or fiscal_periods with guidance as inventory manager is denied
        const regRes = await page.goto(origin + '/components/administrator/registers.php');
        assert.ok(regRes.status() === 403 || !page.url().includes('registers.php'), 'Inventory manager denied registers');

        await page.goto(origin + '/components/auth/help.php');
        assert.equal(await page.locator('.guidance-continue-card').count(), 0, 'No admin continue cards for inventory manager');

        // 7. Responsive & Theme & Reduced motion checks
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        await page.goto(origin + '/components/administrator/registers.php?guidance=registers');
        await page.locator('#guidance-dock').waitFor();

        await page.emulateMedia({reducedMotion: 'reduce'});
        const regAnim = await page.locator('.guidance-spotlight-target').evaluate(el => getComputedStyle(el).animationName);
        assert.ok(regAnim === 'none' || regAnim === '', 'Reduced motion disables pulse animation');
        await page.emulateMedia({reducedMotion: 'no-preference'});

        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 1024, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Registers walkthrough fits width ' + width);
                for (const btn of await page.locator('#guidance-dock button, #guidance-dock a').all()) {
                    if (await btn.isVisible()) {
                        const box = await btn.boundingBox();
                        if (box) assert.ok(box.height >= 40, 'Button target comfortable height at ' + width);
                    }
                }
            }
        }

        console.log('Registers and Fiscal Periods Walkthrough: All acceptance criteria passed!');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
