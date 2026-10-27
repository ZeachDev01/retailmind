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
                    assert(await page.locator('.money-counter-stepper button').evaluateAll(buttons => buttons.every(button => {
                        const rect = button.getBoundingClientRect();
                        return rect.width >= 44 && rect.height >= 44;
                    })), `Cash counter touch targets are at least 44px at ${width}`);
                    const movement = await page.locator('.shift-movement').boundingBox();
                    const reconciliation = await page.locator('.shift-reconciliation').boundingBox();
                    assert(width > 1200 ? Math.abs(movement.y - reconciliation.y) < 2 : reconciliation.y >= movement.y + movement.height,
                        `Panels use the expected grid at ${width}`);
                }
            }
            assert.equal(await page.locator('.shift-history td[data-label]').count(), 7);
            if (variant === 'open') {
                assert.doesNotMatch(await page.locator('.shift-overview').innerText(), /Current shift/, 'Redundant Current shift text is removed from shift overview');
            }
            const intervention = page.locator('.shift-intervention');
            assert.equal(await intervention.locator('p').isVisible(), false, 'Long intervention reason starts collapsed');
            await intervention.locator('summary').click();
            assert.match(await intervention.locator('p').innerText(), /Long shift explanation/, 'Full intervention reason remains available');
            await intervention.locator('summary').click();
            if (variant === 'open') {
                const username = page.locator('#movement-approver');
                const password = page.locator('#movement-approval-password');
                assert.equal(await username.isVisible(), false, 'Authorization username stays off the main page');
                assert.equal(await password.isVisible(), false, 'Authorization password stays off the main page');
                await page.evaluate(() => {
                    window.movementPosts = [];
                    document.getElementById('movement-type').form.addEventListener('submit', event => {
                        if (!event.defaultPrevented) window.movementPosts.push(Object.fromEntries(new FormData(event.target)));
                        event.preventDefault();
                    });
                });
                await page.locator('#movement-amount').fill('100.00');
                await page.locator('#movement-type').selectOption('cash_out');
                for (const reason of ['petty_cash', 'supplier_payment']) {
                    await page.locator('#movement-reason').selectOption(reason);
                    await page.getByRole('button', {name: 'Record movement', exact: true}).click();
                    const dialog = page.getByRole('dialog', {name: 'Authorize drawer movement'});
                    assert(await dialog.isVisible(), `${reason}: record opens authorization`);
                    assert(await username.evaluate(el => el === document.activeElement), 'Username receives focus');
                    assert.equal(await page.evaluate(() => movementPosts.length), 0, 'Opening modal does not record a movement');
                    await page.getByRole('button', {name: 'Authorize and record'}).click();
                    assert.equal(await page.evaluate(() => movementPosts.length), 0, 'Empty credentials cannot submit');
                    await username.fill('fixture-admin');
                    await password.fill('fixture-password');
                    for (const width of [1440, 390, 320]) {
                        await page.setViewportSize({width, height: 900});
                        assert(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth && el.getBoundingClientRect().width <= innerWidth), `Authorization fits at ${width}`);
                    }
                    await page.getByRole('button', {name: 'Cancel', exact: true}).click();
                    await page.waitForFunction(() => document.getElementById('movement-approver').disabled);
                    assert.equal(await username.inputValue(), '', 'Cancel clears username');
                    assert.equal(await password.inputValue(), '', 'Cancel clears password');
                    await page.getByRole('button', {name: 'Record movement', exact: true}).click();
                    await username.fill('fixture-admin');
                    await password.fill('fixture-password');
                    await page.keyboard.press('Escape');
                    await page.waitForFunction(() => document.getElementById('movement-approver').disabled);
                    assert.equal(await password.inputValue(), '', 'Escape clears credentials');
                    assert.equal(await dialog.isVisible(), false, 'Escape closes authorization');
                }
                await page.getByRole('button', {name: 'Record movement', exact: true}).click();
                await username.fill('fixture-admin');
                await password.fill('fixture-password');
                await page.getByRole('button', {name: 'Authorize and record'}).click();
                assert.deepEqual(await page.evaluate(() => movementPosts.map(post => [post.action, post.amount, post.reason, post.approver_username, post.approver_password])),
                    [['movement', '100.00', 'supplier_payment', 'fixture-admin', 'fixture-password']], 'Approval posts original movement and credentials together');
                await page.getByRole('button', {name: 'Cancel', exact: true}).click();
                await page.waitForFunction(() => document.getElementById('movement-approver').disabled);
                await page.locator('#movement-type').selectOption('cash_in');
                await page.getByRole('button', {name: 'Record movement', exact: true}).click();
                assert.equal(await page.evaluate(() => movementPosts.length), 2, 'Float addition submits without approval');
                assert.equal(await page.evaluate(() => 'approver_password' in movementPosts[1]), false, 'Unapproved movement carries no credentials');
            }
            await page.close();
        }
        console.log('Shift layouts: opening, open, reconciliation, admin, and locked states fit at seven desktop/mobile widths.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
