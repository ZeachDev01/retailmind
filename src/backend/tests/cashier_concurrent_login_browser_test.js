const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const startServer = require('./support/concurrent_login_server');

(async () => {
    const server = await startServer();
    const browser = await chromium.launch({headless: true});
    let oldPage, newPage;
    try {
        const a = await browser.newContext();
        const b = await browser.newContext();
        oldPage = await a.newPage();
        newPage = await b.newPage();
        async function login(page) {
            await page.goto(server.origin + '/?login=1');
            await page.locator('#landing-login-username').fill(server.username('cashier'));
            await page.locator('#landing-login-password').fill(server.password);
            await page.getByRole('button', {name: 'Log in', exact: true}).click();
        }
        await oldPage.clock.install();
        await login(oldPage);
        await oldPage.getByRole('heading', {name: 'Authenticated: cashier'}).waitFor();
        const tab = await a.newPage();
        await tab.goto(server.origin + '/protected');
        assert.match(await tab.locator('body').innerText(), /Authenticated: cashier/, 'Same-browser tabs share the login');
        await login(newPage);
        await newPage.getByRole('alert').filter({hasText: 'Confirm this login'}).waitFor();
        await oldPage.clock.runFor(31000);
        await oldPage.getByRole('heading', {name: 'Is this you?'}).waitFor();
        for (const width of [1280, 390]) {
            await oldPage.setViewportSize({width, height: 844});
            const bounds = await oldPage.locator('.swal2-popup').boundingBox();
            assert(bounds.x >= 0 && bounds.x + bounds.width <= width, 'Approval fits at ' + width + 'px');
            if (process.env.CONCURRENT_LOGIN_OUTPUT) {
                await oldPage.screenshot({path: process.env.CONCURRENT_LOGIN_OUTPUT + '/cashier-approval-' + width + '.png', animations: 'disabled'});
            }
        }
        await oldPage.getByRole('button', {name: 'Reject login', exact: true}).click();
        await newPage.getByRole('alert').filter({hasText: 'expired or was rejected'}).waitFor();
        assert.equal((await a.request.get(server.origin + '/protected')).status(), 200);
        await login(newPage);
        await newPage.getByRole('alert').filter({hasText: 'Confirm this login'}).waitFor();
        await oldPage.clock.runFor(31000);
        await oldPage.getByRole('heading', {name: 'Is this you?'}).waitFor();
        await oldPage.getByRole('button', {name: 'Continue on other device', exact: true}).click();
        await newPage.getByRole('heading', {name: 'Authenticated: cashier'}).waitFor();
        await oldPage.waitForURL(/login=1/);
        assert.equal((await a.request.get(server.origin + '/protected', {maxRedirects: 0})).status(), 302);
        await b.setOffline(true);
        await fetch(server.origin + '/fixture-expire');
        await login(oldPage);
        await oldPage.getByRole('heading', {name: 'Authenticated: cashier'}).waitFor();
        await b.setOffline(false);
        await newPage.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await newPage.waitForURL(/login=1/);
        console.log('Cashier concurrent login browser: passed');
    } catch (error) {
        console.error(error);
        console.error(server.errors().split('\n').filter(line => /Fatal|Warning|error/i.test(line)).join('\n'));
        console.error('New device:', newPage?.url(), await newPage?.evaluate(() => document.querySelector('.landing-login-form')?.innerText));
        throw error;
    }
    finally { await browser.close(); await server.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
