const assert = require('node:assert/strict');
const startServer = require('./support/concurrent_login_server');

(async () => {
    const server = await startServer();
    try {
        async function login(role = 'super_admin', password = server.password) {
            const username = server.username(role);
            const landing = await fetch(server.origin + '/?login=1');
            let cookie = landing.headers.get('set-cookie').split(';')[0];
            const html = await landing.text();
            const csrf = html.match(/name="csrf_token" value="([^"]+)"/)[1];
            const response = await fetch(server.origin + '/', {method: 'POST', redirect: 'manual', headers: {Cookie: cookie}, body: new URLSearchParams({username,password,csrf_token:csrf})});
            if (response.headers.get('set-cookie')) cookie = response.headers.get('set-cookie').split(';')[0];
            return cookie;
        }
        const request = (cookie, url = '/protected') => fetch(server.origin + url, {headers: {Cookie: cookie}, redirect: 'manual'});
        const first = await login();
        assert.equal((await request(first)).status, 200, 'First superadmin can use protected requests');
        const second = await login();
        assert.equal((await request(second)).status, 200, 'New superadmin session stays authenticated');
        assert.equal((await request(first)).status, 302, 'New login rejects the previous superadmin session');
        const oldPollingSession = await login();
        const latest = await login();
        const poll = await request(oldPollingSession, '/components/auth/session_activity.php');
        assert.equal(poll.status, 401, 'Old session receives an unauthorized polling response instead of a redirect');
        assert.equal((await poll.json()).reason, 'session_replaced', 'Polling identifies a replacement login');
        assert.equal((await request(latest)).status, 200, 'Polling the old session cannot revoke the new login');
        await login('super_admin', 'wrong-password');
        assert.equal((await request(latest)).status, 200, 'Failed login cannot replace an authenticated session');
        const adminFirst = await login('admin');
        const adminSecond = await login('admin');
        assert.equal((await request(adminFirst)).status, 200, 'Ordinary Administrator keeps concurrent login access');
        assert.equal((await request(adminSecond)).status, 200, 'Second ordinary Administrator session stays authenticated');
        const navigationSession = await login();
        await login();
        const navigation = await request(navigationSession);
        assert.equal(navigation.headers.get('location'), '/?login=1&session=replaced', 'Protected navigation also carries the replacement warning');
        const noAccess = await request(navigationSession, '/components/auth/session_activity.php');
        assert.equal(noAccess.status, 401, 'Rejected session cannot regain access by polling');
        console.log('Super Administrator concurrent login HTTP: passed');
    } catch (error) { console.error(server.errors()); throw error; }
    finally { await server.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
