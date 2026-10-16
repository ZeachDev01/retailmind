const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const source = fs.readFileSync(path.join(root, 'src/frontend/components/cashier/shifts.php'), 'utf8');
    const template = source.slice(source.indexOf('<!DOCTYPE html>')).replace(/<\?php include __DIR__ .*?\?>/, '');
    const browser = await chromium.launch();
    try {
        for (const variant of ['opening', 'open', 'preview', 'admin', 'locked']) {
            const setup = `
                require '../../../backend/app/Services/CashierShiftService.php';
                require_once '../../../backend/app/Services/CashDenominationCounter.php';
                function retailmind_theme_head() {}
                function app_url($path) { return '/'.$path; }
                function generate_csrf_token() { return 'fixture'; }
                function format_display_datetime($value) { return $value; }
                $variant=$argv[1]; $isCashier=$variant!=='admin'; $actorRole=$isCashier?'cashier':'admin';
                $message=''; $error=''; $ownRegisterLocked=$variant==='locked'; $targetCashierId=1;
                $cashiers=[['user_id'=>1,'full_name'=>'Saved Cashier']]; $closedSummary=null;
                $availableRegisters=[['register_id'=>1,'name'=>'Front Register']]; $unresolvedHeldSales=[];
                $shift=['shift_id'=>42,'register_name'=>'Front Register','full_name'=>'Saved Cashier',
                    'opened_at'=>'Oct 16, 2026 08:00 AM','closed_at'=>null,'closing_actor_name'=>null,
                    'intervention_reason'=>str_repeat('Long shift explanation ',10),'status'=>'open',
                    'expected_cash'=>null,'actual_cash'=>null,'cash_variance'=>null];
                $openShift=$variant==='opening'?null:$shift; $recent=[$shift];
                $summary=['opening_cash'=>1000,'cash_sales'=>500,'calculated_expected_cash'=>1500,'sale_count'=>15];
                $countPreview=$variant==='preview'?['cash_variance'=>0,'counted_cash'=>1500,'expected_cash'=>1500,'variance_review_required'=>false]:null;
                $pendingCount=['denominations'=>[]];
                $movements=[['created_at'=>'Oct 16, 2026','movement_type'=>'cash_in','amount'=>100,
                    'reason'=>'float_addition','note'=>str_repeat('Saved movement note ',12),'full_name'=>'Saved Cashier']];
                eval('?><?php use App\\Services\\CashierShiftService; use App\\Services\\CashDenominationCounter; ?>'.stream_get_contents(STDIN));
            `;
            const html = execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['-r', setup, variant], {
                input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/cashier')
            });
            const page = await browser.newPage();
            await page.route('http://shifts.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                if (asset) return route.fulfill({path: path.join(root, 'src/frontend/assets', asset[1])});
                return route.fulfill({contentType: 'text/html', body: html});
            });
            await page.goto('http://shifts.test/');
            for (const width of [1600, 1440, 1200, 1024, 768, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${variant}: page fits at ${width}`);
                assert(await page.locator('.shift-grid').evaluate(el => el.scrollWidth <= el.clientWidth), `${variant}: grid fits at ${width}`);
                assert(await page.locator('.shift-history .table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth), `${variant}: history fits at ${width}`);
                if (variant === 'open') {
                    const movement = await page.locator('.shift-movement').boundingBox();
                    const reconciliation = await page.locator('.shift-reconciliation').boundingBox();
                    assert(width > 1200 ? Math.abs(movement.y - reconciliation.y) < 2 : reconciliation.y >= movement.y + movement.height,
                        `Panels use the expected grid at ${width}`);
                }
            }
            assert.equal(await page.locator('.shift-history td[data-label]').count(), 11);
            await page.close();
        }
        console.log('Shift layouts: opening, open, reconciliation, admin, and locked states fit at seven desktop/mobile widths.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
