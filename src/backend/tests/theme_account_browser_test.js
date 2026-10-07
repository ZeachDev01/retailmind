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
const receiptsOnly = process.env.THEME_RECEIPTS_ONLY === '1';
const reportsOnly = process.env.THEME_REPORTS_ONLY === '1';
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
        const control = () => page.locator('[data-theme-current], [data-theme-mode][aria-pressed="true"]').filter({visible:true}).last();
        const option = async mode => {
            const choice = page.locator('[data-theme-mode="'+mode+'"]').filter({visible:true}).last();
            if (!await choice.count()) await page.locator('.theme-trigger').filter({visible:true}).last().click();
            return choice;
        };
        const savedModes = {admin:'dark',super_admin:'light',inventory_manager:'system',cashier:'dark'};
        const contrast = async locator => {
            await locator.first().evaluate(el=>Promise.all(el.getAnimations({subtree:true}).map(animation=>animation.finished)));
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
            const choice = await option(mode);
            const response = page.waitForResponse(r=>r.url().endsWith('/auth/theme.php') && r.request().method()==='POST' && new URLSearchParams(r.request().postData()).get('mode') === mode);
            const [saved] = await Promise.all([response, choice.click()]);
            assert.equal(saved.status(),200);
        };
        const gateControl = async () => {
            for (const width of [320,390,1280]) {
                await page.setViewportSize({width,height:900});
                const bounds=await control().boundingBox();
                assert(bounds.x>=0 && bounds.y>=0 && bounds.x+bounds.width<=width,'Gate theme control fits');
                assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Gate theme control unobscured');
                await page.keyboard.press('Tab'); await control().focus();
                assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Gate theme focus visible');
                await contrast(control());
            }
            assert.equal((await context.request.post('/components/auth/theme.php',{form:{mode:'dark',csrf_token:'bad'}})).status(),403,'Gate rejects invalid CSRF');
        };
        const reportAcceptance = async role => {
            fixture('reports');
            const records = JSON.parse(fixture('inventory_state'));
            const transactions = JSON.parse(fixture('cashier_state'));
            const routes = role === 'admin' ? [
                '/components/report/report_generation.php?report=inventory_valuation',
                '/components/report/report_generation.php?report=sales_product&from=2020-01-01&to=2030-01-01',
            ] : [
                '/components/invoice/purchase_orders.php',
                '/components/inventory_management/print_barcodes.php?product_id=1&quantity=120',
                '/components/report/data_readiness.php', '/components/report/forecast_exceptions.php',
                '/components/report/predictions.php', '/components/report/predictions.php?variant=A',
                '/components/report/predictions.php?variant=B', '/components/report/predictions.php?variant=C',
                '/components/report/forecast_analytics.php',
            ];
            await page.addInitScript(()=>{window.printCalls=0;window.print=()=>window.printCalls++;});
            for (const route of routes) {
                console.log('Theme report screen/paper: '+role+' '+route);
                assert.equal((await page.goto(route)).status(),200);
                const content = page.locator('.main-content,.sheet').first();
                const rendered = await content.innerText();
                assert.equal(await page.evaluate(()=>window.printCalls),0,'Navigation never prints reports');
                for (const mode of ['light','dark']) {
                    await select(mode);
                    for (const selector of ['h1','h2','h3','label','td','th','.value','.label','.tag-warning','.tag-success','input:not([type="hidden"]):not([type="checkbox"])','select','textarea']) {
                        const visible=page.locator('.main-content '+selector+',.barcode-page '+selector).filter({visible:true});
                        if(await visible.count()) await contrast(visible);
                    }
                }
                const draft=page.locator('#from,#quantity').first();
                if(await draft.count()) await draft.fill(route.includes('print_barcodes')?'121':'2026-01-02');
                for (const width of [320,390,1280]) {
                    await page.setViewportSize({width,height:900});
                    const bounds=await control().boundingBox();
                    assert(bounds && bounds.x>=0 && bounds.x+bounds.width<=width,'Report control fits '+route+' '+width);
                    assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Report control unobscured');
                    await page.keyboard.press('Tab'); await control().focus();
                    assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Report focus visible');
                }
                await select('system'); await page.emulateMedia({colorScheme:'light'});
                await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
                await page.emulateMedia({colorScheme:'dark'});
                await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
                await select('light'); assert.equal(await page.locator('html').getAttribute('data-theme'),'light','Explicit report mode overrides device');
                await select('dark');
                if(await draft.count()) assert.equal(await draft.inputValue(),route.includes('print_barcodes')?'121':'2026-01-02','Mode/device changes preserve report draft');
                if(route.includes('inventory_valuation')) assert.equal(await page.locator('tbody tr').count(),66,'Representative report retains all inventory rows');
                if(route.includes('sales_product')) assert((await page.locator('tbody').innerText()).includes('25.00'),'Report retains original sale amount');
                if(route.includes('purchase_orders')) {
                    assert((await page.locator('.po-card').innerText()).includes('15.00'),'Purchase Order retains five units at cost 3');
                    await page.getByRole('button',{name:'Cancel',exact:true}).first().click();
                    const dialog=page.locator('.rm-modal-overlay.open'); await dialog.waitFor();
                    await select('light'); await contrast(dialog.locator('h2,h3')); await select('dark');
                    await dialog.locator('.rm-cancel').click();
                }
                if(route.includes('forecast_analytics')) {
                    for(const tab of ['features','trend','actual']) {
                        await page.locator('[data-analytics-tab="'+tab+'"]').click();
                        const canvas=page.locator('[data-analytics-panel="'+tab+'"] canvas');
                        const pixels=()=>canvas.evaluate(el=>Array.from(el.getContext('2d').getImageData(0,0,45,45).data));
                        const dark=await pixels(); assert(dark.some((v,i)=>i%4===3 && v>0),'Visible canvas axes/labels');
                        await select('light'); assert.notDeepEqual(await pixels(),dark,'Each visible chart redraws on theme change');
                        await select('system'); await page.emulateMedia({colorScheme:'light'});
                        await page.waitForFunction(()=>document.documentElement.dataset.theme==='light'); const light=await pixels();
                        await page.emulateMedia({colorScheme:'dark'}); await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
                        assert.notDeepEqual(await pixels(),light,'Each visible chart follows live System'); await select('dark');
                        await page.evaluate(()=>window.dispatchEvent(new Event('beforeprint'))); await page.emulateMedia({media:'print'});
                        assert.notDeepEqual(await pixels(),dark,'Paper chart labels redraw with dark ink');
                        assert.equal(await canvas.evaluate(el=>el.getContext('2d').fillStyle),'#475569','Paper canvas label/legend ink');
                        await page.emulateMedia({media:'screen'}); await page.evaluate(()=>window.dispatchEvent(new Event('afterprint')));
                        assert.equal(await canvas.evaluate(el=>el.getContext('2d').fillStyle),'#b1bfd3','Screen canvas restored after print');
                    }
                }
                const pageSize=page.getByRole('combobox',{name:'Rows per page'});
                if(await pageSize.count()) {
                    const before=await page.locator('tbody tr:not([hidden])').allTextContents();
                    await select('light'); await select('dark');
                    assert.deepEqual(await page.locator('tbody tr:not([hidden])').allTextContents(),before,'Report pagination retains visible rows');
                    await pageSize.selectOption('100');
                }
                await page.setViewportSize({width:794,height:1123});
                for(const paperMode of ['dark','system']) {
                    await select(paperMode); await page.emulateMedia({colorScheme:'dark',media:'print'});
                    assert.equal(await page.locator('body').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
                    assert.equal(await page.locator('.sidebar,.admin-mobile-topbar,.sidebar-backdrop,.theme-control,.theme-topbar,.report-filters,.report-actions,.no-print').filter({visible:true}).count(),0,'Paper hides navigation/forms/actions');
                    const paperControls=await page.locator('button,input,select,textarea').filter({visible:true}).evaluateAll(els=>els.map(el=>el.outerHTML.slice(0,180)));
                    assert.equal(paperControls.length,0,'Paper contains no screen controls: '+JSON.stringify(paperControls));
                    for(const selector of ['.main-content td','.main-content h2','.po-head','.label-product','.label-code','.fp-chart-axis']) {
                        const visible=page.locator(selector).filter({visible:true});
                        if(await visible.count()) assert.equal(await visible.first().evaluate(el=>getComputedStyle(el).color),'rgb(17, 17, 17)','Dark paper ink '+selector);
                    }
                    if(await page.locator('.table-wrap').count()) {
                        const size=await page.locator('.table-wrap').first().evaluate(el=>({scroll:el.scrollWidth,width:el.clientWidth,table:el.querySelector('table').getBoundingClientRect().width,cell:getComputedStyle(el.querySelector('td')).cssText,whitespace:getComputedStyle(el.querySelector('td')).whiteSpace,wrap:getComputedStyle(el.querySelector('td')).overflowWrap}));
                        assert(size.scroll<=size.width+1,'Paper table does not clip '+JSON.stringify(size));
                    }
                    for(const selector of ['.fp-table-wrap','.fp-queue']) {
                        if(await page.locator(selector).count()) assert(await page.locator(selector).evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Prototype paper does not clip '+selector);
                    }
                    const pdf=await page.pdf({format:'A4',margin:{top:'10mm',bottom:'10mm',left:'10mm',right:'10mm'}});
                    if(route.includes('forecast_analytics')) assert.equal(await page.locator('#actualChart').evaluate(el=>el.getContext('2d').fillStyle),'#475569','Print preview keeps dark chart ink after browser print events');
                    if(route.includes('inventory_valuation')||route.includes('print_barcodes')) assert((pdf.toString('latin1').match(/\/Type \/Page\b/g)||[]).length>1,'Long reports/label sheets retain pagination');
                    fs.mkdirSync(screenshotOutput,{recursive:true});
                    if(paperMode==='dark') await page.screenshot({path:path.join(screenshotOutput,'report-'+role+'-'+routes.indexOf(route)+'-print.png'),fullPage:true});
                    await page.emulateMedia({media:'screen'}); await select('dark');
                }
                await page.setViewportSize({width:1280,height:900});
                const print=page.locator('button[onclick="window.print()"]');
                if(await print.count()) { await print.first().click(); assert.equal(await page.evaluate(()=>window.printCalls),1,'Explicit action alone invokes report print'); }
                await page.reload(); assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark','Report choice survives reload');
                assert.equal(await content.innerText(),rendered,'Theme/printing preserves report values');
            }
            assert.deepEqual(JSON.parse(fixture('inventory_state')),records,'Report appearance/printing preserves inventory, purchasing and forecasts');
            assert.deepEqual(JSON.parse(fixture('cashier_state')),transactions,'Report appearance/printing preserves transactions');
            if(role==='inventory_manager') assert.equal((await context.request.get('/components/administrator/store_settings.php')).status(),403,'Report appearance cannot expand role visibility');
        };
        await page.goto('/?login=1'); await (await option('light')).click();
        if(reportsOnly) {
            for(const role of ['admin','inventory_manager']) { await login(role); await select('dark'); await reportAcceptance(role); await page.goto('/components/auth/logout.php'); }
            console.log('Theme report browser: passed (real application/MySQL, screens/state/modes/mobile/charts, light paper, controls/pagination/manual print)'); return;
        }
        for (const role of receiptsOnly ? ['cashier'] : ['admin','super_admin','inventory_manager','cashier']) {
            console.log('Theme browser role: '+role);
            await login(role);
            assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'system',role + ' default');
            await select('dark'); await page.reload(); assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark');
            if (role === 'cashier') {
                // POS has native autofocus plus a delayed startup focus at 100 ms.
                await page.waitForFunction(()=>document.activeElement.id === 'sku-input');
                await page.waitForTimeout(150);
            }
            await (await option('light')).focus(); await page.keyboard.press('Space');
            await page.waitForFunction(()=>document.querySelector('[data-theme-mode="light"]').getAttribute('aria-pressed') === 'true');
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
                assert.equal(saved[0].theme_preference,receiptsOnly ? 'system' : 'dark','Client identity cannot change another user');
                assert.equal(saved.find(user=>user.user_id === ['admin','super_admin','inventory_manager','cashier'].indexOf(role)+1).theme_preference,'light');
                await page.reload(); await select('dark');
            }
            for (const width of [320,360,390,800]) {
                await page.setViewportSize({width,height:844});
                const tools = page.locator('.header-display-tools').filter({visible:true});
                const notification = await tools.locator('.global-notification-button').boundingBox();
                const theme = await tools.locator('.theme-control').boundingBox();
                assert(notification.x >= 0 && notification.x + notification.width <= theme.x && theme.x + theme.width <= width, 'Notifications precede theme; whole group fits at '+width+'px');
                assert(await control().isVisible());
                const bounds = await control().boundingBox();
                assert(bounds.x >= 0 && bounds.y >= 0 && bounds.x + bounds.width <= width && bounds.y + bounds.height <= 844,'Mobile control stays inside viewport at '+width+'px');
                assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Mobile control remains unobscured at '+width+'px');
            }
            await page.setViewportSize({width:390,height:844});
            await page.keyboard.press('Tab'); await control().focus();
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
                    assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark','Selected mode follows navigation '+route);
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
                        assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Governance control unobscured '+route);
                        await page.keyboard.press('Tab'); await control().focus(); await contrast(control());
                        assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Governance focus visible '+route);
                    }
                    await page.reload(); assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark','Governance mode survives reload '+route);
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
                                    assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Dialog theme control unobscured');
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
                assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark','Workspace retains personal mode');
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
                if (!receiptsOnly) {
                const cashierState = JSON.parse(fixture('cashier_state'));
                for (const route of ['dashboard','pos','findProduct','shifts','refunds?sale_id=1','stock_issues','history','history?type=refunds','history?type=movements','history?type=shifts','history?type=sales&id=1']) {
                    const [screen,query] = route.split('?');
                    console.log('Theme Cashier screen: '+route);
                    assert.equal((await page.goto('/components/cashier/'+screen+'.php'+(query ? '?'+query : ''))).status(),200);
                    assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark');
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
                        assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Cashier control unobscured');
                        await page.keyboard.press('Tab'); await control().focus(); await contrast(control());
                        assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'));
                    }
                    await page.reload(); assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark');
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
                await (await option('light')).click();
                const saveAlert=page.getByRole('alert').filter({hasText:'display theme was not saved'}); await saveAlert.waitFor();
                assert(await saveAlert.evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Save warning visible above open checkout');
                await contrast(saveAlert); assert.equal(await page.locator('#review-cash').inputValue(),'100');
                await page.unroute('**/auth/theme.php'); await select('dark');
                await page.getByRole('button',{name:'Review cart',exact:true}).click();
                assert.equal((await context.request.get('/components/administrator/store_settings.php')).status(),403);
                assert.equal((await context.request.get('/components/inventory_management/products.php')).status(),403);
                assert.deepEqual(JSON.parse(fixture('cashier_state')),cashierState,'Theme changes preserve Held Sale, shift, sale/refund, stock and drawer records');
                }
                fixture('receipts');
                await page.addInitScript(()=>{window.printCalls=0;window.print=()=>window.printCalls++;});
                for (const paper of [58,80]) {
                    fixture('paper'+paper);
                    const receiptState=JSON.parse(fixture('cashier_state'));
                    for (const [route,printButton] of [
                        ['/components/invoice/sales.php?tab=transactions&sale_id=1','Print Receipt'],
                        ['/components/cashier/refunds.php?refund_id=1','Print Refund Receipt'],
                    ]) {
                        console.log('Theme receipt: '+paper+'mm '+route);
                        assert.equal((await page.goto(route)).status(),200);
                        const receipt=page.locator('.receipt-print-area'); await receipt.waitFor();
                        const details=await receipt.innerText();
                        assert(details.includes('Theme Test Item') && details.includes('Theme Register'),'Recorded product/Register details preserved');
                        assert(!details.includes('Edited live'),'Reprint does not use edited Store/product/Register details');
                        assert.equal(await page.evaluate(()=>window.printCalls),0,'Receipt navigation never auto-prints');
                        await gateControl();
                        for (const width of [320,390,1280]) {
                            await page.setViewportSize({width,height:900});
                            for (const mode of ['light','dark']) {
                                await select(mode);
                                assert.equal(await receipt.evaluate(el=>getComputedStyle(el).backgroundColor),mode==='dark'?'rgb(21, 34, 56)':'rgb(255, 255, 255)');
                                for (const selector of ['h1','.receipt-meta','.sale-receipt-item-name','.sale-receipt-money','.receipt-store-line']) await contrast(receipt.locator(selector));
                                await contrast(page.getByRole('button',{name:printButton,exact:true}));
                                assert.equal(await receipt.innerText(),details,'Theme preserves receipt contents');
                            }
                            await select('system');
                            for (const scheme of ['light','dark']) {
                                await page.emulateMedia({colorScheme:scheme});
                                await page.waitForFunction(scheme=>document.documentElement.dataset.theme===scheme,scheme);
                                assert.equal(await receipt.evaluate(el=>getComputedStyle(el).backgroundColor),scheme==='dark'?'rgb(21, 34, 56)':'rgb(255, 255, 255)');
                                assert.equal(await receipt.innerText(),details);
                            }
                            assert(await receipt.evaluate(el=>Array.from(el.querySelectorAll('*')).every(child=>child.scrollWidth<=child.clientWidth+1)),'Thermal receipt text fits');
                        }
                        await select('dark'); await page.emulateMedia({colorScheme:'light'});
                        assert.equal(await receipt.evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(21, 34, 56)','Explicit Dark overrides live light device');
                        await page.reload();
                        assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark'); assert.equal(await receipt.innerText(),details);
                        assert.equal(await receipt.getAttribute('data-paper-width-mm'),String(paper),'Reprint uses current Register paper width');
                        assert(Math.abs((await receipt.boundingBox()).width-paper*96/25.4)<1,'Rendered thermal width');
                        if (route.includes('/sales.php')) {
                            const nativeDialog=page.locator('#receiptModal');
                            const keyboardSave=page.waitForResponse(r=>r.url().endsWith('/auth/theme.php') && r.request().method()==='POST');
                            await (await option('light')).focus(); await page.keyboard.press('Space'); assert.equal((await keyboardSave).status(),200);
                            assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'light','Modal theme selection works by keyboard');
                            await select('dark');
                            await page.keyboard.press('Tab'); await control().focus(); await page.keyboard.press('Tab');
                            assert(await nativeDialog.evaluate(el=>el.contains(document.activeElement)),'Keyboard remains inside native modal');
                            await page.route('**/auth/theme.php',r=>r.fulfill({status:503,contentType:'application/json',body:'{"success":false}'}));
                            await (await option('light')).click();
                            const warning=nativeDialog.getByRole('alert').filter({hasText:'display theme was not saved'}); await warning.waitFor();
                            for (const width of [320,390,1280]) {
                                await page.setViewportSize({width,height:900});
                                assert(await warning.evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Failed save warning visible in native modal');
                                await contrast(warning);
                            }
                            assert.equal(await receipt.innerText(),details);
                            await page.keyboard.press('Escape'); await page.waitForFunction(()=>!document.querySelector('#receiptModal').open);
                            await page.locator('body>.theme-save-alert').waitFor();
                            assert(await page.locator('body>.theme-save-alert').isVisible(),'Unsaved warning remains after modal closes');
                            await page.evaluate(()=>viewReceipt(1,document.querySelector('#receipt-table-title')));
                            await receipt.waitFor(); assert.equal(await receipt.innerText(),details,'AJAX reopening preserves themed receipt');
                            assert(await warning.isVisible(),'Existing warning follows reopened modal');
                            await page.unroute('**/auth/theme.php'); await select('dark');
                        }
                        await page.getByRole('button',{name:printButton,exact:true}).click();
                        assert.equal(await page.evaluate(()=>window.printCalls),1,'Only explicit printing calls browser print');
                        await page.emulateMedia({media:'print'});
                        const paperCopy=page.locator('.receipt-print-root .receipt-print-area');
                        assert.equal(await paperCopy.evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
                        assert.equal(await paperCopy.evaluate(el=>getComputedStyle(el).color),'rgb(17, 17, 17)');
                        assert.equal(await paperCopy.innerText(),details);
                        assert.equal(await control().count(),0,'Print hides appearance controls');
                        assert.equal(await page.locator('.receipt-print-root button,.receipt-print-root select').count(),0,'Paper contains no controls');
                        assert(Math.abs((await paperCopy.boundingBox()).width-paper*96/25.4)<1);
                        await page.evaluate(()=>window.dispatchEvent(new Event('afterprint'))); await page.emulateMedia({media:'screen'});
                        assert.equal(await receipt.innerText(),details);
                        assert.deepEqual(JSON.parse(fixture('cashier_state')),receiptState,'Screen/printing preserves transactions and immutable customer details');
                    }
                }
                await page.goto('/components/invoice/sales.php?tab=transactions&sale_id=3');
                assert((await page.locator('.receipt-print-area').innerText()).includes('original Store and item details were not preserved'),'Legacy original-details notice retained');
                assert.equal((await context.request.get('/components/invoice/sales.php?action=view&ajax=1&sale_id=4')).status(),403,'Cashier cannot see another seller receipt');
                const forbiddenRefund=await context.request.get('/components/cashier/refunds.php?refund_id=2');
                assert(!(await forbiddenRefund.text()).includes('aria-label="Refund Receipt"'),'Cashier cannot see another issuing Cashier refund');
                if (receiptsOnly) { console.log('Theme receipt browser: passed (real application/MySQL, modes/mobile/modal, saved details, 58/80mm, manual print, authorization)'); return; }
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
                    assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark','Inventory navigation retains mode');
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
                        assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Inventory control unobscured');
                        await page.keyboard.press('Tab'); await control().focus(); await contrast(control());
                        assert(await control().evaluate(el=>getComputedStyle(el).outlineStyle!=='none'));
                    }
                    await page.reload(); assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark');
                }
                await page.goto('/components/inventory_management/products.php');
                await page.locator('.manage-product').first().click();
                await select('light'); await select('dark');
                await contrast(page.locator('#product-drawer-title'));
                await page.locator('#product-drawer [data-close-drawer]').click();
                await page.locator('#product-add-trigger').click(); await contrast(page.locator('#add-product-btn'));
                await page.locator('#add-product-btn').click();
                assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark','New dialog control reflects current mode');
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
                        assert(await control().evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Inventory dialog selector unobscured');
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
                    await page.goto(route); assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'dark');
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
            if(role === 'admin' || role === 'inventory_manager') await reportAcceptance(role);
            await page.route('**/auth/theme.php',route=>route.fulfill({status:503,contentType:'application/json',body:'{"success":false}'}));
            await (await option('light')).click();
            await page.getByRole('alert').filter({hasText:'display theme was not saved'}).waitFor();
            await page.unroute('**/auth/theme.php');
            await select('dark');
            await select(savedModes[role]);
            await page.goto('/components/auth/logout.php');
            assert.equal(await control().evaluate(el => el.dataset.themeMode || el.dataset.themeCurrent),'light','Separate login preference restored');
        }
        const fresh = await browser.newContext({baseURL:origin});
        const other = await fresh.newPage();
        for (const role of ['admin','super_admin','inventory_manager','cashier']) {
            await other.goto('/?login=1');
            await other.waitForFunction(()=>document.activeElement.id==='landing-login-username');
            assert.equal(await other.locator('#loginModal [data-theme-current]').getAttribute('data-theme-current'),'system','Fresh browser has no local login preference');
            await other.locator('#landing-login-username').fill('theme_'+role); await other.locator('#landing-login-password').fill(data.password);
            await Promise.all([other.waitForNavigation({waitUntil:'domcontentloaded'}),other.locator('#landing-login-password').press('Enter')]);
            await other.goto('/');
            assert.equal(await other.locator('[data-theme-current]').filter({visible:true}).getAttribute('data-theme-current'),savedModes[role],'Fresh browser restores '+role+' account');
            await other.goto('/components/auth/logout.php');
        }
        const blocked = await browser.newContext({baseURL:origin,colorScheme:'dark'});
        await blocked.addInitScript(()=>{
            for (const storage of ['localStorage','sessionStorage']) Object.defineProperty(window,storage,{get(){throw new DOMException('blocked','SecurityError');}});
        });
        const privatePage=await blocked.newPage();
        await privatePage.goto('/?login=1');
        await privatePage.waitForFunction(()=>document.activeElement.id==='landing-login-username');
        await privatePage.locator('#loginModal .theme-trigger').click();
        await privatePage.locator('[data-theme-mode="light"]').filter({visible:true}).click();
        await privatePage.locator('#landing-login-username').fill('theme_inventory_manager');
        await privatePage.locator('#landing-login-password').fill(data.password);
        await Promise.all([privatePage.waitForNavigation({waitUntil:'domcontentloaded'}),privatePage.locator('#landing-login-password').press('Enter')]);
        await privatePage.goto('/');
        const privateControl=privatePage.locator('[data-theme-current]').filter({visible:true});
        assert.equal(await privateControl.getAttribute('data-theme-current'),'system','Blocked storage sign-in restores account');
        assert.equal(await privatePage.locator('body').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(11, 18, 32)','Blocked storage System follows device');
        const privateSave=privatePage.waitForResponse(r=>r.url().endsWith('/auth/theme.php') && r.request().method()==='POST');
        await privateControl.click();
        await privatePage.locator('[data-theme-mode="light"]').filter({visible:true}).click(); assert.equal((await privateSave).status(),200);
        await privatePage.reload(); assert.equal(await privateControl.getAttribute('data-theme-current'),'light','Blocked storage account change survives reload');
        await privatePage.goto('/components/auth/logout.php');
        assert.equal(await privatePage.locator('#loginModal [data-theme-current]').getAttribute('data-theme-current'),'system','Blocked storage logout restores System');
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
        assert.equal(finalState.shift.status,'open'); assert(finalState.shift.locked_at); assert.equal(finalState.sales,4); assert.equal(finalState.stock,10);
        console.log('Theme account browser: passed (fresh/upgrade parity, all roles/mobile, keyboard/focus/contrast, device, persistence/isolation, protected writes/failures, dialogs/charts, scans/forms/cart, gates, receipt/report paper)');
    } finally {
        if(browser) await browser.close(); if(server) server.kill(); fixture('cleanup'); fs.rmSync(recovery,{recursive:true,force:true});
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
