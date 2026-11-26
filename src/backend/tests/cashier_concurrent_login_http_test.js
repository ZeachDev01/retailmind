const assert = require('node:assert/strict');
const startServer = require('./support/concurrent_login_server');

(async () => {
    const server = await startServer();
    try {
        async function device() {
            let cookie = '';
            const request = async (url, options = {}) => {
                const response = await fetch(server.origin + url, {redirect: 'manual', ...options,
                    headers: {Cookie: cookie, ...options.headers}});
                if (response.headers.get('set-cookie')) cookie = response.headers.get('set-cookie').split(';')[0];
                return response;
            };
            const landing = await request('/?login=1');
            const csrf = (await landing.text()).match(/name="csrf_token" value="([^"]+)"/)[1];
            return {request, csrf,
                async login(role = 'cashier', password = server.password) {
                    return request('/', {method: 'POST', body: new URLSearchParams({username: server.username(role), password, csrf_token: csrf})});
                },
                post(action, extra = {}) { return request('/components/auth/cashier_login.php', {method: 'POST',
                    body: new URLSearchParams({action, csrf_token: csrf, ...extra})}); },
                async poll() { return (await request('/components/auth/session_activity.php')).json(); },
            };
        }
        const a = await device();
        await a.login();
        assert.equal((await a.request('/protected')).status, 200);
        const b = await device();
        await b.login();
        assert.equal((await b.request('/protected')).status, 302, 'Pending device has no workspace access');
        assert.equal((await (await b.post('status')).json()).state, 'waiting');
        const pending = (await a.poll()).pendingLogin;
        assert.ok(pending);
        assert.equal((await b.post('approve', {request: pending})).status, 401, 'Waiting device cannot approve');
        assert.equal((await a.post('approve', {request: pending, csrf_token: 'wrong'})).status, 403, 'Approval needs CSRF');
        assert.equal((await a.post('reject', {request: pending})).status, 200);
        assert.equal((await (await b.post('status')).json()).state, 'expired');
        await b.login();
        const freshRequest = (await a.poll()).pendingLogin;
        assert.equal((await a.post('approve', {request: freshRequest})).status, 200);
        assert.equal((await a.request('/protected')).status, 302, 'Approved transfer ends old login');
        assert.equal((await (await b.post('status')).json()).state, 'ready');
        assert.equal((await b.request('/protected')).status, 200, 'Approved browser authenticated');
        const c = await device();
        await c.login('cashier', 'incorrect-password');
        assert.equal((await c.post('status')).status, 400, 'Wrong password creates no pending login');
        assert.equal((await b.poll()).pendingLogin, null);
        await fetch(server.origin + '/fixture-expire');
        await c.login();
        assert.equal((await c.request('/protected')).status, 200, 'Broken old device needs no confirmation after expiry');
        assert.equal((await b.request('/protected')).status, 302, 'Expired old browser stays rejected');
        await c.request('/components/auth/logout.php');
        const d = await device();
        await d.login();
        assert.equal((await d.request('/protected')).status, 200, 'Logout releases ownership');
        const multiA = await device();
        const multiB = await device();
        await multiA.login('inventory_manager');
        assert.match(await (await multiA.request('/protected')).text(), /inventory_manager/);
        await multiB.login('inventory_manager');
        assert.equal((await multiB.request('/protected')).status, 302, 'Cashier assignment limits multi-role account in Inventory workspace');
        assert.ok((await multiA.poll()).pendingLogin);
        console.log('Cashier concurrent login HTTP: passed');
    } catch (error) { console.error(server.errors()); throw error; }
    finally { await server.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
