'use strict';
const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const net = require('node:net');
const path = require('node:path');
const { chromium } = require('playwright');
if (process.env.RUN_DB_TESTS !== '1') { console.log('Compact product creation: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const fixture = action => {
    const result = spawnSync('php', [path.join(__dirname, 'support/theme_fixture.php'), action, database], {encoding:'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
};
(async () => {
    let server, browser, created = false;
    try {
        const data = fixture('create'); created = true; fixture('open_fiscal_period');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env:{...process.env, RM_THEME_TEST_DATABASE:database}, stdio:'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless:true});
        for (const width of [1440,390]) {
            const context = await browser.newContext({viewport:{width,height:900}, reducedMotion:'reduce'});
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            const errors = []; page.on('pageerror', error => errors.push(error.message));
            await page.route('https://**/*', route => route.fulfill({body:''}));
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_inventory_manager');
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1')),page.getByRole('button',{name:'Log in',exact:true}).click()]);
            const products = origin + '/components/inventory_management/products.php';
            await page.goto(products);
            await page.locator('#product-add-trigger').click(); await page.locator('#add-product-btn').click();
            for (const theme of ['light','dark']) {
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                for (const id of ['product-name','product-category-search','product-base-unit','cost-price','selling-price','create-barcode-input']) assert.equal(await page.locator('#'+id).isVisible(),true,id+' visible');
                assert.equal(await page.locator('#add-product-form .wizard-progress').count(),0);
                assert.equal(await page.locator('#add-product-form [name="preferred_supplier"]').count(),0);
                const geometry = await page.locator('#add-product-modal .rm-modal').evaluate(el => ({width:el.clientWidth,scroll:el.scrollWidth}));
                assert(geometry.scroll <= geometry.width+1, JSON.stringify(geometry));
                await page.screenshot({path:path.join(require('node:os').tmpdir(),`rm-478-${width}-${theme}.png`)});
                await page.locator('#create-opening-stock summary').focus();
                await page.keyboard.press('Enter'); assert.equal(await page.locator('#initial-stock-quantity').isVisible(),true);
                await page.keyboard.press('Enter');
            }
            const name = 'Compact free product ' + width;
            await page.locator('#product-name').fill(name);
            const category = await page.locator('#product-category option').nth(1).textContent();
            await page.locator('#product-category-search').fill(category);
            await page.getByRole('option',{name:category,exact:true}).click();
            await page.locator('#selling-price').fill('5');
            await page.locator('#product-create-save').click();
            await page.waitForFunction(() => document.getElementById('cost-price').getAttribute('aria-invalid') === 'true');
            assert.equal(await page.locator('#product-name').inputValue(),name);
            await page.locator('#cost-price').fill('0');
            const retryToken = await page.locator('#add-product-form [name="operation_token"]').inputValue();
            // Bypass client checks: server failure retains values and opens the form.
            await Promise.all([page.waitForNavigation(),page.locator('#add-product-form').evaluate(form => {form.elements.cost_price.value=''; form.submit();})]);
            await page.locator('#product-create-error').waitFor();
            assert.equal(await page.locator('#product-name').inputValue(),name);
            assert.match(await page.locator('#product-create-error').innerText(),/Cost price is required/);
            assert.equal(await page.locator('#add-product-form [name="operation_token"]').inputValue(),retryToken,'Server retry retains the operation token');
            await page.locator('#cost-price').fill('0');
            await page.locator('#create-opening-stock summary').click();
            await page.locator('#initial-stock-quantity').fill('2');
            await page.locator('#starting-stock-expiration').fill('2027-12-31');
            await page.locator('[name="print_after_create"]').check();
            await Promise.all([page.waitForURL(url => url.pathname.endsWith('/print_barcodes.php')),page.locator('#product-create-save').click()]);
            assert.match(page.url(),/autoprint=1/);
            await page.goto(products);
            const saved = await page.evaluate(name => JSON.parse(Array.from(document.scripts).find(script => script.textContent.includes('const productData =')).textContent.match(/const productData = (.*);/)[1]).find(product => product.product_name===name),name);
            assert.equal(Number(saved.quantity_on_hand),2); assert.equal(Number(saved.cost_price),0); assert.equal(Number(saved.cost_known),1);
            assert.match(saved.barcode,/^RM/); assert.equal(saved.status,'active');
            for (const field of ['initial_stock_quantity','starting_packs','starting_loose']) {
                await page.goto(products+'?origin=receiving');
                await page.locator('#receiving-product-note').waitFor();
                assert.equal(await page.locator('#units-per-package').isVisible(),true,'Receiving conversion stays visible');
                assert.equal(await page.locator('#create-opening-stock').isVisible(),false);
                await Promise.all([page.waitForNavigation(),page.locator('#add-product-form').evaluate((form,{field,category,name}) => {
                    form.elements.product_name.value=name;
                    form.elements.category_id.value=category;
                    form.elements.cost_price.value='0'; form.elements.selling_price.value='5';
                    form.elements[field].disabled=false; form.elements[field].value='1'; form.submit();
                },{field,category:saved.category_id,name:'Tampered '+field})]);
                await page.locator('#product-create-error').waitFor();
                assert.match(await page.locator('#product-create-error').innerText(),/Receiving/);
                assert.equal(await page.evaluate(() => Array.from(document.querySelectorAll('.manage-product')).some(button => button.getAttribute('aria-label').startsWith('Manage Tampered'))),false);
            }
            await page.locator('#product-name').fill('Receiving zero stock '+width);
            await Promise.all([page.waitForNavigation(),page.locator('#product-create-save').click()]);
            await page.waitForFunction(() => Array.from(document.scripts).some(script => script.textContent.includes('const productData =')));
            const receivingProduct = await page.evaluate(name => JSON.parse(Array.from(document.scripts).find(script => script.textContent.includes('const productData =')).textContent.match(/const productData = (.*);/)[1]).find(product => product.product_name===name),'Receiving zero stock '+width);
            assert.equal(Number(receivingProduct.quantity_on_hand),0,'Receiving-origin creation saves no opening stock');
            assert.deepEqual(errors,[]);
            await context.close();
        }
        console.log('Compact product creation: desktop/mobile, themes, keyboard, server bypass, zero cost, opening stock and printing passed');
    } finally {
        if (browser) await browser.close(); if (server) server.kill(); if (created) fixture('cleanup');
    }
})().catch(error => {console.error(error);process.exitCode=1;});
