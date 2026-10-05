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
        assert.equal(await page.locator('.theme-topbar').count(), 0, 'Landing theme lives in navigation');
        for (const width of [320,390,715,1280]) {
            await page.setViewportSize({width,height:844});
            const theme = await page.locator('.landing-nav .theme-options').boundingBox();
            const login = await page.locator('.landing-nav__login').boundingBox();
            assert(theme.x>=0 && theme.x+theme.width<=login.x && login.x+login.width<=width, 'Landing controls fit at '+width+'px');
            assert(Math.abs(theme.y+theme.height/2-login.y-login.height/2)<1, 'Landing controls align');
        }
        await page.setViewportSize({width:390,height:844});
        const select = page.locator('[data-theme-mode][aria-pressed="true"]').filter({visible:true});
        const checkBrandImages = async theme => {
            const images = page.locator('img[data-brand-base]');
            assert.equal(await images.count(),5, 'Header, footer, compact logo, and both login faces use themed assets');
            for (const image of await images.all()) {
                assert((await image.getAttribute('src')).includes('/'+theme+'/'), 'Logo follows resolved theme');
                await image.evaluate(el => el.decode());
                assert(await image.evaluate(el => el.naturalWidth>0), 'Logo asset loads');
                assert((await image.getAttribute('srcset')).includes('/'+theme+'/'), 'Retina logo follows theme');
            }
            const favicon = await page.locator('link[rel="icon"]').getAttribute('href');
            assert(favicon.includes('/'+theme+'/'), 'Favicon follows theme');
            assert.equal((await context.request.get(favicon)).status(),200);
        };
        await checkBrandImages('dark');
        assert.equal(await select.getAttribute('data-theme-mode'), 'system');
        const background = () => page.locator('body').evaluate(el => getComputedStyle(el).backgroundColor);
        const dark = await background();
        await page.getByRole('button', {name:'Light',exact:true}).filter({visible:true}).click();
        await checkBrandImages('light');
        assert.notEqual(await background(), dark);
        await page.reload();
        assert.equal(await select.getAttribute('data-theme-mode'), 'light');
        await checkBrandImages('light');
        await page.getByRole('button', {name:'System',exact:true}).filter({visible:true}).click();
        await page.emulateMedia({colorScheme: 'light'});
        assert.notEqual(await background(), dark);
        await page.emulateMedia({colorScheme: 'dark'});
        await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(11, 18, 32)');
        assert.equal(await background(), dark);
        await checkBrandImages('dark');
        await page.keyboard.press('Tab'); await select.focus();
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
            assert(await select.evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Login control unobscured');
            for (const mode of ['light','dark','system']) {
                await page.locator('[data-theme-mode="'+mode+'"]').filter({visible:true}).click();
                assert.equal(await page.locator('#landing-login-username').inputValue(),'Unsubmitted login');
                assert.equal(await page.locator('#landing-login-password').inputValue(),'Unsubmitted password');
            }
            await page.keyboard.press('Tab'); await select.focus();
            assert(await select.evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Login focus visible');
        }
        await page.getByRole('link',{name:'Forgot password?'}).click();
        await page.waitForFunction(()=>document.activeElement.id==='landing-recovery-identity');
        await page.locator('#landing-recovery-identity').fill('Unsubmitted recovery');
        await page.getByRole('button', {name:'Dark',exact:true}).filter({visible:true}).click();
        assert.equal(await page.locator('#landing-recovery-identity').inputValue(),'Unsubmitted recovery');
        assert(await select.evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Recovery control unobscured');
        await page.getByRole('link',{name:'Back to login'}).click();
        await page.waitForFunction(()=>document.activeElement.id==='landing-login-username');
        assert.equal(await page.locator('#landing-login-username').inputValue(),'Unsubmitted login');
        assert.equal(await select.getAttribute('data-theme-mode'),'dark','Modal faces share login preference');
        const blocked = await browser.newContext({colorScheme:'dark'});
        await blocked.addInitScript(()=>{
            for (const storage of ['localStorage','sessionStorage']) Object.defineProperty(window,storage,{get(){throw new DOMException('blocked','SecurityError');}});
        });
        const privatePage=await blocked.newPage(); await privatePage.goto(url);
        await privatePage.locator('[data-login-modal-open]').first().click();
        await privatePage.waitForFunction(()=>document.activeElement.id==='landing-login-username',{},{timeout:2000});
        await privatePage.getByRole('button',{name:'Light',exact:true}).filter({visible:true}).click();
        await privatePage.locator('#landing-login-username').fill('Form remains usable');
        assert.equal(await privatePage.locator('#landing-login-username').inputValue(),'Form remains usable');
        await privatePage.reload(); assert.equal(await privatePage.locator('[data-theme-mode][aria-pressed="true"]').filter({visible:true}).getAttribute('data-theme-mode'),'system');
        console.log('Theme login browser: passed');
    } finally { if(browser)await browser.close(); if(server)server.kill(); fs.rmSync(recovery,{recursive:true,force:true}); }
})().catch(error => { console.error(error); process.exitCode = 1; });
