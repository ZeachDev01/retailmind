const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');
const {randomBytes} = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
if (process.env.RUN_DB_TESTS !== '1') { console.log('Theme account browser: skipped (set RUN_DB_TESTS=1; temporary database required)'); process.exit(0); }
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const recovery = fs.mkdtempSync(path.join(os.tmpdir(),'rm-theme-recovery-'));
const fixtureEnv = {...process.env,BACKUP_STORAGE_PATH:recovery};
const screenshotOutput = process.env.THEME_BROWSER_OUTPUT || path.join(os.tmpdir(),'retailmind-theme-browser');
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding:'utf8',env:fixtureEnv});
    if (result.status !== 0) throw new Error(result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let browser, server;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0,'127.0.0.1',resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php,['-S','127.0.0.1:' + port,'-t','src/frontend','src/backend/tests/support/theme_router.php'],
            {env:{...fixtureEnv,RM_THEME_TEST_DATABASE:database},stdio:['ignore','ignore','pipe']});
        let errors=''; server.stderr.on('data',chunk=>{errors+=chunk;});
        for(let i=0;i<100;i++){ try { if((await fetch(origin)).ok) break; } catch{} await new Promise(resolve=>setTimeout(resolve,50)); if(i===99) throw new Error(errors); }
        browser = await chromium.launch({headless:true});
        const context = await browser.newContext({baseURL:origin,colorScheme:'dark'});
        const page = await context.newPage();
        const control = () => page.getByRole('combobox',{name:'Display theme'}).filter({visible:true}).last();
        const savedModes = {admin:'dark',super_admin:'light',inventory_manager:'system',cashier:'dark'};
        const contrast = async locator => {
            const ratio = await locator.first().evaluate(el => {
                const rgb = value => value.match(/[\d.]+/g).slice(0,3).map(Number);
                const luminance = values => values.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
                const fg=luminance(rgb(getComputedStyle(el).color));
                const backgrounds=[]; for(let node=el;node;node=node.parentElement) backgrounds.push(getComputedStyle(node).backgroundColor.match(/[\d.]+/g).map(Number));
                let backdrop=[255,255,255]; backgrounds.reverse().forEach(values=>{const alpha=values[3] ?? 1;backdrop=backdrop.map((v,i)=>values[i]*alpha+v*(1-alpha));});
                const bg=luminance(backdrop);
                return {ratio:(Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05),fg:getComputedStyle(el).color,bg:backdrop,element:el.outerHTML.slice(0,100)};
            });
            assert(ratio.ratio>=4.5,'Visible text contrast must reach 4.5:1; got '+JSON.stringify(ratio));
        };
        const login = async role => {
            await page.goto('/?login=1');
            // Wait for the modal's delayed startup focus before typing credentials.
            await page.waitForFunction(()=>document.activeElement.id==='landing-login-username');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Log in',exact:true}).click()]);
            await page.goto('/');
            await control().waitFor();
        };
        const select = async mode => {
            const response = page.waitForResponse(r=>r.url().endsWith('/auth/theme.php') && r.request().method()==='POST' && new URLSearchParams(r.request().postData()).get('mode') === mode);
            await control().selectOption(mode); assert.equal((await response).status(),200);
        };
        const gateControl = async () => {
            for (const width of [320,390,1280]) {
                await page.setViewportSize({width,height:900});
                const bounds=await control().boundingBox();
                assert(bounds.x>=0 && bounds.y>=0 && bounds.x+bounds.width<=width,'Gate theme control fits');
                assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)===el;}),'Gate theme control unobscured');
                await control().focus();
                assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Gate theme focus visible');
                await contrast(control());
            }
            assert.equal((await context.request.post('/components/auth/theme.php',{form:{mode:'dark',csrf_token:'bad'}})).status(),403,'Gate rejects invalid CSRF');
        };
        await page.goto('/?login=1'); await control().selectOption('light');
        for (const role of ['admin','super_admin','inventory_manager','cashier']) {
            console.log('Theme browser role: '+role);
            await login(role);
            assert.equal(await control().inputValue(),'system',role + ' default');
            await select('dark'); await page.reload(); assert.equal(await control().inputValue(),'dark');
            if (role === 'cashier') {
                // POS has native autofocus plus a delayed startup focus at 100 ms.
                await page.waitForFunction(()=>document.activeElement.id === 'sku-input');
                await page.waitForTimeout(150);
            }
            await control().focus(); await page.keyboard.press('Home');
            await page.waitForFunction(()=>document.querySelector('[data-theme-select]').value === 'light');
            assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Visible keyboard focus');
            await select('dark');
            await contrast(control()); await contrast(page.locator('h1,h2'));
            await page.locator('[data-command-open]').filter({visible:true}).first().click();
            const dialog=page.getByRole('dialog',{name:'Search RetailMind'}); await dialog.waitFor();
            await select('light'); assert(await dialog.isVisible()); await select('dark');
            await contrast(dialog.locator('input')); await page.keyboard.press('Escape');
            const token = await page.evaluate(()=>window.retailmindTheme.csrf);
            const endpoint='/components/auth/theme.php';
            assert.equal((await context.request.post(endpoint,{form:{mode:'blue',csrf_token:token}})).status(),422);
            assert.equal((await context.request.post(endpoint,{form:{mode:'light',csrf_token:'bad'}})).status(),403);
            if (role !== 'admin') {
                assert.equal((await context.request.post(endpoint,{form:{mode:'light',csrf_token:token,user_id:'1'}})).status(),200);
                const saved = JSON.parse(fixture('state')).users;
                assert.equal(saved[0].theme_preference,'dark','Client identity cannot change another user');
                assert.equal(saved.find(user=>user.user_id === ['admin','super_admin','inventory_manager','cashier'].indexOf(role)+1).theme_preference,'light');
                await page.reload(); await select('dark');
            }
            for (const width of [320,360,390,800]) {
                await page.setViewportSize({width,height:844});
                assert(await control().isVisible());
                const bounds = await control().boundingBox();
                assert(bounds.x >= 0 && bounds.y >= 0 && bounds.x + bounds.width <= width && bounds.y + bounds.height <= 844,'Mobile control stays inside viewport at '+width+'px');
                assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2) === el;}),'Mobile control remains unobscured at '+width+'px');
            }
            await page.setViewportSize({width:390,height:844});
            await control().focus();
            assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Mobile keyboard focus remains visible');
            assert(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth));
            fs.mkdirSync(screenshotOutput,{recursive:true});
            await page.screenshot({path:path.join(screenshotOutput,role+'-mobile.png'),fullPage:true});
            await select('system'); await page.emulateMedia({colorScheme:'light'});
            await page.waitForFunction(()=>getComputedStyle(document.body).backgroundColor !== 'rgb(11, 18, 32)');
            await page.emulateMedia({colorScheme:'dark'});
            await page.waitForFunction(()=>getComputedStyle(document.body).backgroundColor === 'rgb(11, 18, 32)');
            await select('light');
            assert.notEqual(await page.locator('body').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(11, 18, 32)','Explicit Light overrides dark device');
            await select('dark');
            await page.emulateMedia({colorScheme:'light'});
            assert.equal(await page.locator('body').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(11, 18, 32)');
            await page.setViewportSize({width:1280,height:900});
            await page.screenshot({path:path.join(screenshotOutput,role+'-desktop.png'),fullPage:true});
            if (role === 'admin' || role === 'super_admin') {
                const governanceState = JSON.parse(fixture('governance_state'));
                const routes = role === 'admin' ? [
                    'administrator/dashboard', 'administrator/store_settings', 'administrator/registers',
                    'administrator/shift_report', 'administrator/stock_issues', 'administrator/database_backup',
                    'system_administrator/fiscal_periods', 'user_manager/user_manager', 'system_administrator/audit_logs',
                ] : [
                    'super_administrator/dashboard', 'system_administrator/system_settings', 'user_manager/user_manager',
                    'system_administrator/audit_logs', 'system_administrator/backup_restore', 'system_administrator/database_updates',
                    'system_administrator/emergency_access', 'system_administrator/recovery_account',
                    'system_administrator/system_health', 'system_administrator/ml_settings',
                ];
                for (const route of routes) {
                    console.log('Theme governance screen: '+role+' '+route);
                    const response = await page.goto('/components/'+route+'.php');
                    assert.equal(response.status(),200,'Authorized screen renders '+route);
                    assert.equal(await control().inputValue(),'dark','Selected mode follows navigation '+route);
                    for (const mode of ['light','dark']) {
                        await select(mode);
                        for (const selector of ['main h1', 'main h2', 'main label', 'main input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"])', 'main select', 'main td', 'main .alert', 'main .health-status', 'main .health-detail', 'main .tag-warning', 'main .tag-success']) {
                            const visible = page.locator(selector).filter({visible:true});
                            if (await visible.count()) await contrast(visible);
                        }
                    }
                    const draft = page.locator('main input[type="text"], main input[type="number"], main textarea').filter({visible:true}).first();
                    if (await draft.count() && await draft.isEditable()) {
                        const value = await draft.getAttribute('type') === 'number' ? '17' : 'Unsubmitted governance work';
                        await draft.fill(value); await select('light');
                        assert.equal(await draft.inputValue(),value,'Appearance preserves form draft '+route);
                        await select('dark');
                    }
                    for (const width of [320,390,1280]) {
                        await page.setViewportSize({width,height:900});
                        const bounds=await control().boundingBox();
                        assert(bounds && bounds.x>=0 && bounds.y>=0 && bounds.x+bounds.width<=width,'Governance control fits '+route+' '+width);
                        assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)===el;}),'Governance control unobscured '+route);
                        await control().focus(); await contrast(control());
                        assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Governance focus visible '+route);
                    }
                    await page.reload(); assert.equal(await control().inputValue(),'dark','Governance mode survives reload '+route);
                    if (route === 'user_manager/user_manager') {
                        for (const [open, overlay, close, field] of [
                            ['#openUserModal','#userModalOverlay','#closeUserModal','#createUsernameInput'],
                            ['.open-user-drawer','#userDrawerOverlay','#closeUserDrawer','#drawerUsername'],
                        ]) {
                            await page.locator(open).first().click();
                            await page.locator(field).fill('UnsubmittedAccount');
                            await page.locator(overlay).evaluate(el=>Promise.all(el.getAnimations({subtree:true}).map(animation=>animation.finished)));
                            for (const width of [320,390,1280]) {
                                await page.setViewportSize({width,height:900});
                                for (const mode of ['light','dark']) {
                                    await select(mode);
                                    assert(await page.locator(overlay).isVisible(),'Open account dialog retained');
                                    assert.equal(await page.locator(field).inputValue(),'UnsubmittedAccount');
                                    await contrast(page.locator(field));
                                    await contrast(page.locator(overlay+' h3'));
                                    await contrast(page.locator(overlay+' label').first());
                                    const bounds=await control().boundingBox();
                                    assert(bounds.x>=0 && bounds.x+bounds.width<=width,'Dialog theme control fits '+width);
                                    assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)===el;}),'Dialog theme control unobscured');
                                }
                            }
                            await page.locator(close).click();
                        }
                    }
                }
                const forbidden = role === 'admin' ? 'system_administrator/system_settings' : 'administrator/store_settings';
                assert.equal((await context.request.get('/components/'+forbidden+'.php')).status(),403,'Appearance preserves role boundary');
                assert.deepEqual(JSON.parse(fixture('governance_state')),governanceState,'Appearance does not change governance or Store records');
            }
            if(role === 'super_admin') {
                await page.goto('/components/system_administrator/emergency_access.php');
                await contrast(page.locator('.emergency-action-note')); await contrast(page.locator('.emergency-expiry strong'));
                await select('light'); await select('dark'); assert(await page.locator('.emergency-expiry').isVisible(),'Appearance retains Emergency Access state');
                await page.goto('/components/auth/workspace.php');
                await page.locator('form').filter({has:page.locator('input[value="inventory_manager"]')}).getByRole('button',{name:'Open'}).click();
                await page.waitForURL('**/inventory_overview.php');
                assert.equal(await control().inputValue(),'dark','Workspace retains personal mode');
                await page.goto('/components/auth/workspace.php');
                await page.locator('form').filter({has:page.locator('input[value="super_admin"]')}).getByRole('button',{name:'Open'}).click();
                await page.waitForURL('**/super_administrator/dashboard.php');
            }
            await page.goto('/components/auth/user_info.php');
            const editable = page.locator('input[type="text"]').first();
            if(await editable.count()) { await editable.fill('Unsubmitted theme test'); await select('light'); assert.equal(await editable.inputValue(),'Unsubmitted theme test'); await select('dark'); }
            if (role === 'admin') {
                await page.goto('/components/auth/preferences.php');
                await page.locator('#low_stock_threshold').fill('17');
                await select('light'); assert.equal(await page.locator('#low_stock_threshold').inputValue(),'17'); await select('dark');
                await page.goto('/components/auth/change_password.php');
                await page.locator('#new_password').fill('Unsubmitted@2026');
                await select('light'); assert.equal(await page.locator('#new_password').inputValue(),'Unsubmitted@2026'); await select('dark');
            }
            if(role === 'cashier') {
                const cashierState = JSON.parse(fixture('cashier_state'));
                for (const route of ['dashboard','pos','findProduct','shifts','refunds?sale_id=1','stock_issues','history','history?type=refunds','history?type=movements','history?type=shifts','history?type=sales&id=1']) {
                    const [screen,query] = route.split('?');
                    console.log('Theme Cashier screen: '+route);
                    assert.equal((await page.goto('/components/cashier/'+screen+'.php'+(query ? '?'+query : ''))).status(),200);
                    assert.equal(await control().inputValue(),'dark');
                    for (const mode of ['light','dark']) {
                        await select(mode);
                        // The hero paints a gradient; this helper composites solid backgrounds only.
                        for (const selector of ['main h1','main h2:not(#cashier-hero-title)','main h3','main label','main td','main th','main input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"])','main select','main .stock-level-pill','main .shift-status-pill']) {
                            const visible=page.locator(selector).filter({visible:true});
                            if(await visible.count()) await contrast(visible);
                        }
                    }
                    for (const width of [320,390,1280]) {
                        await page.setViewportSize({width,height:900});
                        const bounds=await control().boundingBox();
                        assert(bounds && bounds.x>=0 && bounds.x+bounds.width<=width,'Cashier control fits '+route);
                        assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)===el;}),'Cashier control unobscured');
                        await control().focus(); await contrast(control());
                        assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'));
                    }
                    await page.reload(); assert.equal(await control().inputValue(),'dark');
                }
                for (const width of [390,1280]) {
                    await page.setViewportSize({width,height:900});
                    for (const [route,fields] of [
                        ['shifts', [['#movement-amount','17'],['#movement-note','Unsubmitted drawer work'],['#actual-cash','125']]],
                        ['refunds?sale_id=1', [['#return-quantity-1','1'],['#note','Unsubmitted Cash Refund']]],
                        ['stock_issues', [['#quantity','2'],['#explanation','Unsubmitted Stock Issue']]],
                        ['history', [['input[name="date_from"]','2026-10-01']]],
                    ]) {
                        const [screen,query]=route.split('?');
                        await page.goto('/components/cashier/'+screen+'.php'+(query ? '?'+query : ''));
                        if(screen==='stock_issues') {
                            await page.locator('#barcode-input').fill('THEME-BARCODE'); await page.locator('#barcode-input').press('Enter');
                            await page.locator('#product-results').getByRole('button',{name:'Select',exact:true}).click();
                            await page.waitForFunction(()=>document.getElementById('product_id').value==='1');
                        }
                        for(const [selector,value] of fields) await page.locator(selector).fill(value);
                        await select('light'); await select('system'); await page.emulateMedia({colorScheme:'light'});
                        await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
                        await page.emulateMedia({colorScheme:'dark'});
                        await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
                        for(const [selector,value] of fields) assert.equal(await page.locator(selector).inputValue(),value,'Cashier draft retained');
                        if(screen==='stock_issues') assert.equal(await page.locator('#product_id').inputValue(),'1');
                        await select('dark');
                    }
                }
                await page.goto('/components/cashier/dashboard.php'); await contrast(page.locator('.stock-level-pill'));
                await page.goto('/components/cashier/pos.php');
                await contrast(page.locator('.count-badge').filter({visible:true}));
                await contrast(page.getByRole('button',{name:'Add to Cart',exact:true}).first());
                await page.locator('#sku-input').fill('THEME-ITEM'); await page.locator('#sku-input').press('Enter');
                await page.locator('#cart-body').getByText('Theme Test Item',{exact:true}).waitFor();
                const cartText = await page.locator('#cart-body').innerText();
                await select('light'); assert.equal(await page.locator('#cart-body').innerText(),cartText); await select('dark');
                await page.getByRole('spinbutton',{name:'Theme Test Item quantity',exact:true}).fill('2');
                await page.getByRole('spinbutton',{name:'Theme Test Item quantity',exact:true}).press('Tab');
                await page.locator('#hold-list').getByText('THEME-HELD',{exact:true}).waitFor();
                for (const width of [320,390,1280]) {
                    await page.setViewportSize({width,height:900});
                    await page.locator('#checkout-button').click(); await page.locator('#review-cash').fill('100');
                    const reviewed=await page.locator('#checkout-summary').innerText();
                    await select('light'); await select('system'); await page.emulateMedia({colorScheme:'light'});
                    await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
                    assert.equal(await page.locator('#review-cash').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
                    await page.emulateMedia({colorScheme:'dark'}); await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
                    assert.equal(await page.locator('#review-cash').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(16, 28, 46)');
                    assert.equal(await page.locator('#review-cash').inputValue(),'100');
                    assert.equal(await page.locator('#checkout-summary').innerText(),reviewed);
                    await contrast(page.locator('#review-cash')); await gateControl();
                    await page.getByRole('button',{name:'Review cart',exact:true}).click(); await select('dark');
                    await page.locator('#void-sale-btn').click(); await page.locator('#discard-note').fill('Unsubmitted cart discard reason');
                    await select('light'); await select('dark'); await gateControl();
                    assert.equal(await page.locator('#discard-note').inputValue(),'Unsubmitted cart discard reason');
                    await page.getByRole('button',{name:'Keep working',exact:true}).click();
                    await page.locator('#hold-list').getByRole('button',{name:'Discard',exact:true}).click();
                    await page.locator('#discard-note').fill('Unsubmitted discard reason');
                    await select('light'); await select('dark'); await gateControl();
                    assert.equal(await page.locator('#discard-note').inputValue(),'Unsubmitted discard reason');
                    await page.getByRole('button',{name:'Keep working',exact:true}).click();
                    assert.equal(await page.getByRole('spinbutton',{name:'Theme Test Item quantity',exact:true}).inputValue(),'2');
                }
                await page.locator('#sku-input').fill('UNKNOWN-THEME-CODE'); await page.locator('#sku-input').press('Enter');
                await page.locator('#cart-message').filter({hasText:'No product found'}).waitFor();
                for (const mode of ['light','dark']) {
                    await select(mode); await contrast(page.locator('#cart-message'));
                    assert.equal(await page.getByRole('spinbutton',{name:'Theme Test Item quantity',exact:true}).inputValue(),'2');
                }
                await page.locator('#checkout-button').click(); await page.locator('#review-cash').fill('100');
                await page.route('**/auth/theme.php',route=>route.fulfill({status:503,contentType:'application/json',body:'{"success":false}'}));
                await control().selectOption('light');
                const saveAlert=page.getByRole('alert').filter({hasText:'display theme was not saved'}); await saveAlert.waitFor();
                assert(await saveAlert.evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Save warning visible above open checkout');
                await contrast(saveAlert); assert.equal(await page.locator('#review-cash').inputValue(),'100');
                await page.unroute('**/auth/theme.php'); await select('dark');
                await page.getByRole('button',{name:'Review cart',exact:true}).click();
                assert.equal((await context.request.get('/components/administrator/store_settings.php')).status(),403);
                assert.equal((await context.request.get('/components/inventory_management/products.php')).status(),403);
                assert.deepEqual(JSON.parse(fixture('cashier_state')),cashierState,'Theme changes preserve Held Sale, shift, sale/refund, stock and drawer records');
                await page.goto('/components/invoice/sales.php?tab=transactions&sale_id=1');
                await page.locator('.receipt-print-area').waitFor();
                assert.equal(await page.locator('.receipt-print-area').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(21, 34, 56)');
                {
                    const details=await page.locator('.receipt-print-area').textContent();
                    await page.emulateMedia({media:'print'});
                    assert.equal(await page.locator('.receipt-print-area').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
                    assert.equal(await control().count(),0);
                    assert.equal(await page.locator('.receipt-print-area').textContent(),details);
                    await page.emulateMedia({media:'screen'});
                }
            }
            if(role === 'inventory_manager') {
                const inventoryState = JSON.parse(fixture('inventory_state'));
                const inventoryRoutes = [
                    'inventory_management/inventory_overview','inventory_management/inventory_insights',
                    'inventory_management/products','inventory_management/csv_import',
                    'inventory_management/reorder_planner','inventory_management/replenishment_requests',
                    'inventory_management/stock_issues','inventory_management/suppliers','inventory_management/inventory_counts',
                    'report/stock_receiving','invoice/purchase_orders','report/predictions','report/forecast_exceptions',
                    'report/data_readiness','report/forecast_analytics',
                ];
                for (const route of inventoryRoutes) {
                    console.log('Theme inventory screen: '+route);
                    assert.equal((await page.goto('/components/'+route+'.php')).status(),200);
                    assert.equal(await control().inputValue(),'dark','Inventory navigation retains mode');
                    for (const mode of ['light','dark']) {
                        await select(mode);
                        for (const selector of ['main h1','main h3','main label','main td','main th','main input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"])','main select','main .tag','main .message']) {
                            const visible=page.locator(selector).filter({visible:true});
                            if (await visible.count()) await contrast(visible);
                        }
                    }
                    for (const width of [320,390,1280]) {
                        await page.setViewportSize({width,height:900});
                        const bounds=await control().boundingBox();
                        assert(bounds && bounds.x>=0 && bounds.x+bounds.width<=width,'Inventory control fits '+route);
                        assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)===el;}),'Inventory control unobscured');
                        await control().focus(); await contrast(control());
                        assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'));
                    }
                    await page.reload(); assert.equal(await control().inputValue(),'dark');
                }
                await page.goto('/components/inventory_management/products.php');
                await page.locator('.manage-product').first().click();
                await select('light'); await select('dark');
                await contrast(page.locator('#product-drawer-title'));
                await page.locator('#product-drawer [data-close-drawer]').click();
                await page.locator('#product-add-trigger').click(); await contrast(page.locator('#add-product-btn'));
                await page.locator('#add-product-btn').click();
                assert.equal(await control().inputValue(),'dark','New dialog control reflects current mode');
                await page.locator('#add-product-modal input[name="product_name"]').fill('Unsubmitted product');
                for (const width of [320,390,1280]) {
                    await page.setViewportSize({width,height:900});
                    for (const mode of ['light','dark','system']) {
                        await select(mode); await page.emulateMedia({colorScheme:'light'});
                        if (mode === 'system') {
                            await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
                            assert.equal(await page.locator('#add-product-modal input[name="product_name"]').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
                        }
                        await page.emulateMedia({colorScheme:'dark'});
                        if (mode === 'system') {
                            await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
                            assert.equal(await page.locator('#add-product-modal input[name="product_name"]').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(16, 28, 46)');
                        }
                        assert(await page.locator('#add-product-modal').isVisible());
                        assert.equal(await page.locator('#add-product-modal input[name="product_name"]').inputValue(),'Unsubmitted product');
                        await contrast(page.locator('#add-product-modal h2'));
                        const bounds=await control().boundingBox();
                        assert(bounds && bounds.x>=0 && bounds.x+bounds.width<=width,'Inventory dialog selector fits');
                        assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)===el;}),'Inventory dialog selector unobscured');
                    }
                }
                await page.locator('#add-product-modal [data-close-modal]').first().click();
                await page.setViewportSize({width:1280,height:900}); await select('dark');
                await page.goto('/components/invoice/purchase_orders.php');
                await page.locator('form[data-confirm] button').click();
                await page.getByRole('heading',{name:'Cancel purchase order'}).waitFor();
                await select('light'); await select('dark');
                await contrast(page.getByRole('heading',{name:'Cancel purchase order'}));
                await page.locator('.rm-cancel').click();
                await page.locator('textarea[name="notes"]').fill('Unsubmitted purchasing work');
                await page.locator('select[name="supplier_id"]').selectOption('1');
                await page.locator('input[name="request_ids[]"]').check();
                await select('light'); await select('system'); await page.emulateMedia({colorScheme:'light'});
                assert.equal(await page.locator('textarea[name="notes"]').inputValue(),'Unsubmitted purchasing work');
                assert.equal(await page.locator('select[name="supplier_id"]').inputValue(),'1');
                assert(await page.locator('input[name="request_ids[]"]').isChecked());
                await page.emulateMedia({colorScheme:'dark'}); await select('dark');
                await page.goto('/components/inventory_management/stock_issues.php');
                await page.locator('textarea[name="review_notes"]').fill('Unsubmitted Stock Issue decision');
                await select('light'); await select('dark');
                assert.equal(await page.locator('textarea[name="review_notes"]').inputValue(),'Unsubmitted Stock Issue decision');
                assert.equal((await context.request.get('/components/administrator/store_settings.php')).status(),403);
                assert.equal((await context.request.get('/components/system_administrator/ml_settings.php')).status(),403);
                assert.equal((await context.request.get('/components/inventory_management/promotions.php')).status(),403);
                assert.deepEqual(JSON.parse(fixture('inventory_state')),inventoryState,'Appearance preserves inventory, purchasing, Stock Issues and forecasts');

                for (const width of [390,1280]) {
                    await page.setViewportSize({width,height:900});
                    for(const route of ['/components/inventory_management/inventory_counts.php','/components/report/stock_receiving.php']) {
                        await page.goto(route); await page.locator('#product_code_scan').fill('THEME-BARCODE'); await page.locator('#product_code_scan').press('Enter');
                        await page.waitForFunction(()=>document.getElementById('product_id').value==='1');
                        const field=route.includes('inventory_counts') ? '#physical_quantity' : '#received_qty';
                        const notes=page.locator(route.includes('inventory_counts') ? '#discrepancy_reason' : '#notes');
                        await page.locator(field).fill('7'); await notes.fill('Unsubmitted inventory work');
                        await select('light'); assert.equal(await page.locator(field).inputValue(),'7'); assert.equal(await notes.inputValue(),'Unsubmitted inventory work');
                        await select('dark'); assert.equal(await page.locator('#product_id').inputValue(),'1'); await contrast(page.locator(field));
                        await select('system'); await page.emulateMedia({colorScheme:'light'});
                        await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
                        await page.emulateMedia({colorScheme:'dark'});
                        await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
                        assert.equal(await page.locator('#product_id').inputValue(),'1');
                        assert.equal(await page.locator(field).inputValue(),'7');
                        assert.equal(await notes.inputValue(),'Unsubmitted inventory work');
                        await select('dark');
                    }
                }
                await page.goto('/components/inventory_management/suppliers.php'); await contrast(page.locator('.count-badge')); await contrast(page.locator('.status-badge--active'));
                for(const route of ['/components/invoice/purchase_orders.php','/components/inventory_management/print_barcodes.php?product_id=1']) {
                    await page.goto(route); assert.equal(await control().inputValue(),'dark');
                    if(route.includes('print_barcodes')) assert.equal(await page.locator('.barcode-svg').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)','Barcode ink retains readable inset');
                    await page.emulateMedia({media:'print'});
                    assert.equal(await page.locator('body').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
                    assert.equal(await control().count(),0); await page.emulateMedia({media:'screen'});
                }
                await page.goto('/components/report/forecast_analytics.php');
                const canvas=page.locator('#featureChart');
                const pixels=()=>canvas.evaluate(el=>Array.from(el.getContext('2d').getImageData(0,0,45,45).data));
                const darkPixels=await pixels(); assert(darkPixels.some((v,i)=>i%4===3 && v>0),'Chart visibly draws its labels/axes');
                await select('light'); assert.notDeepEqual(await pixels(),darkPixels,'Theme switch redraws visible chart pixels');
                await select('system'); await page.emulateMedia({colorScheme:'light'});
                await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
                const systemLightPixels=await pixels();
                await page.emulateMedia({colorScheme:'dark'});
                await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
                assert.notDeepEqual(await pixels(),systemLightPixels,'Live System change redraws chart pixels'); await select('dark');
                await page.goto('/components/report/predictions.php?variant=A'); await contrast(page.locator('.fp-prototype-note'));
            }
            if(role === 'admin') {
                await page.goto('/components/report/report_generation.php');
                await page.emulateMedia({media:'print'});
                assert.equal(await page.locator('body').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
                assert.equal(await control().count(),0); await page.emulateMedia({media:'screen'});
            }
            await page.route('**/auth/theme.php',route=>route.fulfill({status:503,contentType:'application/json',body:'{"success":false}'}));
            await control().selectOption('light');
            await page.getByRole('alert').filter({hasText:'display theme was not saved'}).waitFor();
            await page.unroute('**/auth/theme.php');
            await select('dark');
            await select(savedModes[role]);
            await page.goto('/components/auth/logout.php');
            assert.equal(await control().inputValue(),'light','Separate login preference restored');
        }
        const fresh = await browser.newContext({baseURL:origin});
        const other = await fresh.newPage();
        for (const role of ['admin','super_admin','inventory_manager','cashier']) {
            await other.goto('/?login=1');
            await other.waitForFunction(()=>document.activeElement.id==='landing-login-username');
            assert.equal(await other.getByRole('combobox',{name:'Display theme'}).filter({visible:true}).inputValue(),'system','Fresh browser has no local login preference');
            await other.locator('#landing-login-username').fill('theme_'+role); await other.locator('#landing-login-password').fill(data.password);
            await Promise.all([other.waitForNavigation({waitUntil:'domcontentloaded'}),other.locator('#landing-login-password').press('Enter')]);
            await other.goto('/');
            assert.equal(await other.getByRole('combobox',{name:'Display theme'}).filter({visible:true}).inputValue(),savedModes[role],'Fresh browser restores '+role+' account');
            await other.goto('/components/auth/logout.php');
        }
        const blocked = await browser.newContext({baseURL:origin,colorScheme:'dark'});
        await blocked.addInitScript(()=>{
            for (const storage of ['localStorage','sessionStorage']) Object.defineProperty(window,storage,{get(){throw new DOMException('blocked','SecurityError');}});
        });
        const privatePage=await blocked.newPage();
        await privatePage.goto('/?login=1');
        await privatePage.waitForFunction(()=>document.activeElement.id==='landing-login-username');
        await privatePage.getByRole('combobox',{name:'Display theme'}).selectOption('light');
        await privatePage.locator('#landing-login-username').fill('theme_inventory_manager');
        await privatePage.locator('#landing-login-password').fill(data.password);
        await Promise.all([privatePage.waitForNavigation({waitUntil:'domcontentloaded'}),privatePage.locator('#landing-login-password').press('Enter')]);
        await privatePage.goto('/');
        const privateControl=privatePage.getByRole('combobox',{name:'Display theme'}).filter({visible:true});
        assert.equal(await privateControl.inputValue(),'system','Blocked storage sign-in restores account');
        assert.equal(await privatePage.locator('body').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(11, 18, 32)','Blocked storage System follows device');
        const privateSave=privatePage.waitForResponse(r=>r.url().endsWith('/auth/theme.php') && r.request().method()==='POST');
        await privateControl.selectOption('light'); assert.equal((await privateSave).status(),200);
        await privatePage.reload(); assert.equal(await privateControl.inputValue(),'light','Blocked storage account change survives reload');
        await privatePage.goto('/components/auth/logout.php');
        assert.equal(await privateControl.inputValue(),'system','Blocked storage logout restores System');
        assert.equal((await context.request.post('/components/auth/theme.php',{form:{mode:'dark'}})).status(),401);
        fixture('gates');
        await login('admin');
        await page.waitForURL('**/change_password.php');
        await gateControl();
        await page.locator('#new_password').fill('Unsubmitted@2026'); await select('light');
        assert.equal(await page.locator('#new_password').inputValue(),'Unsubmitted@2026');
        await page.goto('/components/administrator/dashboard.php'); await page.waitForURL('**/change_password.php');
        assert.equal(JSON.parse(fixture('state')).users[0].must_change_password,1,'Appearance cannot complete password gate');
        await page.goto('/components/auth/logout.php');
        await login('cashier'); await page.getByRole('heading',{name:'Register locked'}).waitFor();
        await gateControl();
        await page.locator('#pos-unlock-password').fill('not-submitted'); await select('light');
        assert.equal(await page.locator('#pos-unlock-password').inputValue(),'not-submitted');
        await page.reload(); await page.getByRole('heading',{name:'Register locked'}).waitFor();
        const finalState=JSON.parse(fixture('state'));
        assert.equal(finalState.shift.status,'open'); assert(finalState.shift.locked_at); assert.equal(finalState.sales,1); assert.equal(finalState.stock,10);
        console.log('Theme account browser: passed (fresh/upgrade parity, all roles/mobile, keyboard/focus/contrast, device, persistence/isolation, protected writes/failures, dialogs/charts, scans/forms/cart, gates, receipt/report paper)');
    } finally {
        if(browser) await browser.close(); if(server) server.kill(); fixture('cleanup'); fs.rmSync(recovery,{recursive:true,force:true});
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
