const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-picture-visibility-'));
    const filename = 'a'.repeat(64) + '.png';
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    fs.writeFileSync(path.join(directory, filename), png);
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    const origin = 'http://127.0.0.1:' + port;
    const server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, 'src/backend/tests/profile_picture_visibility_router.php'], {
        env: {...process.env, RM_PROFILE_VISIBILITY_DIR: directory, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')}, stdio: ['ignore', 'ignore', 'pipe']
    });
    let errors = '';
    server.stderr.on('data', data => { errors += data; });
    let browser;
    try {
        for (let attempt = 0; attempt < 100; attempt++) {
            try { await fetch(origin); break; } catch {}
            if (attempt === 99) throw new Error(errors);
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        for (const actor of [0, 1, 2, 3, 4, 6]) {
            for (let target = 1; target <= 8; target++) {
                const allowed = actor > 0 && actor !== 6 && (actor === target
                    || (actor === 3 && [1, 2].includes(target))
                    || (actor === 4 && [1, 2, 3, 7].includes(target)));
                const response = await fetch(`${origin}/profile_image.php?actor=${actor}&user_id=${target}&v=old`);
                assert.equal(response.status, actor === 0 ? 401 : allowed ? 200 : 403, `read actor ${actor} -> ${target}`);
                assert.equal(response.headers.get('cache-control'), 'private, no-store, max-age=0');
                assert.equal(response.headers.get('vary'), 'Cookie');
                const bytes = Buffer.from(await response.arrayBuffer());
                if (allowed) {
                    assert.deepEqual(bytes, png);
                    assert.equal(response.headers.get('content-type'), 'image/png');
                    assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
                } else assert.equal(bytes.length, 0, 'Denied read sends no picture bytes');
            }
        }
        for (const id of ['0', '-1', '999', '../../storage/picture.png', '1%20OR%201=1']) {
            const response = await fetch(`${origin}/profile_image.php?actor=4&user_id=${id}`);
            assert.equal(response.status, 404, `Invalid/unknown target ${id}`);
        }
        const removed = await fetch(`${origin}/profile_image.php?actor=1&user_id=1&removed=1&v=old`);
        assert.equal(removed.status, 404, 'Stale image URL cannot serve removed association, even while bytes exist');
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        const modal = fs.readFileSync('src/frontend/components/user_manager/modals/manage_user_modal.php', 'utf8');
        const hiddenAction = modal.match(/<input type="hidden" name="action" value="update">/)[0];
        const removeButton = modal.match(/<button[^>]*id="drawerRemoveProfileImage"[^>]*>Remove picture<\/button>/)[0];
        await page.goto(origin + '?actor=3');
        await page.setContent(`<form method="POST" action="${origin}/manage?actor=3">${hiddenAction}
            <input name="csrf_token" value="test-token"><input name="user_id" value="1">
            <input name="username" value="unsaved-edit"><input name="first_name" required value="">
            <input name="status" value="disabled">${removeButton}</form>`);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Remove picture'}).click()]);
        const saved = JSON.parse(await page.locator('body').innerText());
        assert.equal(saved.action, 'remove_profile_image', 'Browser submit button overrides hidden update action');
        assert(saved.message.includes('Initials'));
        assert.equal(saved.account.profile_image, null);
        assert.equal(saved.account.username, 'test', 'Removal ignores unrelated unsaved edits');
        assert.equal(saved.account.full_name, 'Test Person');
        assert.equal(saved.account.status, 'active');
        assert(!fs.existsSync(path.join(directory, filename)), 'Management HTTP submission deletes managed picture');
        console.log('Profile picture visibility HTTP tests: passed');
    } catch (error) {
        console.error(errors);
        throw error;
    } finally {
        if (browser) await browser.close();
        server.kill();
        await new Promise(resolve => server.once('exit', resolve));
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
