const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const {chromium} = require('playwright');
const source = fs.readFileSync('src/frontend/assets/js/cart-workspace.js', 'utf8');
(async () => {
    const requests = [];
    let locked = false;
    const server = http.createServer((request, response) => {
        if (request.method === 'POST') {
            let body = '';
            request.on('data', part => body += part);
            request.on('end', () => {
                const input = JSON.parse(body); requests.push(input);
                response.setHeader('Content-Type', 'application/json');
                if (locked) { response.writeHead(409); response.end(JSON.stringify({success:false,message:'Register locked'})); return; }
                response.end(JSON.stringify({success:true, review:{cart:{1:{qty:2,price:30}}, changes:['Requested 5; available 2. Price 10 to 30.']}}));
            }); return;
        }
        response.end(`<script>${source}</script><script>
            const context = {cashier:1, shift:11, workspace:'cashier', locked:false, endpoint:'/held', csrf:'test', reasons:{other:'Other'}};
            window.answers = []; window.messages = [];
            window.Swal = {fire: async () => answers.shift()};
            window.RetailMindUI = {confirm: async () => answers.shift(), alert: async value => messages.push(value)};
            window.workspace = new CartWorkspace(context); workspace.bindNavigation();
        </script><a href='/auth/logout.php'>Logout</a><form action='/auth/workspace.php'><button>Switch workspace</button></form>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({headless:true});
    try {
        const page = await browser.newPage(); const url = `http://127.0.0.1:${server.address().port}`;
        await page.goto(url);
        await page.evaluate(() => workspace.save({1:{qty:5,price:10,name:'Item'}}, 0));
        await page.reload();
        assert.equal(await page.evaluate(() => workspace.state.cart[1].qty), 5, 'same Cashier/shift reload recovers requested quantity');
        await page.evaluate(() => { answers.push(false); });
        assert.equal(await page.evaluate(() => workspace.review(workspace.state.cart)), null, 'declining review retains unresolved requested work');
        assert.equal(await page.evaluate(() => workspace.state.cart[1].qty), 5);
        await page.evaluate(() => answers.push({isConfirmed:false,isDenied:false}));
        await page.click('a'); await page.waitForTimeout(50);
        assert.equal(page.url(), url + '/', 'cancel logout keeps work and page');
        await page.evaluate(() => answers.push({isConfirmed:true}, false));
        assert.equal(await page.evaluate(() => workspace.resolveWork()), false, 'declining hold review preserves requested work');
        locked = true;
        await page.evaluate(() => answers.push({isConfirmed:true}));
        await page.click('a'); await page.waitForFunction(() => messages.length > 0);
        assert.equal(await page.evaluate(() => workspace.state.cart[1].qty), 5, 'lock-race refusal keeps cart');
        locked = false;
        await page.evaluate(() => answers.push({isDenied:true}, {isConfirmed:true,value:'other'}, {isConfirmed:true,value:'Customer left'}));
        assert.equal(await page.evaluate(() => workspace.resolveWork()), true);
        assert.equal(requests.at(-1).action, 'discard_cart');
        assert.equal(requests.at(-1).cashier_context, 1);
        assert.equal(requests.at(-1).shift_context, 11);
        assert.equal(requests.at(-1).discard_note, 'Customer left');
        assert.equal(await page.evaluate(() => workspace.state), null);
        await page.evaluate(() => {workspace.save({1:{qty:5}}, 42); answers.push({isConfirmed:true});});
        const count = requests.length;
        assert.equal(await page.evaluate(() => workspace.resolveWork()), true);
        assert.equal(requests.length, count, 'already resumed work stays held without creating duplicate hold');
        for (const override of [{cashier:2}, {shift:12}, {workspace:'admin'}, {shift:0}]) {
            await page.evaluate(override => { workspace = new CartWorkspace(context); workspace.save({1:{qty:5}},42); workspace = new CartWorkspace({...context,...override}); }, override);
            assert.equal(await page.evaluate(() => workspace.state), null, 'identity, shift or workspace changes start empty');
        }
        await page.evaluate(() => {workspace = new CartWorkspace(context); workspace.save({1:{qty:5}},42); workspace = new CartWorkspace({...context,locked:true}); workspace.save({1:{qty:1}});});
        assert.equal(await page.evaluate(() => workspace.state.cart[1].qty), 5, 'locked context cannot mutate saved work');
        assert.equal(await page.evaluate(async () => {
            let release;
            const pending = workspace.exclusive(() => new Promise(resolve => release = resolve));
            let refused = false;
            try { await workspace.resolveWork(); } catch { refused = true; }
            release(); await pending; return refused;
        }), true, 'concurrent cart decision refuses duplicate hold/navigation');
        await page.evaluate(() => {workspace = new CartWorkspace(context); localStorage.setItem('retailmind.checkout.1','pending');});
        assert.equal(await page.evaluate(() => workspace.resolveWork()), false, 'pending payment recovery blocks discard or new hold');
        await page.route('**/held', route => route.abort());
        const message = await page.evaluate(async () => {try {await workspace.request('review', {cart:{1:{qty:1}}});} catch(error) {return error.message;}});
        assert(message.includes('Check your connection and try again.') && !message.includes('fetch'), 'Network failures retain calm aligned fallback');
        console.log('Cart workspace browser: passed (reload, identity/shift/workspace isolation, declined review, leave decisions, audited discard request, locked write/race, resumed preservation, payment recovery)');
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => {console.error(error); process.exitCode = 1;});
