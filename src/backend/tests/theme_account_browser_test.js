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
        const control = () => page.getByRole('combobox',{name:'Display theme'}).filter({visible:true});
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
                await page.goto('/components/cashier/dashboard.php'); await contrast(page.locator('.stock-level-pill'));
                await page.goto('/components/cashier/pos.php');
                await contrast(page.locator('.count-badge').filter({visible:true}));
                await contrast(page.getByRole('button',{name:'Add to Cart',exact:true}).first());
                await page.locator('#sku-input').fill('THEME-ITEM'); await page.locator('#sku-input').press('Enter');
                await page.locator('#cart-body').getByText('Theme Test Item',{exact:true}).waitFor();
                const cartText = await page.locator('#cart-body').innerText();
                await select('light'); assert.equal(await page.locator('#cart-body').innerText(),cartText); await select('dark');
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
                for(const route of ['/components/inventory_management/inventory_counts.php','/components/report/stock_receiving.php']) {
                    await page.goto(route); await page.locator('#product_code_scan').fill('THEME-BARCODE'); await page.locator('#product_code_scan').press('Enter');
                    await page.waitForFunction(()=>document.getElementById('product_id').value==='1');
                    const field=route.includes('inventory_counts') ? '#physical_quantity' : '#received_qty';
                    const notes=page.locator(route.includes('inventory_counts') ? '#discrepancy_reason' : '#notes');
                    await page.locator(field).fill('7'); await notes.fill('Unsubmitted inventory work');
                    await select('light'); assert.equal(await page.locator(field).inputValue(),'7'); assert.equal(await notes.inputValue(),'Unsubmitted inventory work');
                    await select('dark'); assert.equal(await page.locator('#product_id').inputValue(),'1'); await contrast(page.locator(field));
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
                await select('light'); assert.notDeepEqual(await pixels(),darkPixels,'Theme switch redraws visible chart pixels'); await select('dark');
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
