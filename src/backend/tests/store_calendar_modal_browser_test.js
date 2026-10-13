const assert = require('node:assert/strict');
const fs = require('node:fs');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

// Render the actual page with in-memory calendar data; no Store DB or login changes.
const rendered = spawnSync(process.env.PHP_BINARY || 'php', [], {encoding: 'utf8', input: `<?php
require 'src/backend/app/Store/StoreCalendar.php';
require 'src/backend/app/Services/PhilippineTime.php';
date_default_timezone_set('Asia/Manila');
function require_role($roles) {}
function retailmind_theme_head() {}
function theme_url($path) { return $path; }
function csrf_field() { return '<input type="hidden" name="csrf_token" value="fixture-token">'; }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['year' => '2026', 'month' => '10'];
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE store_calendar (calendar_id INTEGER PRIMARY KEY, calendar_date TEXT, status TEXT, recorded_at TEXT)');
$pdo->exec("INSERT INTO store_calendar VALUES (1,'2026-10-06','open','2026-01-01'),(2,'2026-10-07','closed','2026-01-01'),(3,'2026-10-08','holiday','2026-01-01'),(4,'2026-10-09','exceptional-closure','2026-01-01')");
$source = file_get_contents('src/frontend/components/administrator/store_calendar.php');
$source = str_replace("require_once __DIR__ . '/../../../backend/includes/auth.php';", '', $source);
$source = str_replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '<aside class="sidebar"></aside>', $source);
eval('?>' . $source);
`});
assert.equal(rendered.status, 0, rendered.stderr);
const css = fs.readFileSync('src/frontend/assets/css/style.css', 'utf8') + fs.readFileSync('src/frontend/assets/css/theme.css', 'utf8');
const ui = fs.readFileSync('src/frontend/assets/js/ui.js', 'utf8');

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage({reducedMotion: 'reduce'});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const width of [375, 1440]) {
            await page.setViewportSize({width, height: 900});
            await page.setContent(rendered.stdout.replace('</head>', `<style>${css}</style><script>${ui}</script></head>`));
            const editor = page.locator('#calendar-editor');
            assert.equal(await editor.isVisible(), false);
            for (const [day, status] of [['06', 'open'], ['07', 'closed'], ['08', 'holiday'], ['09', 'exceptional_closure'], ['10', 'open']]) {
                const cell = page.locator(`[data-date="2026-10-${day}"]`);
                await cell.click();
                await page.waitForFunction(() => document.querySelector('#calendar-editor').classList.contains('open'));
                assert.equal(await page.locator('#calendar_date').inputValue(), `2026-10-${day}`);
                assert.equal(await page.locator('#status').inputValue(), status);
                assert.equal(await page.locator('#through_date').inputValue(), '');
                await page.locator('#through_date').fill('2026-10-15');
                assert.match(await page.locator('#range-preview-hint').textContent(), /inclusive days/);
                await page.keyboard.press('Escape');
                assert.equal(await editor.getAttribute('aria-hidden'), 'true');
                assert.equal(await cell.evaluate(el => el === document.activeElement), true);
            }
            await page.locator('[data-date="2026-10-08"]').focus();
            await page.keyboard.press('Enter');
            await page.waitForFunction(() => document.querySelector('#calendar-editor').contains(document.activeElement));
            await page.locator('#save-status-btn').focus();
            await page.keyboard.press('Tab');
            assert.equal(await editor.evaluate(el => el.contains(document.activeElement)), true);
            const box = await page.locator('.rm-modal').boundingBox();
            assert.ok(box.x >= 0 && box.x + box.width <= width + 1 && box.y >= 0 && box.y + box.height <= 901);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await page.locator('[data-close-modal]').click();
            await page.locator('#record-date-btn').click();
            await page.locator('#status').selectOption('closed');
            assert.deepEqual(await page.locator('#calendar-form').evaluate(form => {
                const data = new FormData(form);
                return [form.method, data.get('calendar_date'), data.get('status'), data.get('csrf_token')];
            }), ['post', '2026-10-08', 'closed', 'fixture-token']);
            await page.locator('.rm-close').click();
            for (const theme of ['light', 'dark']) {
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                const colors = await page.locator('.calendar-legend .calendar-status-badge').evaluateAll(nodes => nodes.map(el => getComputedStyle(el).backgroundColor));
                assert.equal(new Set(colors.slice(0, 4)).size, 4, `${theme}: recorded statuses need distinct colors`);
                await page.screenshot({path: `.scratch/calendar-${theme}-${width}.png`, fullPage: true, animations: 'disabled'});
                await page.locator('[data-date="2026-10-08"]').click();
                await page.waitForFunction(() => document.querySelector('#calendar-editor').contains(document.activeElement));
                await page.waitForFunction(() => getComputedStyle(document.querySelector('#calendar-editor')).opacity === '1');
                await page.screenshot({path: `.scratch/calendar-modal-${theme}-${width}.png`, animations: 'disabled'});
                await page.keyboard.press('Escape');
            }
        }
        assert.deepEqual(errors, []);
        console.log('Calendar modal browser: passed (mobile/desktop, status prefill, range reset, keyboard, focus return, colors, form payload).');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
