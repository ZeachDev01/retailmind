'use strict';
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const template = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
        require 'vendor/autoload.php';
        function csrf_field() { return ''; }
        $roles = [];
        include 'src/frontend/components/user_manager/modals/manage_user_modal.php';
    `], {cwd: root, encoding: 'utf8'});
    assert.equal(template.status, 0, template.stderr);
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.route('http://retailmind.test/**', route => route.fulfill({
            contentType: 'text/html',
            body: `<div id="rm-flash-messages" hidden data-success="User created successfully in the Store."></div>${template.stdout}`,
        }));
        await page.goto('http://retailmind.test/components/user_manager/user_manager.php');
        await page.addScriptTag({path: path.join(root, 'src/frontend/assets/js/ui.js')});
        await page.evaluate(() => RetailMindUI.initPageContent());
        await page.waitForFunction(() => document.querySelector('.rm-toast.success'));
        assert.deepEqual(await page.locator('.rm-toast .rm-toast-copy > span').allTextContents(),
            ['User created successfully in the Store.'], 'Creating an account must not toast the hidden pending-duties warning');
        assert.equal(await page.locator('#drawerPendingDuties').getAttribute('data-rm-alert-processed'), null,
            'Hidden duty warning must remain unprocessed');
        await page.evaluate(() => {
            document.getElementById('drawerPendingDuties').hidden = false;
            RetailMindUI.initPageContent();
        });
        assert.equal(await page.locator('.rm-toast.warning').count(), 0, 'Hidden status panel must not notify');
        await page.evaluate(() => {
            document.getElementById('drawerPanelStatus').hidden = false;
            RetailMindUI.initPageContent();
        });
        assert.equal(await page.locator('.rm-toast.warning').count(), 0, 'Closed account drawer must not notify');
        await page.evaluate(() => {
            document.getElementById('userDrawerOverlay').classList.add('is-open');
            document.getElementById('userDrawerOverlay').setAttribute('aria-hidden', 'false');
            RetailMindUI.initPageContent();
            RetailMindUI.initPageContent();
        });
        await page.waitForFunction(() => document.querySelector('.rm-toast.warning'));
        assert.deepEqual(await page.locator('.rm-toast.warning .rm-toast-copy > span').allTextContents(),
            ['Unfinished duties pending: This account has open cash or GCash duties that require operational handover.'],
            'An actual displayed duty warning must still notify exactly once');
        console.log('User pending duties toast: passed');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
