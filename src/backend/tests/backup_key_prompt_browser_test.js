'use strict';
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');
const fs = require('node:fs');

function render(afterDownload) {
    const result = spawnSync('php', ['-r', `
        namespace App\\Backup { class RecoveryStore {
            static function isAvailable() { return true; }
            static function isPaused() { return false; }
        } }
        namespace {
            function app_url($path) { return '/'.$path; }
            function csrf_field() { return '<input name="csrf_token" type="hidden" value="fixture">'; }
            $escape = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            $backupRecommendation = ['fingerprint' => str_repeat('a', 64)];
            $download = ${afterDownload ? "['key_fingerprint' => str_repeat('b', 64)]" : 'null'};
            require 'src/frontend/components/backup/recovery_key_recommendation.php';
        }
    `], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    return result.stdout;
}

module.exports = {render};
if (require.main === module) (async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({acceptDownloads: true});
        let failDownload = false;
        await page.route('http://backup.test/**', route => route.fulfill(route.request().url().endsWith('/download') ? {
            status: failDownload ? 500 : 200,
            headers: failDownload ? {} : {'Content-Disposition': 'attachment; filename="fixture.rmpkg"'},
            body: 'fixture',
        } : route.request().url().includes('/assets/') ? {
            body: fs.readFileSync('src/frontend' + new URL(route.request().url()).pathname),
        } : {contentType: 'text/html', body: '<body></body>'}));
        await page.goto('http://backup.test/');
        async function show(afterDownload) {
            await page.setContent('<body><button data-backup-create>Create backup</button>' +
                (afterDownload ? '<div><a data-backup-download href="http://backup.test/download">Download backup</a></div>' : '') + render(afterDownload) + '</body>');
            await page.addStyleTag({path: 'src/frontend/assets/css/style.css'});
            await page.addStyleTag({path: 'src/frontend/assets/css/backup.css'});
            await page.addStyleTag({url: 'http://backup.test/assets/vendor/bootstrap-icons/bootstrap-icons.min.css'});
            await page.addScriptTag({path: 'src/frontend/assets/js/backup_key_recommendation.js'});
        }
        await show(false);
        assert.equal(await page.locator('dialog[open]').count(), 0,
            'Opening an admin dashboard with an unacknowledged scheduled backup must not open the recovery-key modal');
        await show(true);
        assert.equal(await page.locator('dialog[open]').count(), 0, 'Creating alone must not open the modal');
        failDownload = true;
        await page.locator('[data-backup-download]').click();
        await page.getByRole('status').filter({hasText: 'Download unavailable'}).waitFor();
        assert.equal(await page.locator('dialog[open]').count(), 0, 'Failed downloads must not open the modal');
        failDownload = false;
        for (const width of [1440, 768, 390]) {
            await page.setViewportSize({width, height: 900});
            await show(true);
            await page.locator('[data-backup-download]').click();
            await page.locator('dialog[open]').waitFor();
            await page.evaluate(() => document.fonts.ready);
            assert.equal(await page.locator('dialog').getAttribute('data-fingerprint'), 'b'.repeat(64), 'The prompt uses the downloaded backup key');
            assert.equal(await page.locator('[data-backup-download]').isVisible(), false);
            const bounds = await page.locator('dialog').boundingBox();
            assert(bounds.x >= 0 && bounds.x + bounds.width <= width, 'Dialog fits viewport');
            assert(await page.locator('dialog').evaluate(el => el.scrollWidth <= el.clientWidth), 'Dialog content fits');
            fs.mkdirSync('tmp/manual/backup-key-prompt', {recursive: true});
            await page.screenshot({path: `tmp/manual/backup-key-prompt/${width}.png`});
            await page.getByLabel('Current login password').fill('discard-me');
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => document.querySelector('[name="key_password"]').value === '');
            assert.equal(await page.getByLabel('Current login password').inputValue(), '', 'Escape clears the password');
        }
        console.log('Backup key prompt: passed (no dashboard popup; download success/failure; exact key; responsive; Escape)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
