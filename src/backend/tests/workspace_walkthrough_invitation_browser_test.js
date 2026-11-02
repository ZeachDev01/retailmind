const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Administrator Walkthrough Invitation: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-invitation-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};

const fixture = (action, extra, extra2) => {
    const args = ['src/backend/tests/support/theme_fixture.php', action, database];
    if (extra) args.push(extra);
    if (extra2) args.push(extra2);
    const result = spawnSync(process.env.PHP_BINARY || 'php', args, {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    try {
        const seedData = JSON.parse(fixture('create'));
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

        // 1. Create a newly created Administrator (admin_one) with temporary password
        const adminOne = JSON.parse(fixture('create_admin_with_temp_password', 'admin_one'));

        // 2. Verify password requirement cannot be bypassed
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill(adminOne.username);
        await page.locator('#landing-login-password').fill(adminOne.password);
        await Promise.all([page.waitForURL(u => u.pathname.includes('change_password.php')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        // Attempting to bypass by direct navigation to dashboard is redirected back to change_password.php
        await page.goto(origin + '/components/administrator/dashboard.php');
        assert.ok(page.url().includes('change_password.php'), 'Cannot bypass password requirement by navigating to dashboard');
        assert.equal(await page.locator('#workspace-invitation').count(), 0, 'Invitation is not accessible before password change');

        // Complete Mandatory Password Change for admin_one
        const newPasswordOne = 'AdminOnePassword123';
        await page.locator('#current_password').fill(adminOne.password);
        await page.locator('#new_password').fill(newPasswordOne);
        await page.locator('#confirm_password').fill(newPasswordOne);
        await Promise.all([page.waitForURL(u => u.pathname.includes('dashboard.php')), page.getByRole('button', {name: 'Save new password', exact: true}).click()]);

        // 3. Newly created Administrator is offered invitation after Mandatory Password Change (shared setup incomplete)
        const invitation = page.locator('#workspace-invitation');
        await invitation.waitFor();
        assert.equal(await invitation.isVisible(), true, 'Invitation is displayed in Administrator Workspace after mandatory password change');
        assert.match(await invitation.innerText(), /Welcome to the Administrator Workspace|Get oriented/i);

        const startBtn = invitation.locator('#btn-invitation-start, button[name="invitation_choice"][value="start"]');
        const notNowBtn = invitation.locator('#btn-invitation-dismiss, button[name="invitation_choice"][value="dismiss"], button[name="invitation_choice"][value="not_now"]');
        assert.equal(await startBtn.count(), 1, 'Start guidance button is present');
        assert.equal(await notNowBtn.count(), 1, 'Not now button is present');

        // Verify keyboard accessibility of invitation controls
        await startBtn.focus();
        assert.equal(await startBtn.evaluate(el => document.activeElement === el), true, 'Start guidance button is focusable');
        await notNowBtn.focus();
        assert.equal(await notNowBtn.evaluate(el => document.activeElement === el), true, 'Not now button is focusable');

        // 4. Test Security: Forged user_id, forged workspace, and invalid CSRF rejected
        const csrfToken = await page.locator('input[name="csrf_token"]').first().inputValue();
        const postHelp = async (body) => {
            const res = await page.request.post(origin + '/components/auth/help.php', {
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                data: new URLSearchParams(body).toString(),
            });
            return {status: res.status(), body: await res.text()};
        };

        const badCsrf = await postHelp({
            csrf_token: 'invalid_token_9999',
            invitation_action: 'choice',
            invitation_choice: 'dismiss',
            user_id: String(adminOne.user_id),
            workspace: 'admin'
        });
        assert.equal(badCsrf.status, 403, 'Invalid CSRF must be rejected with 403');

        const forgedUser = await postHelp({
            csrf_token: csrfToken,
            invitation_action: 'choice',
            invitation_choice: 'dismiss',
            user_id: '9999',
            workspace: 'admin'
        });
        assert.ok(forgedUser.status === 403 || forgedUser.status === 422, 'Forged user ID must be rejected');

        const forgedWorkspace = await postHelp({
            csrf_token: csrfToken,
            invitation_action: 'choice',
            invitation_choice: 'dismiss',
            user_id: String(adminOne.user_id),
            workspace: 'cashier'
        });
        assert.ok(forgedWorkspace.status === 403 || forgedWorkspace.status === 422, 'Forged workspace must be rejected');

        // Verify state is completely unchanged after failed forgery attempts
        const stateAfterFailedSecurity = JSON.parse(fixture('get_guidance_row', String(adminOne.user_id), 'admin'));
        assert.equal(stateAfterFailedSecurity.invitation_status, 'invited', 'Invitation status remains invited after rejected tampering');

        // 5. Test "Not now" dismisses the invitation and choice persists across navigation, refresh, and later login
        await Promise.all([page.waitForNavigation(), notNowBtn.click()]);
        assert.equal(await page.locator('#workspace-invitation').count(), 0, 'Invitation dismissed on Not now');

        // Database persistence check
        const rowAfterDismiss = JSON.parse(fixture('get_guidance_row', String(adminOne.user_id), 'admin'));
        assert.equal(rowAfterDismiss.invitation_status, 'dismissed', 'invitation_status is saved as dismissed in DB');
        assert.equal(rowAfterDismiss.status, null, 'Overview status remains distinct and not prematurely marked completed');

        // Refresh: stays dismissed
        await page.reload();
        assert.equal(await page.locator('#workspace-invitation').count(), 0, 'Invitation stays dismissed on refresh');

        // Navigation: stays dismissed
        await page.goto(origin + '/components/administrator/shift_report.php');
        assert.equal(await page.locator('#workspace-invitation').count(), 0, 'Invitation stays dismissed on navigation');

        // Later login: stays dismissed
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill(adminOne.username);
        await page.locator('#landing-login-password').fill(newPasswordOne);
        await Promise.all([page.waitForURL(u => u.pathname.includes('dashboard.php')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        assert.equal(await page.locator('#workspace-invitation').count(), 0, 'Invitation stays quiet on later login without repeated automatic launch');

        // Help remains available
        await page.goto(origin + '/components/auth/help.php');
        assert.equal(await page.locator('.guidance-walkthroughs').count(), 1, 'Help still offers walkthroughs');

        // 6. Test second new Administrator (admin_two) with shared Store setup COMPLETE
        fixture('complete_store_setup');

        const adminTwo = JSON.parse(fixture('create_admin_with_temp_password', 'admin_two'));

        // Admin one's choice does NOT suppress Admin two's invitation!
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill(adminTwo.username);
        await page.locator('#landing-login-password').fill(adminTwo.password);
        await Promise.all([page.waitForURL(u => u.pathname.includes('change_password.php')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        const newPasswordTwo = 'AdminTwoPassword123';
        await page.locator('#current_password').fill(adminTwo.password);
        await page.locator('#new_password').fill(newPasswordTwo);
        await page.locator('#confirm_password').fill(newPasswordTwo);
        await Promise.all([page.waitForURL(u => u.pathname.includes('dashboard.php')), page.getByRole('button', {name: 'Save new password', exact: true}).click()]);

        // Even though shared Store setup is complete, Admin two is offered personal invitation!
        const invitationTwo = page.locator('#workspace-invitation');
        await invitationTwo.waitFor();
        assert.equal(await invitationTwo.isVisible(), true, 'Invitation offered to Admin Two even when shared Store setup is complete');

        // 7. Test "Start guidance" begins Get oriented
        const startBtnTwo = invitationTwo.locator('#btn-invitation-start, button[name="invitation_choice"][value="start"]');
        await Promise.all([page.waitForURL(u => u.pathname.includes('store_settings.php') && u.search.includes('guidance=store_settings')), startBtnTwo.click()]);

        const dock = page.locator('#guidance-dock');
        await dock.waitFor();
        assert.equal(await dock.isVisible(), true, 'Start guidance opened Store Settings walkthrough');

        // Invitation choice for admin_two is saved and does not repeat
        const rowAdminTwo = JSON.parse(fixture('get_guidance_row', String(adminTwo.user_id), 'admin'));
        assert.equal(rowAdminTwo.invitation_status, 'started', 'invitation_status is saved as started');

        await page.goto(origin + '/components/administrator/dashboard.php');
        assert.equal(await page.locator('#workspace-invitation').count(), 0, 'Invitation does not repeat after starting guidance');

        // 8. Existing users with prior choices are not silently re-enrolled
        // theme_admin is an existing admin created during fixture initialization
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(seedData.password);
        await Promise.all([page.waitForURL(u => u.pathname.includes('dashboard.php')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        assert.equal(await page.locator('#workspace-invitation').count(), 0, 'Existing user theme_admin is not enrolled in automatic invitation');

        // 9. Verify setup completion messaging does not claim to suppress new Administrator personal invitations
        await page.goto(origin + '/components/auth/help.php');
        const setupHelp = page.locator('.help-setup');
        assert.ok(await setupHelp.isVisible());
        // Verify messaging in setup completion alert if present or content text
        const pageContent = await page.content();
        assert.ok(!pageContent.includes('Automatic onboarding reminders are now turned off.') || pageContent.includes('personal'), 'Setup completion messaging does not claim to suppress personal orientation');

        // 10. Responsive & Theme check on 320px viewport
        await page.setViewportSize({width: 320, height: 568});
        // Create admin_three to view invitation on 320px
        const adminThree = JSON.parse(fixture('create_admin_with_temp_password', 'admin_three'));
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill(adminThree.username);
        await page.locator('#landing-login-password').fill(adminThree.password);
        await Promise.all([page.waitForURL(u => u.pathname.includes('change_password.php')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);

        const newPasswordThree = 'AdminThreePassword123';
        await page.locator('#current_password').fill(adminThree.password);
        await page.locator('#new_password').fill(newPasswordThree);
        await page.locator('#confirm_password').fill(newPasswordThree);
        await Promise.all([page.waitForURL(u => u.pathname.includes('dashboard.php')), page.getByRole('button', {name: 'Save new password', exact: true}).click()]);

        const invitationThree = page.locator('#workspace-invitation');
        await invitationThree.waitFor();
        const box = await invitationThree.boundingBox();
        assert.ok(box.width <= 320, 'Invitation fits within 320px viewport');
        assert.equal(await invitationThree.locator('#btn-invitation-start, button[name="invitation_choice"][value="start"]').isVisible(), true, 'Start button visible at 320px');
        assert.equal(await invitationThree.locator('#btn-invitation-dismiss, button[name="invitation_choice"][value="dismiss"], button[name="invitation_choice"][value="not_now"]').isVisible(), true, 'Not now button visible at 320px');

        console.log('Administrator Walkthrough Invitation: All acceptance criteria passed!');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill('SIGTERM');
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})();
