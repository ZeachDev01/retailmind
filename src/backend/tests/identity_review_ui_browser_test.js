const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

// Render the real template with fixture identities; never read or restore a database.
const frontend = path.resolve(__dirname, '../../frontend');
const file = path.join(frontend, 'components/system_administrator/identity_reconciliation.php');
const source = fs.readFileSync(file, 'utf8');
const template = source.slice(source.indexOf('<header class="topbar'), source.indexOf('    </main>'));
function render(state) {
    const php = `
        function app_url($path) { return '/' . $path; }
        function csrf_field() { return ''; }
        $escape = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $backupPage = 'system_administrator/backup_restore.php';
        $message = ''; $isSuccessMessage = false; $recordedApproval = $approval = null;
        $editing = false; $packageHash = 'fixture'; $decisions = [];
        $person = ['user_id'=>2, 'display_name'=>'Alex Rivera', 'username'=>'alex',
            'email'=>'alex@example.com', 'status'=>'active', 'roles'=>['admin'],
            'ordinary_roles'=>['admin'], 'historical'=>false];
        $review = ['ready'=>${state === 'ready' ? 'true' : 'false'}, 'source_users'=>[$person, array_replace($person,
            ['user_id'=>3, 'display_name'=>'Historical operator', 'historical'=>true])],
            'destination_users'=>[$person], 'conflicts'=>[['source_id'=>2,'destination_id'=>2,'kind'=>'username']],
            'active_administrators'=>1, 'preservation_closure'=>['counts'=>array_fill_keys([
            'recovery_accounts','platform_settings','platform_attention_settings','ml_settings','activity_log_evidence',
            'login_attempts','password_reset_tokens','notification_preferences','backup_history','email_delivery_log'], 1)],
            'resulting_access'=>[['user_id'=>2,'username'=>'alex','classification'=>'destination_linked','usable_access'=>true,'roles'=>['admin']]]];
        $draft = ['filename'=>'test-store.rmpkg','token'=>'fixture'];
        $package = ['manifest'=>['snapshot_at'=>'2026-11-03 00:00:00']];
        $restorer = new class { function supportedUploadBytes() { return 12 * 1048576; } };
        if ('${state}' === 'initial') { $review = $draft = null; unset($package); }
        if ('${state}' === 'retained') $decisions = [2=>['action'=>'distinct','username'=>'newalex','email'=>'']];
        eval('?>' . base64_decode('${Buffer.from(template).toString('base64')}'));
    `;
    const result = spawnSync(process.env.PHP_BINARY || 'php', [], {input:'<?php ' + php, encoding:'utf8'});
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stderr, '');
    return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
        ${['style','theme','backup','backup_restore'].map(name=>`<link rel="stylesheet" href="/assets/css/${name}.css">`).join('')}
        </head><body class="database-backup-page database-restore-page identity-reconciliation-page">
        <main class="main-content" style="margin-left:0">${result.stdout}</main>
        <script src="/assets/js/backup_review.js"></script></body></html>`;
}
(async () => {
    const pages = Object.fromEntries(['initial','review','ready','retained'].map(state=>[state,render(state)]));
    const server = http.createServer(async (req,res)=> {
        if (req.method === 'POST') {
            for await (const chunk of req) { /* consume fixture submission */ }
            res.end(pages.review);
            return;
        }
        if (req.url.startsWith('/assets/')) {
            res.setHeader('Content-Type', req.url.endsWith('.js') ? 'text/javascript' : 'text/css');
            res.end(fs.readFileSync(path.join(frontend, req.url)));
        } else res.end(pages[req.url.slice(1)] || pages.review);
    });
    let browser;
    try {
        await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
        const origin = 'http://127.0.0.1:' + server.address().port;
        browser = await chromium.launch({headless:true});
        const page = await browser.newPage();
        await page.goto(origin + '/initial');
        await page.locator('[name="backup_file"]').setInputFiles({name:'fixture.rmpkg',mimeType:'application/octet-stream',buffer:Buffer.from('fixture')});
        await page.getByRole('button',{name:'Review identities',exact:true}).click();
        assert.equal(await page.getByRole('dialog').locator('.restore-introduction').count(), 0);
        await page.locator('[name="restore_password"]').fill('fixture-password');
        await page.getByRole('dialog').getByRole('button',{name:'Review identities',exact:true}).click();
        await page.locator('[data-account-action]').waitFor();
        const action = page.locator('[data-account-action]');
        assert.equal(await page.locator('#destination-2').isVisible(), false);
        assert.equal(await page.locator('#username-2').isVisible(), false);
        await action.selectOption('link');
        assert.equal(await page.locator('#destination-2').isVisible(), true);
        assert.equal(await page.locator('#destination-2').evaluate(el=>el.required), true);
        await page.locator('#destination-2').selectOption('2');
        await action.selectOption('distinct');
        assert.equal(await page.locator('#destination-2').isVisible(), false);
        assert.equal(await page.locator('#username-2').isVisible(), true);
        assert.equal(await page.locator('#username-2').evaluate(el=>el.required), true);
        await page.locator('#username-2').fill('newalex');
        const fields = await page.locator('form').evaluate(form=>Object.fromEntries(new FormData(form)));
        assert.equal(fields['people[2][destination_id]'], undefined);
        assert.equal(fields['people[2][email]'], '');
        assert.equal(fields['people[3][action]'], 'historical');
        await action.selectOption('link');
        assert.equal(await page.locator('#destination-2').inputValue(), '2');
        assert.equal(await page.locator('#username-2').isDisabled(), true);
        await page.goto(origin + '/retained');
        assert.equal(await action.inputValue(), 'distinct');
        assert.equal(await page.locator('#username-2').inputValue(), 'newalex');
        const captures = path.resolve('.impeccable/review/identity-review');
        fs.mkdirSync(captures,{recursive:true});
        for (const width of [1440,390]) {
            await page.setViewportSize({width,height:900});
            for (const state of ['initial','review','ready']) {
                await page.goto(origin + '/' + state);
                assert(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), state + ': viewport overflow');
                await page.screenshot({path:path.join(captures,`${state}-${width}.png`),fullPage:true});
                if (state === 'ready') {
                    assert.equal(await page.locator('[data-start-restore]').isVisible(), true);
                    assert.equal(await page.locator('[name="confirm_replacement"]').evaluate(el=>el.required), true);
                    assert.equal(await action.isVisible(), false);
                    assert.equal(await page.getByText('Existing server account',{exact:true}).isVisible(), true);
                }
            }
            await page.goto(origin + '/review');
            for (const choice of ['link','distinct']) {
                await action.selectOption(choice);
                await page.screenshot({path:path.join(captures,`${choice}-${width}.png`),fullPage:true});
            }
            await page.evaluate(()=>document.documentElement.dataset.theme='dark');
            await page.waitForFunction(()=>getComputedStyle(document.querySelector('.btn-secondary')).backgroundColor === 'rgb(21, 34, 56)');
            await page.screenshot({path:path.join(captures,`dark-${width}.png`),fullPage:true});
        }
        const noScript = await browser.newPage({javaScriptEnabled:false});
        await noScript.goto(origin + '/review');
        assert.equal(await noScript.locator('#destination-2').isVisible(), true);
        assert.equal(await noScript.locator('#username-2').isVisible(), true);
        console.log('Identity review UI: conditional fields, validation, retained choices, historical accounts and responsive steps passed.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve=>server.close(resolve));
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
