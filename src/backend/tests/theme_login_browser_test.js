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
            const theme = await page.locator('.landing-nav .theme-trigger').boundingBox();
            const login = await page.locator('.landing-nav__login').boundingBox();
            assert(theme.x>=0 && theme.x+theme.width<=login.x && login.x+login.width<=width, 'Landing controls fit at '+width+'px');
            assert(Math.abs(theme.y+theme.height/2-login.y-login.height/2)<1, 'Landing controls align');
            if (width <= 640) {
                assert(await page.locator('.landing-header .landing-brand__logo').isVisible(), 'Full mobile logo visible');
                const sections = page.locator('.landing-nav__sections');
                assert(await sections.getByRole('link', {name:'Capabilities',exact:true}).isVisible(), 'Capabilities visible on mobile');
                assert(await sections.getByRole('link', {name:'Workflow',exact:true}).isVisible(), 'Workflow visible on mobile');
                assert((await sections.boundingBox()).y >= login.y + login.height, 'Section navigation occupies second row');
                assert.equal(await page.locator('.landing-preview li').filter({visible:true}).count(), 2, 'Compact mobile stock preview');
            }
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No overflow at '+width+'px');
        }
        await page.setViewportSize({width:390,height:844});
        const menu = page.locator('.landing-nav .theme-landing-menu');
        const trigger = menu.locator('summary');
        const chooseTheme = async mode => {
            await trigger.click();
            const panel = await menu.locator('.theme-menu-panel').boundingBox();
            assert(panel.x >= 0 && panel.x + panel.width <= page.viewportSize().width, 'Appearance menu fits');
            assert(await menu.locator('[data-theme-mode="'+mode+'"] > span').isVisible(), 'Menu labels visible on mobile');
            await menu.locator('[data-theme-mode="'+mode+'"]').click();
            assert.equal(await menu.getAttribute('open'), null, 'Selection closes menu');
            assert(await trigger.evaluate(el => el === document.activeElement), 'Selection returns focus');
        };
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
        assert.equal(await trigger.getAttribute('data-theme-current'), 'system');
        const background = () => page.locator('body').evaluate(el => getComputedStyle(el).backgroundColor);
        const dark = await background();
        await chooseTheme('light');
        await checkBrandImages('light');
        assert.notEqual(await background(), dark);
        await page.reload();
        assert.equal(await trigger.getAttribute('data-theme-current'), 'light');
        await checkBrandImages('light');
        await chooseTheme('system');
        await page.emulateMedia({colorScheme: 'light'});
        await page.waitForFunction(() => document.documentElement.dataset.theme === 'light');
        assert.notEqual(await background(), dark);
        await page.emulateMedia({colorScheme: 'dark'});
        await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(11, 22, 37)');
        assert.equal(await background(), dark);
        await checkBrandImages('dark');
        await page.keyboard.press('Tab'); await trigger.focus();
        assert(await trigger.evaluate(el => getComputedStyle(el).outlineStyle !== 'none'));
        await trigger.press('Space');
        await page.keyboard.press('Escape');
        assert.equal(await menu.getAttribute('open'), null, 'Escape closes appearance menu');
        await trigger.click();
        await page.locator('#landing-title').click();
        assert.equal(await menu.getAttribute('open'), null, 'Outside click closes appearance menu');
        const preview = await page.locator('.landing-preview li strong').first().evaluate(el => ({ink:getComputedStyle(el).color,surface:getComputedStyle(el.closest('.landing-preview')).backgroundColor}));
        assert.equal(preview.ink, 'rgb(243, 246, 251)', 'Stock-flow labels remain readable');
        assert.equal(preview.surface, 'rgb(16, 30, 48)', 'Preview uses reference navy');
        if (process.env.THEME_LANDING_OUTPUT) {
            for (const width of [1280,390]) {
                await page.setViewportSize({width,height:844});
                await chooseTheme('dark');
                await trigger.click();
                await page.screenshot({path:process.env.THEME_LANDING_OUTPUT+'/landing-'+width+'.png'});
                await page.keyboard.press('Escape');
            }
        }
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.goto(url+'/?login=1');
        await page.waitForFunction(()=>document.activeElement.id==='landing-login-username');
        await page.locator('#landing-login-username').fill('Unsubmitted login');
        await page.locator('#landing-login-password').fill('Unsubmitted password');
        const modalMenu = page.locator('#loginModal .theme-login-menu');
        const select = modalMenu.locator('summary');
        const chooseModalTheme = async mode => {
            await select.click();
            const option = modalMenu.locator('[data-theme-mode="'+mode+'"]');
            const bounds = await option.boundingBox();
            assert(bounds.width >= 44 && bounds.height >= 44, 'Theme option meets touch target');
            await option.click();
            assert.equal(await modalMenu.getAttribute('open'), null, 'Modal selection closes menu');
            assert.equal(await select.getAttribute('data-theme-current'), mode, 'Modal shows current mode');
            const backdrop = await page.locator('.landing-login-modal__backdrop').evaluate(el => ({
                overlay: getComputedStyle(el.parentElement).backgroundColor,
                tint: getComputedStyle(el).backgroundColor,
                blur: getComputedStyle(el).backdropFilter
            }));
            assert.equal(backdrop.overlay, 'rgba(0, 0, 0, 0)', 'Modal overlay stays transparent in '+mode);
            assert.equal(backdrop.tint, 'rgba(11, 20, 39, 0.45)', 'Page stays visible through backdrop in '+mode);
            assert.equal(backdrop.blur, 'blur(8px)', 'Page stays blurred in '+mode);
            assert(await select.evaluate(el => el === document.activeElement), 'Modal selection returns focus');
        };
        assert.equal(await page.locator('.landing-login-modal__body>.theme-control').count(), 0, 'Large selector removed');
        for (const width of [320,360,390,1280]) {
            await page.setViewportSize({width,height:900});
            const bounds=await select.boundingBox();
            const close = await page.locator('.landing-login-modal__close').boundingBox();
            assert(bounds.width >= 44 && bounds.height === 44 && close.width === 44 && close.height === 44, 'Modal controls meet touch targets');
            assert(Math.abs(close.x - bounds.x - bounds.width - 8) < 1 && Math.abs(close.y - bounds.y) < 1, 'Theme sits left of close with 8px gap');
            assert(bounds.x>=0 && bounds.y>=0 && bounds.x+bounds.width<=width,'Login control fits at '+width+'px');
            assert(await select.evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Login control unobscured');
            for (const mode of ['light','dark','system']) {
                await chooseModalTheme(mode);
                assert.equal(await page.locator('#landing-login-username').inputValue(),'Unsubmitted login');
                assert.equal(await page.locator('#landing-login-password').inputValue(),'Unsubmitted password');
            }
            await page.keyboard.press('Tab'); await select.focus();
            assert(await select.evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Login focus visible');
            await select.press('Space');
            await page.keyboard.press('Escape');
            assert.equal(await modalMenu.getAttribute('open'), null, 'Escape closes modal theme menu');
            assert(await page.locator('#loginModal').evaluate(el => el.classList.contains('is-open')), 'Escape keeps login open');
            if (process.env.THEME_LANDING_OUTPUT && [390,1280].includes(width)) {
                await select.click();
                await page.screenshot({path:process.env.THEME_LANDING_OUTPUT+'/login-'+width+'.png'});
                await page.keyboard.press('Escape');
            }
        }
        await page.getByRole('link',{name:'Forgot password?'}).click();
        await page.waitForFunction(()=>document.activeElement.id==='landing-recovery-identity');
        await page.locator('#landing-recovery-identity').fill('Unsubmitted recovery');
        await chooseModalTheme('dark');
        assert.equal(await page.locator('#landing-recovery-identity').inputValue(),'Unsubmitted recovery');
        assert(await select.evaluate(el=>{const b=el.getBoundingClientRect();return el.contains(document.elementFromPoint(b.x+b.width/2,b.y+b.height/2));}),'Recovery control unobscured');
        await page.getByRole('link',{name:'Back to login'}).click();
        await page.waitForFunction(()=>document.activeElement.id==='landing-login-username');
        assert.equal(await page.locator('#landing-login-username').inputValue(),'Unsubmitted login');
        assert.equal(await select.getAttribute('data-theme-current'),'dark','Modal faces share login preference');
        await page.reload();
        assert.equal(await select.getAttribute('data-theme-current'),'dark','Modal theme persists after reload');
        const blocked = await browser.newContext({colorScheme:'dark'});
        await blocked.addInitScript(()=>{
            for (const storage of ['localStorage','sessionStorage']) Object.defineProperty(window,storage,{get(){throw new DOMException('blocked','SecurityError');}});
        });
        const privatePage=await blocked.newPage(); await privatePage.goto(url);
        await privatePage.locator('[data-login-modal-open]').first().click();
        await privatePage.waitForFunction(()=>document.activeElement.id==='landing-login-username',{},{timeout:2000});
        await privatePage.locator('#loginModal .theme-trigger').click();
        await privatePage.getByRole('button',{name:'Light',exact:true}).filter({visible:true}).click();
        await privatePage.locator('#landing-login-username').fill('Form remains usable');
        assert.equal(await privatePage.locator('#landing-login-username').inputValue(),'Form remains usable');
        await privatePage.reload(); assert.equal(await privatePage.locator('.landing-nav [data-theme-current]').getAttribute('data-theme-current'),'system');
        console.log('Theme login browser: passed');
    } finally { if(browser)await browser.close(); if(server)server.kill(); fs.rmSync(recovery,{recursive:true,force:true}); }
})().catch(error => { console.error(error); process.exitCode = 1; });
