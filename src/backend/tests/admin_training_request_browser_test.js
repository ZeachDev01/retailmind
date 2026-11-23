const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const net = require('node:net');
const { chromium } = require('playwright');
if (process.env.RUN_DB_TESTS !== '1') { console.log('Training request: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const db = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
function fixture(action, role, theme = false) {
    const result = spawnSync(php, ['src/backend/tests/support/' + (theme ? 'theme_fixture.php' : 'training_request_fixture.php'), action, db, role || 'admin'], {encoding:'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout ? JSON.parse(result.stdout) : null;
}
(async () => {
let browser, server;
try {
    const login = fixture('create', null, true);
    fixture('ready');
    const result = fixture('enqueue');
    assert.ok(result.id > 0, 'Administrator can queue a ready candidate: ' + JSON.stringify(result));
    const state = fixture('state');
    assert.equal(state.jobs[0].status, 'queued');
    assert.equal(Number(state.audit.at(-1).user_id), 1);
    assert.equal(Number(state.audit.at(-1).record_id), result.id);
    assert.match(fixture('enqueue').error, /already queued or running/);
    assert.match(fixture('system_enqueue').error, /already queued or running/);
    assert.equal(fixture('state').jobs.length, 1, 'manual and scheduled requests share overlap protection');
    fixture('clear_jobs');
    fixture('fail_audit');
    const auditFailure = fixture('enqueue');
    assert.match(auditFailure.error, /Fixture audit unavailable/);
    assert.equal(fixture('state').jobs.length, 0, 'audit failure rolls back the job');
    fixture('allow_audit');
    fixture('not_ready');
    assert.match(fixture('enqueue').error, /No Product is ready for training/);
    assert.equal(fixture('state').jobs.length, 0, 'empty readiness cannot queue work');
    fixture('ready');
    const race = ['enqueue','system_enqueue'].map(action => {
        const child = spawn(php,['src/backend/tests/support/training_request_fixture.php',action,db,'admin','barrier'],{stdio:['pipe','pipe','pipe']});
        let output='', error='';
        const ready = new Promise(resolve => child.stdout.on('data',chunk => { output += chunk; if (output.includes('ready\n')) resolve(); }));
        child.stderr.on('data',chunk => error += chunk);
        const done = new Promise((resolve,reject) => child.on('close',code => code === 0 ? resolve(JSON.parse(output.replace('ready\n',''))) : reject(new Error(error || output))));
        return {child,ready,done};
    });
    await Promise.all(race.map(item => item.ready));
    for (const item of race) item.child.stdin.end('go\n');
    const results = await Promise.all(race.map(item => item.done));
    assert.equal(results.filter(item => item.id).length,1,'concurrent manual/scheduled requests produce exactly one job');
    assert.equal(results.filter(item => /already queued or running/.test(item.error)).length,1);
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    const origin = 'http://127.0.0.1:' + port;
    server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env:{...process.env, RM_THEME_TEST_DATABASE:db, APP_DEBUG:'false'}, stdio:'ignore'});
    for (let n = 0; n < 100; n++) {
        try { if ((await fetch(origin)).ok) break; } catch {}
        await new Promise(resolve => setTimeout(resolve, 50));
    }
    browser = await chromium.launch({headless:true});
    for (const role of ['admin','super_admin','inventory_manager','cashier']) {
        fixture('ready');
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_' + role);
        await page.locator('#landing-login-password').fill(login.password);
        await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}), page.getByRole('button',{name:'Log in',exact:true}).click()]);
        const url = origin + '/components/system_administrator/ml_settings.php';
        const response = await page.goto(url);
        if (['cashier','inventory_manager'].includes(role)) {
            assert.equal(response.status(),403);
            assert.equal((await context.request.post(url,{form:{action:'train'}})).status(),403);
            assert.equal(fixture('state').jobs.length,0);
            await context.close(); continue;
        }
        assert.equal(response.status(),200);
        const form = await page.locator('form:has(button[value="train"])').evaluate(form => Object.fromEntries(new FormData(form)));
        form.action = 'train';
        assert.equal((await context.request.post(url,{form:{...form,csrf_token:''}})).status(),403);
        await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Train candidate model',exact:true}).click()]);
        assert.match(await page.locator('main').innerText(),/Training queued/);
        assert.equal(fixture('state').jobs.length,1);
        const job = fixture('state').jobs[0];
        assert.match(await page.locator('.ml-action-bar .ml-job-status').innerText(),new RegExp('#' + job.job_id + '.*Queued'));
        assert.equal(Number(job.requester_id),role === 'admin' ? 1 : 2);
        const duplicate = await context.request.post(url,{form});
        assert.match(await duplicate.text(),/already queued or running/);
        assert.equal(fixture('state').jobs.length,1);
        if (role === 'admin') {
            const worker = spawnSync(process.env.PYTHON_BINARY || 'python',['-c',
                'import sys,tempfile; from pathlib import Path; sys.path.insert(0,"src/backend/scripts"); import ml_worker; tmp=tempfile.TemporaryDirectory(); ml_worker.STORAGE_MODELS_DIR=Path(tmp.name); ml_worker.process_jobs(); tmp.cleanup()'],
                {env:{...process.env,DB_NAME:db,DB_HOST:'127.0.0.1',DATABASE_ENV:'local',APP_ENV:'test'},encoding:'utf8',timeout:180000});
            assert.equal(worker.status,0,worker.stderr || worker.stdout);
            const completed = fixture('history').find(item => Number(item.job_id) === Number(job.job_id));
            assert.equal(completed.status,'succeeded',JSON.stringify(completed));
            assert.deepEqual(completed.trained_product_ids,[1],'worker excludes Product without eligible history');
            assert.equal(completed.candidate_qualification,'failed','successful training can fail baseline quality');
            await page.reload({waitUntil:'domcontentloaded'});
            await page.locator('#runs-heading').click();
            const history = await page.getByRole('table',{name:'Recent training jobs',exact:true}).innerText();
            assert.match(history,/Completed/);
            assert.match(history,/Failed - approval separate/);
            const orphanId = fixture('orphan_succeeded');
            const orphan = fixture('history').find(item => Number(item.job_id) === orphanId);
            assert.equal(orphan.status,'failed','an older completed run cannot prove a different job succeeded');
            assert.equal(orphan.diagnostic_reference,'UNCOMMITTED_RESULTS');
            fixture('fail_latest');
            await page.reload({waitUntil:'domcontentloaded'});
            await page.locator('#runs-heading').click();
            assert.match(await page.locator('main').innerText(),/ERR-TEST-502/);
            assert.doesNotMatch(await page.locator('main').innerText(),/DO-NOT-EXPOSE|Traceback/);
        }
        fixture('clear_jobs'); fixture('not_ready');
        const denied = await context.request.post(url,{form});
        assert.match(await denied.text(),/No Product is ready for training/);
        assert.equal(fixture('state').jobs.length,0);
        await context.close();
    }
    console.log('Administrator training requests passed');
} finally {
    if (browser) await browser.close();
    if (server) server.kill();
    fixture('cleanup', null, true);
}
})().catch(error => { console.error(error); process.exitCode=1; });
