'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

// Render the shared templates without a database or recovery side effects.
function render(tool, verified = false) {
    const php = `
        namespace App\\Authorization { class RoleCapabilityPolicy { const MANAGE_DATABASE_BACKUP = 'backup'; } }
        namespace {
            function current_role() { return 'admin'; }
            function role_capability_policy() { return new class { function allows(...$args) { return true; } }; }
            function app_url($path) { return '/'.$path; }
            function csrf_field() { return '<input type="hidden" name="csrf_token" value="fixture">'; }
            $_SESSION = [];
            $escape = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            $recoveryTabbed = true;
            $safetyError = ''; $safetyDownload = ${verified ? 'true' : 'null'}; $safetyOperation = 'fixture';
            ${tool === 'key' ? "require 'src/frontend/components/backup/recovery_key_form.php';" : `
                $source = file_get_contents('src/frontend/components/backup/safety_copy.php');
                eval('?>'.substr($source, strpos($source, '<section')));
            `}
        }
    `;
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', php], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    return result.stdout;
}

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        fs.mkdirSync('.impeccable/review', {recursive: true});
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 1000});
            for (const [tool, verified, name] of [['key', false, 'key'], ['safety', false, 'safety-create'], ['safety', true, 'safety-ready']]) {
                await page.setContent('<body class="database-backup-page administrator-backup-page"><main class="main-content"><section class="dashboard-section backup-recovery">' + render(tool, verified) + '</section></main></body>');
                await page.addStyleTag({content: ':root {--primary:#2563eb;--muted:#667085;--text:#1f2937;--border:#e5e7eb;--card-bg:#fff;--surface-soft:#f8fafc;} body {font:16px system-ui;background:#f4f6f9;margin:0;} * {box-sizing:border-box;} .main-content {margin:0!important;padding:24px!important;} .dashboard-section {background:#fff;} .btn {border:0;text-decoration:none;} .btn-primary {background:var(--primary);color:white;} .btn-secondary {background:#e5e7eb;color:var(--text);}' + fs.readFileSync('src/frontend/assets/css/backup.css', 'utf8')});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${name}: no overflow at ${width}`);
                if (tool === 'key') {
                    const importer = page.getByRole('form', {name: 'Import key from another server'});
                    const downloader = page.getByRole('form', {name: 'Save this server’s key'});
                    assert.equal(await importer.getByLabel('Recovery key file (.key)').isVisible(), true);
                    assert.equal(await importer.evaluate(form => form.checkValidity()), false, 'Import requires a file and password');
                    await downloader.getByLabel('Current login password').fill('fixture-password');
                    assert.equal(await downloader.evaluate(form => form.checkValidity()), true, 'Download needs no import file');
                    assert.equal(await importer.locator('[name="csrf_token"]').count(), 1);
                    assert.equal(await downloader.locator('[name="csrf_token"]').count(), 1);
                    await downloader.getByLabel('Current login password').fill('');
                } else {
                    assert.equal(await page.locator(verified ? '[data-safety-download]' : '[data-safety-create]').isVisible(), true);
                }
                await page.screenshot({path: `.impeccable/review/recovery-${name}-${width}.png`, fullPage: true});
            }
        }
        console.log('Recovery tools: passed (1440px and 390px; import/download validation; safety states)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
