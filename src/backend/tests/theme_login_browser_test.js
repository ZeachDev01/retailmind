const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
(async () => {
    let browser, server;
    const recovery = fs.mkdtempSync(path.join(os.tmpdir(),'rm-theme-login-'));
    try {
        let url = process.env.LOGIN_TEST_URL;
        if (!url) {
            const listener=net.createServer(); await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));
            const port=listener.address().port; await new Promise(resolve=>listener.close(resolve));
            url='http://127.0.0.1:'+port;
            server=spawn(process.env.PHP_BINARY || 'php',['-S','127.0.0.1:'+port,'-t','src/frontend'],
                {env:{...process.env,BACKUP_STORAGE_PATH:recovery},stdio:['ignore','ignore','ignore']});
            for(let i=0;i<200;i++) { try { if((await fetch(url)).ok)break; }catch{} if(i===199)throw new Error('Login test server unavailable'); await new Promise(resolve=>setTimeout(resolve,50)); }
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({colorScheme: 'dark', viewport: {width: 390, height: 844}});
        const page = await context.newPage();
        await page.goto(url);
        const select = page.getByRole('combobox', {name: 'Display theme'}).filter({visible:true});
        assert.equal(await select.inputValue(), 'system');
        const background = () => page.locator('body').evaluate(el => getComputedStyle(el).backgroundColor);
        const dark = await background();
        await select.selectOption('light');
        assert.notEqual(await background(), dark);
        await page.reload();
        assert.equal(await select.inputValue(), 'light');
        await select.selectOption('system');
        await page.emulateMedia({colorScheme: 'light'});
        assert.notEqual(await background(), dark);
        await page.emulateMedia({colorScheme: 'dark'});
        await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(11, 18, 32)');
        assert.equal(await background(), dark);
        await select.focus();
        assert(await select.evaluate(el => getComputedStyle(el).outlineStyle !== 'none'));
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.goto(url+'/?login=1');
        await page.waitForFunction(()=>document.activeElement.id==='landing-login-username');
        await page.locator('#landing-login-username').fill('Unsubmitted login');
        await page.locator('#landing-login-password').fill('Unsubmitted password');
        for (const width of [320,360,390,1280]) {
            await page.setViewportSize({width,height:900});
            const bounds=await select.boundingBox();
            assert(bounds.x>=0 && bounds.y>=0 && bounds.x+bounds.width<=width,'Login control fits at '+width+'px');
            assert(await select.evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)===el;}),'Login control unobscured');
            for (const mode of ['light','dark','system']) {
                await select.selectOption(mode);
                assert.equal(await page.locator('#landing-login-username').inputValue(),'Unsubmitted login');
                assert.equal(await page.locator('#landing-login-password').inputValue(),'Unsubmitted password');
            }
            await select.focus();
            assert(await select.evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Login focus visible');
        }
        await page.getByRole('link',{name:'Forgot password?'}).click();
        await page.waitForFunction(()=>document.activeElement.id==='landing-recovery-identity');
        await page.locator('#landing-recovery-identity').fill('Unsubmitted recovery');
        await select.selectOption('dark');
        assert.equal(await page.locator('#landing-recovery-identity').inputValue(),'Unsubmitted recovery');
        assert(await select.evaluate(el=>{const b=el.getBoundingClientRect();return document.elementFromPoint(b.x+b.width/2,b.y+b.height/2)===el;}),'Recovery control unobscured');
        await page.getByRole('link',{name:'Back to login'}).click();
        await page.waitForFunction(()=>document.activeElement.id==='landing-login-username');
        assert.equal(await page.locator('#landing-login-username').inputValue(),'Unsubmitted login');
        assert.equal(await select.inputValue(),'dark','Modal faces share login preference');
        const blocked = await browser.newContext({colorScheme:'dark'});
        await blocked.addInitScript(()=>{
            for (const storage of ['localStorage','sessionStorage']) Object.defineProperty(window,storage,{get(){throw new DOMException('blocked','SecurityError');}});
        });
        const privatePage=await blocked.newPage(); await privatePage.goto(url);
        await privatePage.locator('[data-login-modal-open]').first().click();
        await privatePage.waitForFunction(()=>document.activeElement.id==='landing-login-username',{},{timeout:2000});
        await privatePage.getByRole('combobox',{name:'Display theme'}).selectOption('light');
        await privatePage.locator('#landing-login-username').fill('Form remains usable');
        assert.equal(await privatePage.locator('#landing-login-username').inputValue(),'Form remains usable');
        await privatePage.reload(); assert.equal(await privatePage.getByRole('combobox',{name:'Display theme'}).inputValue(),'system');
        console.log('Theme login browser: passed');
    } finally { if(browser)await browser.close(); if(server)server.kill(); fs.rmSync(recovery,{recursive:true,force:true}); }
})().catch(error => { console.error(error); process.exitCode = 1; });
