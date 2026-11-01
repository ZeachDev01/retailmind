'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

// Render production markup and shared tools without an authenticated Store database.
function render(count = 23, historyPage = '1', action = '') {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
        register_shutdown_function(function () { if ($error = error_get_last()) fwrite(STDERR, json_encode($error)); });
        require 'src/backend/bootstrap/app.php';
        require 'src/backend/includes/backup.php';
        require 'src/backend/includes/csrf.php';
        function role_capability_policy() { return new App\\Authorization\\RoleCapabilityPolicy(); }
        function current_role() { return 'super_admin'; }
        function app_url($path) { return '/'.$path; }
        function theme_url($path) { return '/'.$path; }
        function retailmind_theme_head() {}
        function format_display_datetime($value) { return $value; }
        $pdo = new PDO('sqlite::memory:');
        $service = new App\\Backup\\DatabaseBackupService($pdo, role_capability_policy());
        $restorer = new App\\Backup\\DatabaseRestoreService($pdo, role_capability_policy(), $service);
        $actorUserId = 1; $actorRole = 'super_admin';
        $escape = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $_SESSION = []; $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['history_page'] = $argv[2]; $_POST['action'] = $argv[3];
        $history = [];
        for ($i = 1; $i <= (int)$argv[1]; $i++) $history[] = [
            'snapshot_at' => '2026-11-01 10:00:00', 'created_at' => '2026-11-01 10:00:00',
            'filename' => 'backup-'.$i.'.rmpkg', 'format' => 'Encrypted Store package',
            'notes' => '', 'file_size' => 1024, 'status' => 'completed', 'requested_by' => 'Super Administrator'
        ];
        $message = ''; $messageClass = ''; $download = null; $invalidField = ''; $preflight = null;
        $maxUploadBytes = $restorer->supportedUploadBytes();
        $path = realpath('src/frontend/components/system_administrator/backup_restore.php');
        $source = file_get_contents($path);
        $source = substr($source, strpos($source, '$historyPerPage ='));
        $source = str_replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '', $source);
        $source = str_replace('__DIR__', var_export(dirname($path), true), $source);
        eval('use App\\Backup\\RecoveryStore;' . $source);
    `, String(count), historyPage, action], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}

(async () => {
    const browser = await chromium.launch({headless: true});
    const output = '.impeccable/review/superadmin-backup';
    fs.mkdirSync(output, {recursive: true});
    try {
        const page = await browser.newPage();
        async function show(count = 23, number = '1', action = '') {
            await page.setContent(render(count, number, action));
            await page.addStyleTag({path: 'src/frontend/assets/css/style.css'});
            await page.addStyleTag({path: 'src/frontend/assets/css/backup.css'});
            // The production sidebar normally owns the navigation gutter.
            await page.addStyleTag({content: '.main-content {margin:0!important;width:100%;}'});
            await page.addScriptTag({path: 'src/frontend/assets/js/backup_review.js'});
            await page.addScriptTag({path: 'src/frontend/assets/js/backup_restore.js'});
            await page.addScriptTag({path: 'src/frontend/assets/js/backup_recovery_tabs.js'});
        }
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 960});
            await show();
            assert.equal(await page.locator('.backup-summary > div').count(), 3);
            assert.equal(await page.locator('tbody tr').count(), 10);
            assert.equal(await page.getByRole('link', {name: 'Identity Reconciliation', exact: true}).count(), 1);
            assert.equal(await page.locator('[role="tabpanel"]:visible').count(), 1);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.screenshot({path: output + '/' + (width === 1440 ? 'desktop' : 'mobile') + '.png', fullPage: true});
            await page.getByRole('tab', {name: 'Safety copy', exact: true}).click();
            assert(await page.locator('#safety-panel').isVisible());
            await page.keyboard.press('ArrowRight');
            assert(await page.locator('#key-panel').isVisible());
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.screenshot({path: output + '/key-' + width + '.png', fullPage: true});
            await page.keyboard.press('Home');
            await page.getByText('Advanced: validate without restoring', {exact: true}).click();
            await page.getByLabel('Encrypted RetailMind package', {exact: true}).setInputFiles({name: 'test.rmpkg', mimeType: 'application/octet-stream', buffer: Buffer.from('fixture')});
            await page.getByLabel('Administrator password', {exact: true}).fill('fixture-password');
            await page.locator('[data-restore-form]').evaluate(form => form.addEventListener('submit', event => event.preventDefault()));
            await page.getByRole('button', {name: 'Validate package', exact: true}).click();
            assert.equal(await page.locator('[data-backup-restore]').textContent(), 'Validating package…');
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.screenshot({path: output + '/validation-' + width + '.png', fullPage: true});
        }
        for (const [number, expected, label] of [['2', 10, 'Showing 11–20 of 23'], ['999', 3, 'Showing 21–23 of 23'], ['-1', 10, 'Showing 1–10 of 23']]) {
            await show(23, number);
            assert.equal(await page.locator('tbody tr').count(), expected);
            assert.equal(await page.locator('.backup-pagination p').textContent(), label);
        }
        await show(0);
        assert.equal(await page.locator('.backup-empty').count(), 1);
        assert.equal(await page.locator('.backup-pagination').count(), 0);
        await show(1, '1', 'safety');
        assert(await page.locator('#safety-panel').isVisible(), 'Safety action returns to its tab');
        await show(1, '1', 'preflight');
        assert(await page.locator('#restore-panel details.backup-tips').evaluate(node => node.open), 'Validation feedback remains expanded');
        console.log('Superadmin backup layout: passed (production PHP, tabs, validation, pagination, empty, desktop/mobile)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

