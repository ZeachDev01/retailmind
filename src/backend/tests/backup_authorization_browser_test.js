'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

function renderRestore() {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
        require 'src/backend/bootstrap/app.php';
        require 'src/backend/includes/backup.php';
        require 'src/backend/includes/csrf.php';
        function role_capability_policy() { return new App\\Authorization\\RoleCapabilityPolicy(); }
        function app_url($path) { return '/'.$path; }
        $pdo = new PDO('sqlite::memory:');
        $service = new App\\Backup\\DatabaseBackupService($pdo, role_capability_policy());
        $escape = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $_SESSION = [];
        require 'src/frontend/components/backup/restore_form.php';
    `], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    return result.stdout;
}

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.setContent('<main>' + renderRestore() + '</main>');
        await page.addScriptTag({path: 'src/frontend/assets/js/backup_review.js'});
        assert.equal(await page.getByLabel('Current login password').isVisible(), false, 'Password appears only after Review backup');
        assert.equal(await page.getByText('Restore replaces newer records, including sales.', {exact: true}).isVisible(), false);
        await page.getByRole('button', {name: 'Review backup', exact: true}).click();
        assert.equal(await page.getByRole('dialog').count(), 0, 'A backup file is required before authorization');
        await page.getByLabel('Backup file (.rmpkg)').setInputFiles({name: 'store.rmpkg', mimeType: 'application/octet-stream', buffer: Buffer.from('fixture')});
        await page.getByRole('button', {name: 'Review backup', exact: true}).click();
        const dialog = page.getByRole('dialog');
        await dialog.waitFor();
        await page.addStyleTag({path: 'src/frontend/assets/css/style.css'});
        await page.addStyleTag({path: 'src/frontend/assets/css/backup.css'});
        fs.mkdirSync('tmp/manual/backup-authorization', {recursive: true});
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 900});
            assert.equal(await dialog.evaluate(node => node.getBoundingClientRect().right <= innerWidth), true);
            assert.equal(await dialog.evaluate(node => Math.abs(node.getBoundingClientRect().left - (document.documentElement.clientWidth - node.getBoundingClientRect().right)) < 2), true, 'Dialog stays centered');
            await page.screenshot({path: `tmp/manual/backup-authorization/restore-dialog-${width}.png`, fullPage: true});
        }
        assert.equal(await dialog.getByText('Restore replaces newer records, including sales.', {exact: true}).isVisible(), true);
        assert.equal(await dialog.getByLabel('Current login password').evaluate(input => input === document.activeElement), true);
        await dialog.getByLabel('Current login password').fill('discard-me');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[name="restore_password"]').inputValue(), '', 'Cancel clears the password');
        await page.getByRole('button', {name: 'Review backup', exact: true}).click();
        await dialog.getByRole('button', {name: 'Review backup', exact: true}).click();
        assert.equal(await dialog.isVisible(), true, 'Empty password cannot continue');
        await dialog.getByLabel('Current login password').fill('fixture-password');
        await page.evaluate(() => document.addEventListener('submit', event => {
            event.preventDefault();
            window.submitted = Object.fromEntries(new FormData(event.target, event.submitter));
        }, {capture: true}));
        await dialog.getByRole('button', {name: 'Review backup', exact: true}).click();
        assert.equal(await page.evaluate(() => window.submitted.restore_password), 'fixture-password');
        assert.equal(await page.evaluate(() => window.submitted.backup_file.name), 'store.rmpkg');
        console.log('Backup authorization: passed');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
