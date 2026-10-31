'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const source = fs.readFileSync('src/frontend/components/administrator/database_backup.php', 'utf8');
    const start = source.indexOf('<section class="dashboard-section backup-recovery"');
    const end = source.indexOf('<section class="dashboard-section backup-history"', start);
    const markup = source.slice(start, end).replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '')
        .replace('data-recovery-tab=""', 'data-recovery-tab="restore-panel"')
        .replace(/(<div id="(?:restore|safety)-panel"[^>]*>)/g, '$1<p>Tool form</p>')
        .replace(/(<div id="key-panel"[^>]*>)/, '$1<section id="recovery-key"><input aria-label="Key password" type="password"></section>');
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.setContent('<body class="administrator-backup-page"><main class="main-content">' + markup + '</main></body>');
        await page.addStyleTag({content: '* {box-sizing:border-box;} :root {--primary:#2563eb;--muted:#475569;--text:#0f172a;--border:#cbd5e1;--surface-soft:#f1f5f9;}' + fs.readFileSync('src/frontend/assets/css/backup.css', 'utf8')});
        assert.equal(await page.locator('[role="tabpanel"]:visible').count(), 3, 'Tools remain available without JavaScript');
        await page.addScriptTag({path: 'src/frontend/assets/js/backup_recovery_tabs.js'});
        for (const width of [1440, 375]) {
            await page.setViewportSize({width, height: 900});
            await page.getByRole('tab', {name: 'Restore', exact: true}).click();
            assert.equal(await page.locator('#restore-panel').isVisible(), true);
            await page.keyboard.press('ArrowRight');
            assert.equal(await page.locator('#safety-panel').isVisible(), true);
            await page.keyboard.press('End');
            assert.equal(await page.locator('#key-panel').isVisible(), true);
            await page.getByLabel('Key password').fill('retained');
            await page.getByRole('tab', {name: 'Restore', exact: true}).click();
            await page.keyboard.press('ArrowLeft');
            assert.equal(await page.getByLabel('Key password').inputValue(), 'retained');
            await page.keyboard.press('Home');
            assert.equal(await page.locator('#restore-panel').isVisible(), true);
            assert.equal(await page.locator('[role="tabpanel"]:visible').count(), 1);
            assert.equal(await page.locator('[role="tab"][tabindex="0"]').count(), 1);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'No horizontal overflow');
        }
        await page.evaluate(() => { location.hash = 'recovery-key'; });
        await page.locator('#key-panel').waitFor({state: 'visible'});
        await page.evaluate(() => { location.hash = ''; document.querySelector('#backup-recovery').dataset.recoveryTab = 'safety-panel'; });
        await page.locator('#safety-panel').waitFor({state: 'visible'});
        console.log('Backup recovery tabs: passed');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
