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
        for (const variant of ['opening', 'open', 'preview', 'admin', 'superadmin', 'locked', 'closed', 'movement-error', 'count-error']) {
            const setup = `
                require '../../../backend/app/Services/CashierShiftService.php';
                require_once '../../../backend/app/Services/CashDenominationCounter.php';
                function retailmind_theme_head() {}
                function app_url($path) { return '/'.$path; }
                function generate_csrf_token() { return 'fixture'; }
                function format_display_datetime($value) { return $value; }
                $variant=$argv[1]; $isCashier=!in_array($variant,['admin','superadmin']); $actorRole=$isCashier?'cashier':($variant==='admin'?'admin':'super_admin'); $_GET['tab']=$argv[2]??''; $_POST=[];
                if ($variant==='movement-error') $_POST=['action'=>'movement'];
                if ($variant==='count-error') $_POST=['action'=>'count'];
                $message=''; $error=''; $ownRegisterLocked=$variant==='locked'; $targetCashierId=1; $movementToken='fixture-movement';
                $cashiers=[['user_id'=>1,'full_name'=>'Saved Cashier']]; $closedSummary=null;
                $availableRegisters=[['register_id'=>1,'name'=>'Front Register']]; $unresolvedHeldSales=[];
                $shift=['shift_id'=>42,'register_name'=>'Front Register','full_name'=>'Saved Cashier',
                    'opened_at'=>'Oct 16, 2026 08:00 AM','closed_at'=>null,'closing_actor_name'=>null,
                    'intervention_reason'=>str_repeat('Long shift explanation ',10),'status'=>'open',
                    'expected_cash'=>null,'actual_cash'=>null,'cash_variance'=>null];
                $openShift=in_array($variant,['opening','closed'])?null:$shift; $recent=[$shift];
                if ($variant==='closed') $closedSummary=['actual_cash'=>1500,'expected_cash'=>1500,'cash_variance'=>0,'variance_review_required'=>false];
                $summary=['opening_cash'=>1000,'cash_sales'=>400,'cash_in'=>100,'calculated_expected_cash'=>1500,'sale_count'=>15,'refund_count'=>0];
                $countPreview=$variant==='preview'?['cash_variance'=>0,'counted_cash'=>1500,'expected_cash'=>1500,'variance_review_required'=>false]:null;
                $pendingCount=['denominations'=>[]];
                $movements=[['created_at'=>'Oct 16, 2026','movement_type'=>'cash_in','amount'=>100,
                    'reason'=>'float_addition','note'=>str_repeat('Saved movement note ',12),'full_name'=>'Saved Cashier']];
                eval('?><?php use App\\Services\\CashierShiftService; use App\\Services\\CashDenominationCounter; ?>'.stream_get_contents(STDIN));
            `;
            const render = (tab = '') => execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['-r', setup, variant, tab], {
                input: template, encoding: 'utf8', cwd: path.join(root, 'src/frontend/components/cashier')
            });
            const page = await browser.newPage();
            await page.route('http://shifts.test/**', route => {
                const asset = new URL(route.request().url()).pathname.match(/^\/assets\/(.*)/);
                if (asset) return route.fulfill({path: path.join(root, 'src/frontend/assets', asset[1])});
                return route.fulfill({contentType: 'text/html', body: render(new URL(route.request().url()).searchParams.get('tab') || '')});
            });
            await page.goto('http://shifts.test/');
            const tabs = page.locator('.shift-tablist [role="tab"]');
            const panelCount = variant === 'superadmin' ? 2 : variant === 'admin' ? 3 : 4;
            assert.equal(await tabs.count(), panelCount, `${variant}: only authorized tabs exist`);
            const expectedTab = ['preview','count-error'].includes(variant) ? 'close' : ['opening','closed'].includes(variant) ? 'recent' : ['admin','superadmin','locked'].includes(variant) ? 'current' : 'record';
            assert.equal(await page.locator('.shift-tab[aria-selected="true"]').getAttribute('aria-controls'), `shift-panel-${expectedTab}`, `${variant}: arrival shows relevant task`);
            for (const width of [1600, 1440, 1200, 1024, 768, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                const headerTop = await page.locator('.shift-overview').evaluate(el => el.getBoundingClientRect().top + scrollY);
                for (let index = 0; index < panelCount; index++) {
                    await tabs.nth(index).click();
                    assert.equal(await page.locator('.shift-tabpanel:visible').count(), 1, `${variant}: one task at ${width}`);
                    assert.equal(await page.locator('.shift-tab[tabindex="0"]').count(), 1, 'One tab in keyboard sequence');
                    assert.equal(await page.locator('.shift-overview').evaluate(el => el.getBoundingClientRect().top + scrollY), headerTop, 'Shared header stays in the same position');
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${variant}: page fits at ${width}`);
                    assert(await page.locator('.shift-tabpanel:visible').evaluate(el => el.scrollWidth <= el.clientWidth), `${variant}: panel fits at ${width}`);
                    assert(await tabs.nth(index).evaluate(el => el.getBoundingClientRect().height >= 44), 'Tabs have touch targets');
                }
                if (variant === 'open') {
                    await page.getByRole('tab', {name:'Close shift', exact:true}).click();
                    assert(await page.locator('.money-counter-stepper button').evaluateAll(buttons => buttons.every(button => {
                        const rect = button.getBoundingClientRect();
                        return rect.width >= 44 && rect.height >= 44;
                    })), `Cash counter touch targets are at least 44px at ${width}`);
                }
            }
            await tabs.first().focus();
            await page.keyboard.press('End');
            assert.equal(await tabs.last().getAttribute('aria-selected'), 'true', 'End opens final tab');
            await page.keyboard.press('ArrowRight');
            assert.equal(await tabs.first().getAttribute('aria-selected'), 'true', 'ArrowRight wraps and activates');
            await page.keyboard.press('ArrowLeft');
            assert.equal(await tabs.last().getAttribute('aria-selected'), 'true', 'ArrowLeft wraps and activates');
            await page.keyboard.press('Home');
            assert.equal(await tabs.first().getAttribute('aria-selected'), 'true', 'Home opens first tab');
            await page.getByRole('tab', {name:'Recent shifts', exact:true}).click();
            await page.reload();
            const reloadTab = ['preview','closed','movement-error','count-error'].includes(variant) ? expectedTab : 'recent';
            assert.equal(await page.locator('.shift-tab[aria-selected="true"]').getAttribute('aria-controls'), `shift-panel-${reloadTab}`, 'URL retains selection unless a submitted task takes priority');
            await page.getByRole('tab', {name:'Recent shifts', exact:true}).click();
            assert.equal(await page.locator('.shift-history td[data-label]').count(), 7);
            assert.equal(await page.getByRole('link', {name:'Drawer movement history across shifts'}).count(), ['admin','superadmin'].includes(variant) ? 0 : 1, 'Own movement history stays accessible even without an open Shift');
            if (variant === 'open') {
                assert.doesNotMatch(await page.locator('.shift-overview').innerText(), /Current shift/, 'Redundant Current shift text is removed from shift overview');
            }
            const intervention = page.locator('.shift-intervention');
            assert.equal(await intervention.locator('p').isVisible(), false, 'Long intervention reason starts collapsed');
            await intervention.locator('summary').click();
            assert.match(await intervention.locator('p').innerText(), /Long shift explanation/, 'Full intervention reason remains available');
            await intervention.locator('summary').click();
            if (variant === 'open') {
                await page.getByRole('tab', {name:'Record movement', exact:true}).click();
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
                await page.locator('#movement-source').fill('Owner cash box');
                await page.getByRole('tab', {name:'Close shift', exact:true}).click();
                await page.locator('#count-bill_100').fill('2');
                await page.getByRole('tab', {name:'Record movement', exact:true}).click();
                assert.equal(await page.locator('#movement-amount').inputValue(), '100.00', 'Movement draft survives switching');
                await page.getByRole('tab', {name:'Close shift', exact:true}).click();
                assert.equal(await page.locator('#count-bill_100').inputValue(), '2', 'Cash count survives switching');
                await page.getByRole('tab', {name:'Record movement', exact:true}).click();
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
            if (variant === 'open') {
                const context = await browser.newContext({javaScriptEnabled:false});
                const fallback = await context.newPage();
                await fallback.route('http://fallback.test/**', route => {
                    const url = new URL(route.request().url());
                    const asset = url.pathname.match(/^\/assets\/(.*)/);
                    return asset ? route.fulfill({path:path.join(root,'src/frontend/assets',asset[1])}) : route.fulfill({contentType:'text/html',body:render(url.searchParams.get('tab') || '')});
                });
                await fallback.goto('http://fallback.test/?tab=current');
                assert.equal(await fallback.locator('#shift-panel-current').isVisible(), true, 'Direct movement link works without JavaScript');
                await fallback.getByRole('tab', {name:'Close shift',exact:true}).click();
                assert.equal(await fallback.locator('#cash-drawer-counter').isVisible(), true, 'Native links navigate without JavaScript');
                await context.close();
            }
            if (variant === 'open') {
                await page.evaluate(() => {
                    document.querySelectorAll('.shift-movement-table tbody, .shift-history-table tbody').forEach(body => {
                        const row = body.firstElementChild.cloneNode(true);
                        body.replaceChildren(...Array.from({length: 23}, () => row.cloneNode(true)));
                    });
                });
                await page.addScriptTag({path: path.join(root, 'src/frontend/assets/js/ui.js')});
                await page.evaluate(() => RetailMindUI.initPageContent());
                for (const tab of ['current', 'recent']) {
                    await page.getByRole('tab', {name: tab === 'current' ? 'Current movements' : 'Recent shifts', exact: true}).click();
                    const panel = page.locator(`#shift-panel-${tab}`);
                    const rows = panel.locator('tbody tr:visible');
                    assert.equal(await panel.getByRole('combobox', {name: 'Rows per page'}).inputValue(), '10');
                    assert.equal(await rows.count(), 10, `${tab}: defaults to ten rows`);
                    await panel.getByRole('button', {name: 'Next page', exact: true}).click();
                    assert.equal(await rows.count(), 10, `${tab}: second page has ten rows`);
                    await panel.getByRole('button', {name: 'Next page', exact: true}).click();
                    assert.equal(await rows.count(), 3, `${tab}: final page retains remaining rows`);
                    assert(await panel.getByRole('button', {name: 'Next page', exact: true}).isDisabled());
                    await panel.getByRole('combobox', {name: 'Rows per page'}).selectOption('25');
                    assert.equal(await rows.count(), 23, `${tab}: changing page size resets pagination`);
                    for (const width of [1440, 390, 320]) {
                        await page.setViewportSize({width, height: 900});
                        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${tab}: pagination fits at ${width}`);
                    }
                }
            }
            await page.close();
        }
        console.log('Shift tabs: four tasks, keyboard, URL selection, drafts, role/state transitions and seven desktop/mobile widths passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
