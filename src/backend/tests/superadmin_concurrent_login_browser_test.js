const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const startServer = require('./support/concurrent_login_server');

(async () => {
    const server = await startServer();
    let browser;
    try {
        browser = await chromium.launch({headless: true});
        const oldDevice = await browser.newContext();
        const newDevice = await browser.newContext();
        const oldPage = await oldDevice.newPage();
        const newPage = await newDevice.newPage();
        async function login(page) {
            await page.goto(server.origin + '/?login=1');
            await page.locator('#landing-login-username').fill(server.username('super_admin'));
            await page.locator('#landing-login-password').fill(server.password);
            await page.getByRole('button', {name: 'Log in', exact: true}).click();
            await page.getByRole('heading', {name: 'Authenticated: super_admin'}).waitFor();
        }
        await oldPage.clock.install();
        await login(oldPage);
        const sameSessionTab = await oldDevice.newPage();
        await sameSessionTab.goto(server.origin + '/protected');
        assert.match(await sameSessionTab.locator('body').innerText(), /Authenticated: super_admin/, 'Ordinary tabs share the login');
        assert.equal((await oldDevice.request.get(server.origin + '/protected')).status(), 200, 'Opening a tab does not revoke the shared session');
        await login(newPage);
        await oldPage.clock.runFor(31000);
        await oldPage.locator('.swal2-popup').waitFor({timeout: 10000});
        assert.match(await oldPage.locator('.swal2-html-container').innerText(), /change your password immediately/i);
        assert.equal(new URL(oldPage.url()).searchParams.get('session'), 'replaced');
        assert.equal((await newDevice.request.get(server.origin + '/protected')).status(), 200, 'New browser stays authenticated');
        for (const width of [1280, 390]) {
            await oldPage.setViewportSize({width, height: 844});
            const bounds = await oldPage.locator('.swal2-popup').boundingBox();
            assert(bounds.x >= 0 && bounds.x + bounds.width <= width, 'Warning fits at ' + width + 'px');
            if (process.env.CONCURRENT_LOGIN_OUTPUT) {
                await oldPage.screenshot({path: process.env.CONCURRENT_LOGIN_OUTPUT + '/warning-' + width + '.png'});
            }
        }
        await oldPage.getByRole('button', {name: 'Forgot password', exact: true}).click();
        await oldPage.getByRole('heading', {name: 'Reset Password', exact: true}).waitFor();
        await newPage.clock.install();
        await newDevice.setOffline(true);
        await login(oldPage);
        await newDevice.setOffline(false);
        await newPage.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await newPage.locator('.swal2-popup').waitFor();
        await newPage.getByRole('button', {name: 'Back to login', exact: true}).click();
        await newPage.locator('#landing-login-username').waitFor();
        assert.equal(await newPage.locator('.swal2-popup').count(), 0, 'Back to login dismisses the warning without repeating it');
        console.log('Super Administrator concurrent login browser: passed');
    } catch (error) { console.error(server.errors()); throw error; }
    finally { await browser?.close(); await server.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
