'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const template = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
        require 'src/backend/bootstrap/app.php';
        function csrf_field() { return ''; }
        $actorRole = 'admin';
        $roles = [];
        $createFormValues = ['username' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'role_ids' => []];
        include 'src/frontend/components/user_manager/modals/add_user_modal.php';
    `], {cwd: root, encoding: 'utf8'});
    assert.equal(template.status, 0, template.stderr);
    const source = fs.readFileSync(path.join(root, 'src/frontend/components/user_manager/user_manager.php'), 'utf8');
    const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace(/<\?=[\s\S]*?\?>/g, 'false').replace(/<\?php[\s\S]*?\?>/g, '');
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(`<body class="manage-users-page">${template.stdout}<script>${script}</script></body>`);
        await page.addStyleTag({path: path.join(root, 'src/frontend/assets/css/style.css')});
        const input = page.getByLabel('Profile Picture', {exact: false});
        const remove = page.getByRole('button', {name: 'Remove picture', exact: true});
        const picture = {name: 'picture.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64')};
        assert.equal(await remove.isVisible(), false, 'No remove action before choosing a picture');
        for (const width of [1280, 390]) {
            await page.setViewportSize({width, height: 900});
            await input.setInputFiles(picture);
            assert.equal(await remove.isVisible(), true, 'Chosen picture has a remove action');
            await remove.focus();
            await page.keyboard.press('Enter');
            assert.equal(await input.evaluate(node => node.files.length), 0, 'Removing clears the upload');
            assert.equal(await remove.isVisible(), false, 'Remove action hides after clearing');
            assert.equal(await input.evaluate(node => node === document.activeElement), true, 'Focus returns to the picker');
            assert.deepEqual(await page.locator('#createUserForm').evaluate(form => {
                const file = new FormData(form).get('profile_image');
                return {name: file.name, size: file.size};
            }), {name: '', size: 0}, 'Submission contains no chosen picture');
        }
        assert.deepEqual(errors, [], 'Account form has no browser errors');
        console.log('Add User picture removal: passed (clear, reselect, keyboard, desktop/mobile, submission)');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
