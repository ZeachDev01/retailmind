const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const pages = ['administrator/database_backup.php', 'system_administrator/backup_restore.php', 'system_administrator/identity_reconciliation.php'];
    const scripts = pages.map(name => {
        const file = path.join(frontend, 'components', name);
        const source = fs.readFileSync(file, 'utf8').match(/<script src="[^\n]*backup_review\.js[^\n]*<\/script>/)[0];
        const rendered = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
            function app_url($path) { return '/' . $path; }
            $escape = fn($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            eval('?>' . base64_decode('${Buffer.from(source.replaceAll('__DIR__', "'" + path.dirname(file).replaceAll('\\', '/') + "'")).toString('base64')}'));
        `], {encoding: 'utf8'});
        assert.equal(rendered.status, 0, rendered.stderr);
        return rendered.stdout;
    });
    const form = `<form method="post" action="/review" enctype="multipart/form-data" data-identity-form data-password-authorization>
        <input type="file" name="backup_file" required>
        <div class="form-group"><label for="test-password">Current login password</label><input id="test-password" type="password" name="restore_password" required></div>
        <button>Review backup</button></form>`;
    const reviewSource = fs.readFileSync(path.join(frontend, 'components/system_administrator/identity_reconciliation.php'), 'utf8');
    const approvalAssignment = reviewSource.match(/\$recordedApproval = [^\n]+/)[0];
    const approvalCard = reviewSource.match(/<\?php if \([^\n]+\): \?>\s*<section class="dashboard-section reconcile-evidence"[\s\S]*?<\?php endif; \?>/)[0];
    const approvalRendering = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
        $escape = fn($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $saved = ['approved_by' => 1, 'approved_at' => '2026-10-31 23:37:37 UTC',
            'package_id' => 'previous-package', 'active_administrators' => 1];
        $reconciler = new class($saved) {
            function __construct(private array $saved) {}
            function latestApproval() { return $this->saved; }
        };
        $results = [];
        foreach (['fresh', 'different-package', 'current-approval'] as $scenario) {
            $review = $scenario === 'fresh' ? null : ['ready' => true];
            $approval = $scenario === 'current-approval' ? array_replace($saved, ['package_id' => 'current-package']) : null;
            eval(base64_decode('${Buffer.from(approvalAssignment).toString('base64')}'));
            ob_start();
            eval('?>' . base64_decode('${Buffer.from(approvalCard).toString('base64')}'));
            $results[$scenario] = ob_get_clean();
        }
        echo json_encode($results);
    `], {encoding: 'utf8'});
    assert.equal(approvalRendering.status, 0, approvalRendering.stderr);
    const approvalResults = JSON.parse(approvalRendering.stdout);
    assert.equal(approvalResults.fresh.trim(), '', 'Fresh review must not show a saved approval');
    assert.equal(approvalResults['different-package'].trim(), '', 'Selecting another package must not show a saved approval');
    assert(approvalResults['current-approval'].includes('current-package'), 'Current approval shows its own package evidence');
    assert(!approvalResults['current-approval'].includes('previous-package'), 'Current approval must not show another package');
    const packageSection = reviewSource.match(/<section class="dashboard-section reconcile-package"[\s\S]*?<\/section>/)[0];
    const reviewed = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
        function app_url($path) { return '/' . $path; }
        $escape = fn($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $review = ['ready' => false];
        $draft = null;
        $editing = false;
        $package = ['manifest' => ['snapshot_at' => '2026-10-31 06:54:41']];
        $restorer = new class { function supportedUploadBytes() { return 12 * 1048576; } };
        eval('?>' . base64_decode('${Buffer.from(packageSection).toString('base64')}'));
    `], {encoding: 'utf8'});
    assert.equal(reviewed.status, 0, reviewed.stderr);
    const server = http.createServer(async (request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/sw.js' || url.pathname === '/assets/js/backup_review.js') {
            response.setHeader('Content-Type', 'text/javascript');
            response.end(fs.readFileSync(path.join(frontend, url.pathname)));
            return;
        }
        if (request.method === 'POST') for await (const chunk of request) { /* consume upload */ }
        response.setHeader('Content-Type', 'text/html');
        response.end(`<!doctype html><title>Backup review</title><main>
            ${request.method === 'POST' ? '<h1>Review identities</h1>' + form.replace(' data-password-authorization', '').replace(/<input[\s\S]*<button>/, reviewed.stdout + '<button>') : form}</main>
            ${scripts[Number(url.searchParams.get('page') || 0)]}`);
    });
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = 'http://127.0.0.1:' + server.address().port;
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.goto(origin);
        await page.evaluate(async () => {
            await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;
            const cache = await caches.open('retailmind-static-v2');
            await cache.put('/assets/js/backup_review.js', new Response('// Cached script without file retention', {
                headers: {'Content-Type': 'text/javascript'},
            }));
        });
        await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
        for (let index = 0; index < pages.length; index++) {
            await page.goto(origin + '/?page=' + index);
            await page.locator('[name="backup_file"]').setInputFiles({name: 'store.rmpkg', mimeType: 'application/octet-stream', buffer: Buffer.from('test package')});
            assert.equal(await page.locator('[name="restore_password"]').isVisible(), false);
            await page.getByRole('button', {name: 'Review backup', exact: true}).click();
            await page.locator('[name="restore_password"]').fill('test-password');
            await page.getByRole('dialog').getByRole('button', {name: 'Review backup', exact: true}).click();
            await page.getByRole('heading', {name: 'Review identities'}).waitFor();
            assert.equal(await page.locator('[name="backup_file"]').evaluate(input => input.files[0]?.name), 'store.rmpkg', pages[index] + ': no second file selection');
            assert.equal(await page.locator('[name="restore_password"]').inputValue(), 'test-password', pages[index] + ': no second password entry');
            assert.equal(await page.locator('[name="backup_file"]').isVisible(), false, 'Reviewed package must not look like another upload step');
            assert.equal(await page.locator('[name="restore_password"]').isVisible(), false, 'Reviewed password must not appear as another credential prompt');
            assert.equal(await page.locator('[data-selected-package-name]').innerText(), 'store.rmpkg');
            assert.equal(await page.locator('[data-identity-form]').evaluate(form => form.checkValidity()), true, 'Retained required fields still allow account review');
            assert.equal(await page.locator('[data-identity-form]').evaluate(async form => new FormData(form).get('backup_file').text()), 'test package', 'Hidden package remains part of the submission');
            await Promise.all([page.waitForResponse(response => response.url() === origin + '/review' && response.request().method() === 'POST'), page.getByRole('button', {name: 'Review backup'}).click()]);
            await page.locator('[data-reviewed-package]:not([hidden])').waitFor();
            assert.equal(await page.locator('[name="restore_password"]').inputValue(), 'test-password', 'Credentials survive subsequent account review');
            await page.getByRole('link', {name: 'Change backup'}).click();
            assert.equal(await page.locator('[name="backup_file"]').evaluate(input => input.files.length), 0, 'Changing backup starts a new selection');
            assert.equal(await page.locator('[name="restore_password"]').inputValue(), '', 'Changing backup clears previous credentials');
        }
        console.log('Backup review cache: passed for both backup screens and identity review');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
