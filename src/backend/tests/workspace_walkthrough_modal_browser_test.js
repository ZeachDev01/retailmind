const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Walkthrough modal: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-walkthrough-modal-'));
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
        fs.mkdirSync('tmp/manual/walkthrough-ui', {recursive: true});
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));

        const initialInventory = JSON.parse(fixture('inventory_state'));
        const initialSetup = JSON.parse(fixture('setup_state'));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        for (const theme of ['light', 'dark']) {
            for (const viewport of [{width: 1280, height: 680}, {width: 1024, height: 600}, {width: 390, height: 844}, {width: 320, height: 568}]) {
                await page.setViewportSize(viewport);
                await page.goto(origin + '/components/inventory_management/products.php?guidance=products&replay=1');
                await page.locator('#guidance-dock').waitFor();
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                await page.locator('#guidance-btn-next').click();
                await page.locator('#guidance-btn-next').click();
                for (let step = 3; step <= 6; step++) {
                    await page.waitForTimeout(200);
                    const metrics = await page.locator('#add-product-modal').evaluate(modal => {
                        const body = modal.querySelector('.rm-modal-body');
                        const target = modal.querySelector('.guidance-spotlight-target');
                        const r = target.getBoundingClientRect();
                        const b = body.getBoundingClientRect();
                        const buttons = [...modal.querySelectorAll('.rm-modal-actions button, #guidance-btn-back, #guidance-btn-next, #guidance-btn-exit')].filter(el => !el.hidden && !el.disabled);
                        return {
                            bodyHeight: body.clientHeight,
                            targetVisible: r.top >= b.top && r.bottom <= b.bottom && target.contains(document.elementFromPoint(r.left + Math.min(r.width / 2, 30), r.top + Math.min(r.height / 2, 20))),
                            coveredButtons: buttons.filter(el => {
                                const box = el.getBoundingClientRect();
                                return !el.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2));
                            }).map(el => el.id || el.textContent.trim()),
                            insideDialog: !!modal.querySelector('[role="dialog"] #guidance-dock')
                        };
                    });
                    const label = theme + '/' + viewport.width + 'x' + viewport.height + '/step ' + step;
                    assert.ok(metrics.bodyHeight >= (viewport.width >= 1000 ? 180 : 80), label + ': form body collapsed: ' + JSON.stringify(metrics));
                    assert.ok(metrics.targetVisible, label + ': highlighted field clipped: ' + JSON.stringify(metrics));
                    assert.deepEqual(metrics.coveredButtons, [], label + ': modal and guide actions remain usable');
                    assert.ok(metrics.insideDialog, label + ': guide stays inside modal focus scope');
                    if (step === 3 && viewport.width === 1280 && theme === 'light') await page.screenshot({path: 'tmp/manual/walkthrough-ui/product-modal-short-after.png'});
                    if (step < 6) await page.locator('#guidance-btn-next').click();
                }
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('#add-product-modal.open, #guidance-dock').count(), 0);
            }
        }
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        for (const viewport of [{width: 1280, height: 680}, {width: 320, height: 568}]) {
            await page.setViewportSize(viewport);
            for (const entry of [
                {path: 'user_manager/user_manager.php', topic: 'user_manager', step: 2, trigger: '#openUserModal', modal: '#userModalOverlay'},
                {path: 'user_manager/user_manager.php', topic: 'user_manager', step: 3, trigger: '.open-user-drawer', modal: '#userDrawerOverlay'},
                {path: 'administrator/store_calendar.php', topic: 'store_calendar', step: 2, trigger: '#weekly-schedule-btn', modal: '#weekly-schedule-modal'},
                {path: 'administrator/store_calendar.php', topic: 'store_calendar', step: 3, trigger: '.calendar-day-cell:not(.is-empty)', modal: '#calendar-editor'}
            ]) {
                await page.goto(origin + '/components/' + entry.path + '?guidance=' + entry.topic + '&replay=1');
                await page.locator('#guidance-dock').waitFor();
                for (let step = 1; step < entry.step; step++) await page.locator('#guidance-btn-next').click();
                await page.locator(entry.trigger).first().click();
                await page.locator(entry.modal + '.open').waitFor();
                await page.waitForTimeout(200);
                assert.equal(await page.locator('#guidance-dock').isVisible(), false, 'Launcher guidance pauses inside another modal');
                for (const button of await page.locator(entry.modal + ' button:visible:not(:disabled)').all()) {
                    await button.scrollIntoViewIfNeeded();
                    const result = await button.evaluate(el => {
                        const rect = el.getBoundingClientRect();
                        const top = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
                        return {usable: el.contains(top), button: el.id, coveredBy: top?.outerHTML.slice(0, 160)};
                    });
                    if (!result.usable) await page.screenshot({path: 'tmp/manual/walkthrough-ui/other-modal-covered.png'});
                    assert.ok(result.usable, entry.topic + '/' + entry.step + '/' + viewport.width + ': modal action covered: ' + JSON.stringify(result));
                }
                await page.keyboard.press('Escape');
                await page.locator(entry.modal + '.open').waitFor({state: 'hidden'});
                assert.equal(await page.locator('#guidance-dock').isVisible(), true, 'Closing the modal restores guidance');
                await page.keyboard.press('Escape');
            }
        }
        assert.deepEqual(JSON.parse(fixture('inventory_state')), initialInventory, 'Guidance leaves inventory unchanged');
        assert.deepEqual(JSON.parse(fixture('setup_state')), initialSetup, 'Guidance leaves setup confirmations unchanged');
        console.log('Walkthrough modal: Product, Staff, and Calendar checks passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
