'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

const source = fs.readFileSync('src/frontend/components/administrator/database_backup.php', 'utf8');
assert(source.indexOf('class="dashboard-section backup-create"') < source.indexOf('id="backup-recovery"'));
assert(source.indexOf('id="backup-recovery"') < source.indexOf('id="backup-history"'));
// Render the production pagination calculation and history markup with isolated records.
function render(count, page = '1') {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
        require_once 'src/backend/bootstrap/app.php';
        $source = file_get_contents('src/frontend/components/administrator/database_backup.php');
        $history = [];
        for ($i = 1; $i <= (int)$argv[1]; $i++) $history[] = [
            'snapshot_at' => '2026-11-01 10:00:00', 'filename' => 'backup-' . $i . '.rmpkg',
            'format' => 'Encrypted Store package', 'notes' => '', 'file_size' => 1024,
            'status' => 'completed', 'requested_by' => 'Administrator'
        ];
        $_GET['history_page'] = $argv[2];
        $start = strpos($source, '$historyPerPage =');
        eval(substr($source, $start, strpos($source, '$restoreCapability', $start) - $start));
        $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        function format_display_datetime($value) { return $value; }
        $start = strpos($source, '<section class="dashboard-section backup-history"');
        eval('?>' . substr($source, $start, strpos($source, '</main>', $start) - $start));
    `, String(count), page], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    return result.stdout;
}

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        async function show(count, number) {
            await page.setContent('<body class="administrator-backup-page"><main class="main-content">' + render(count, number) + '</main></body>');
            await page.addStyleTag({path: 'src/frontend/assets/css/style.css'});
            await page.addStyleTag({path: 'src/frontend/assets/css/backup.css'});
            await page.addStyleTag({content: '.main-content {margin:0!important;padding:24px!important;width:100%;}'});
        }
        for (const [number, expected, first, label] of [['1', 10, 1, 'Showing 1–10 of 23'], ['2', 10, 11, 'Showing 11–20 of 23'], ['999', 3, 21, 'Showing 21–23 of 23'], ['-2', 10, 1, 'Showing 1–10 of 23'], ['invalid', 10, 1, 'Showing 1–10 of 23']]) {
            await show(23, number);
            assert.equal(await page.locator('tbody tr').count(), expected);
            assert.equal(await page.locator('tbody tr').first().locator('.backup-filename').textContent(), 'backup-' + first + '.rmpkg');
            assert.equal(await page.locator('.backup-pagination p').textContent(), label);
            assert.equal(await page.locator('.backup-record-count').textContent(), '23 records');
        }
        await show(23, '2');
        assert.equal(await page.getByRole('link', {name: 'Previous', exact: true}).getAttribute('href'), '?history_page=1#backup-history');
        assert.equal(await page.getByRole('link', {name: 'Next', exact: true}).getAttribute('href'), '?history_page=3#backup-history');
        const output = 'tmp/manual/backup-history-pagination';
        fs.mkdirSync(output, {recursive: true});
        for (const width of [1440, 375]) {
            await page.setViewportSize({width, height: 960});
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No page overflow at ' + width);
            await page.screenshot({path: path.join(output, 'history-' + width + '.png'), fullPage: true});
        }
        await show(1, '1');
        assert.equal(await page.locator('.backup-pagination [aria-disabled="true"]').count(), 2);
        await show(0, '999');
        assert.equal(await page.locator('.backup-empty').count(), 1);
        assert.equal(await page.locator('.backup-pagination').count(), 0);
        console.log('Backup history pagination: passed (order, 10 rows, boundaries, empty, desktop/mobile)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
