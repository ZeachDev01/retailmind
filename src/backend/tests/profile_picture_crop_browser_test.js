const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-profile-crop-'));
    const net = require('node:net');
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    const origin = 'http://127.0.0.1:' + port;
    const server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', '.', 'src/backend/tests/profile_picture_crop_router.php'], {
        env: {...process.env, RM_PROFILE_CROP_TEST_DIR: directory}, stdio: ['ignore', 'ignore', 'pipe']
    });
    let errors = '';
    server.stderr.on('data', chunk => { errors += chunk; });
    let browser;
    try {
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error(errors);
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.addInitScript(() => {
            const decode = window.createImageBitmap;
            window.profileDecodeCalls = 0;
            window.createImageBitmap = (...args) => { window.profileDecodeCalls++; return decode(...args); };
        });
        await page.goto(origin);
        const png = Buffer.from(await page.evaluate(() => {
            const canvas = document.createElement('canvas'); canvas.width = 400; canvas.height = 200;
            const context = canvas.getContext('2d');
            context.fillStyle = '#ff0000'; context.fillRect(0, 0, 200, 200);
            context.fillStyle = '#0000ff'; context.fillRect(200, 0, 200, 200);
            return canvas.toDataURL('image/png').split(',')[1];
        }), 'base64');
        const upload = async () => {
            await page.locator('#profile_image').setInputFiles({name: 'two-colors.png', mimeType: 'image/png', buffer: png});
            await page.waitForFunction(() => !document.getElementById('profile-picture-save').disabled);
        };
        const state = role => fetch(origin + '/state?role=' + role).then(response => response.json());
        for (const role of ['cashier', 'inventory_manager', 'admin', 'super_admin']) {
            await page.goto(origin + '/profile?role=' + role);
            await upload();
            await page.getByLabel('Horizontal position', {exact: true}).fill('100');
            await page.getByLabel('Zoom', {exact: true}).focus();
            await page.keyboard.press('ArrowRight');
            const crop = await page.locator('#profile-crop-size').inputValue();
            assert(Number(crop) < 200, 'Keyboard zoom shrinks crop');
            await Promise.all([page.waitForNavigation({waitUntil: 'load'}), page.getByRole('button', {name: 'Save picture'}).click()]);
            await page.waitForSelector('.alert.tag-success');
            const saved = await state(role);
            assert.equal(saved.width, 512); assert.equal(saved.height, 512);
            assert.equal(saved.center, 0x0000ff, 'Persisted pixels match repositioned right-hand crop');
            assert.equal(await page.locator('.sidebar-avatar .profile-avatar-image').count(), 1, 'Account menu picture refreshed');
            assert.equal(await page.locator('.profile-picture-avatar .profile-avatar-image').count(), 1, 'Profile picture preview refreshed');
            await upload();
            await page.getByRole('button', {name: 'Cancel', exact: true}).click();
            assert.equal(await page.locator('#profile_image').inputValue(), '');
            assert(await page.locator('#profile-crop-editor').isHidden());
            assert.equal((await state(role)).filename, saved.filename, 'Cancel leaves saved picture untouched');
            await upload();
            await page.getByLabel('Horizontal position', {exact: true}).fill('0');
            await Promise.all([page.waitForNavigation({waitUntil: 'load'}), page.getByRole('button', {name: 'Save picture'}).click()]);
            await page.waitForSelector('.alert.tag-success');
            const replaced = await state(role);
            assert.equal(replaced.center, 0xff0000, 'Replacement persists left-hand crop');
            assert.notEqual(replaced.filename, saved.filename);
            assert(!fs.existsSync(path.join(directory, 'images', saved.filename)), 'Superseded image removed');

            for (const failure of ['persistence', 'commit', 'delete', 'storage']) {
                await page.goto(origin + '/profile?role=' + role + '&failure=' + failure);
                await upload();
                await Promise.all([page.waitForNavigation({waitUntil: 'load'}), page.getByRole('button', {name: 'Save picture'}).click()]);
                await page.waitForSelector('.alert.tag-warning');
                assert.equal((await state(role)).filename, replaced.filename, failure + ': previous picture preserved');
            }
            for (const cropFields of [{}, {'crop[x]': '-1', 'crop[y]': '0', 'crop[size]': '200'},
                {'crop[x]': '201', 'crop[y]': '0', 'crop[size]': '200'},
                {'crop[x]': '0.5', 'crop[y]': '0', 'crop[size]': '200'},
                {'crop[x]': '0', 'crop[y]': '0', 'crop[size]': '0'}]) {
                const data = new FormData();
                data.set('action', 'replace_profile_image'); data.set('csrf_token', 'crop-test-token');
                data.set('profile_image', new Blob([png], {type: 'image/png'}), 'original.png');
                Object.entries(cropFields).forEach(([key, value]) => data.set(key, value));
                const response = await fetch(origin + '/profile?role=' + role, {method: 'POST', body: data});
                assert.match(await response.text(), /tag-warning/);
                assert.equal((await state(role)).filename, replaced.filename, 'Invalid raw crop cannot bypass server');
            }
        }
        const saved = await state('admin');
        assert.equal(saved.files, 4, 'Only four current pictures; no abandoned or failed files');
        for (const buffer of [Buffer.from('not an image'), Buffer.alloc(5242881),
            Buffer.from('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', 'base64')]) {
            const data = new FormData();
            data.set('action', 'replace_profile_image'); data.set('csrf_token', 'crop-test-token');
            data.set('profile_image', new Blob([buffer], {type: 'image/png'}), 'spoofed.png');
            Object.entries({'crop[x]': '0', 'crop[y]': '0', 'crop[size]': '1'}).forEach(([key, value]) => data.set(key, value));
            const response = await fetch(origin + '/profile?role=admin', {method: 'POST', body: data});
            assert.match(await response.text(), /tag-warning/);
            assert.deepEqual(await state('admin'), saved, 'Original policy enforced before accepting tiny crop');
        }
        // Camera orientation metadata must not change preview coordinates relative to GD.
        const formats = await page.evaluate(() => {
            const canvas = document.createElement('canvas'); canvas.width = 400; canvas.height = 200;
            const context = canvas.getContext('2d');
            context.fillStyle = '#ff0000'; context.fillRect(0, 0, 200, 200);
            context.fillStyle = '#0000ff'; context.fillRect(200, 0, 200, 200);
            return ['image/jpeg', 'image/png', 'image/webp'].map(type => [type, canvas.toDataURL(type).split(',')[1]]);
        });
        // Oversized headers must be rejected without allocating a decoded raster.
        for (const [mimeType, encoded] of formats) {
            const buffer = Buffer.from(encoded, 'base64');
            if (mimeType === 'image/png') {
                buffer.writeUInt32BE(8001, 16);
            } else if (mimeType === 'image/jpeg') {
                let offset = 2;
                while (buffer[offset + 1] !== 0xc0 && buffer[offset + 1] !== 0xc2) offset += 2 + buffer.readUInt16BE(offset + 2);
                buffer.writeUInt16BE(8001, offset + 7);
            } else {
                const type = buffer.toString('ascii', 12, 16);
                if (type === 'VP8X') buffer.writeUIntLE(8000, 24, 3);
                else if (type === 'VP8 ') buffer.writeUInt16LE(8001, 26);
                else buffer.writeUInt32LE(8000, 21);
            }
            await page.goto(origin + '/profile?role=admin');
            await page.locator('#profile_image').setInputFiles({name: 'oversized-header', mimeType, buffer});
            await page.waitForFunction(() => document.getElementById('profile-crop-error').textContent !== '');
            assert.equal(await page.evaluate(() => window.profileDecodeCalls), 0, mimeType + ': reject dimensions before decode');
            assert(await page.getByRole('button', {name: 'Save picture'}).isDisabled());
        }
        for (const [type, data] of [
            ['VP8X', Buffer.from([0, 0, 0, 0, 0x40, 0x1f, 0, 0, 0, 0])],
            ['VP8 ', Buffer.from([0, 0, 0, 0x9d, 1, 0x2a, 0x41, 0x1f, 1, 0])],
            ['VP8L', Buffer.from([0x2f, 0x40, 0x1f, 0, 0])]
        ]) {
            const header = Buffer.alloc(20);
            header.write('RIFF'); header.writeUInt32LE(12 + data.length, 4); header.write('WEBP', 8);
            header.write(type, 12); header.writeUInt32LE(data.length, 16);
            const buffer = Buffer.concat([header, data, data.length % 2 ? Buffer.alloc(1) : Buffer.alloc(0)]);
            await page.goto(origin + '/profile?role=admin');
            await page.locator('#profile_image').setInputFiles({name: 'oversized.webp', mimeType: 'image/webp', buffer});
            await page.waitForFunction(() => document.getElementById('profile-crop-error').textContent !== '');
            assert.equal(await page.evaluate(() => window.profileDecodeCalls), 0, type + ': reject dimensions before decode');
        }
        const excessivePixels = Buffer.from(png);
        excessivePixels.writeUInt32BE(7000, 16); excessivePixels.writeUInt32BE(6000, 20);
        for (const buffer of [excessivePixels, png.subarray(0, 12)]) {
            await page.goto(origin + '/profile?role=admin');
            await page.locator('#profile_image').setInputFiles({name: 'invalid.png', mimeType: 'image/png', buffer});
            await page.waitForFunction(() => document.getElementById('profile-crop-error').textContent !== '');
            assert.equal(await page.evaluate(() => window.profileDecodeCalls), 0, 'Excessive pixels and truncated headers reject before decode');
        }
        const tiff = Buffer.from('4d4d002a00000008000101120003000000010006000000000000', 'hex');
        const crc32 = bytes => {
            let crc = 0xffffffff;
            for (const byte of bytes) {
                crc ^= byte;
                for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
            }
            return (crc ^ 0xffffffff) >>> 0;
        };
        for (const [mimeType, encoded] of formats) {
            let buffer = Buffer.from(encoded, 'base64');
            if (mimeType === 'image/jpeg') {
                const exif = Buffer.concat([Buffer.from('Exif\0\0'), tiff]);
                const marker = Buffer.from([255, 225, 0, exif.length + 2]);
                buffer = Buffer.concat([buffer.subarray(0, 2), marker, exif, buffer.subarray(2)]);
            } else if (mimeType === 'image/png') {
                const data = Buffer.concat([Buffer.from('eXIf'), tiff]);
                const length = Buffer.alloc(4); length.writeUInt32BE(tiff.length);
                const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(data));
                buffer = Buffer.concat([buffer.subarray(0, 33), length, data, crc, buffer.subarray(33)]);
            } else {
                const chunk = (name, data) => {
                    const length = Buffer.alloc(4); length.writeUInt32LE(data.length);
                    return Buffer.concat([Buffer.from(name), length, data, data.length % 2 ? Buffer.alloc(1) : Buffer.alloc(0)]);
                };
                const extended = Buffer.alloc(10); extended[0] = 8;
                extended.writeUIntLE(399, 4, 3); extended.writeUIntLE(199, 7, 3);
                const hasExtended = buffer.toString('ascii', 12, 16) === 'VP8X';
                if (hasExtended) buffer[20] |= 8;
                const payload = Buffer.concat([hasExtended ? Buffer.alloc(0) : chunk('VP8X', extended), buffer.subarray(12), chunk('EXIF', tiff)]);
                const header = Buffer.from(buffer.subarray(0, 12)); header.writeUInt32LE(payload.length + 4, 4);
                buffer = Buffer.concat([header, payload]);
            }
            await page.goto(origin + '/profile?role=admin');
            await page.locator('#profile_image').setInputFiles({name: 'oriented-image', mimeType, buffer});
            await page.waitForFunction(() => !document.getElementById('profile-picture-save').disabled);
            await page.getByLabel('Horizontal position', {exact: true}).fill('100');
            assert.equal(await page.locator('#profile-crop-x').inputValue(), '200', mimeType + ': preview uses raw raster bounds');
            await Promise.all([page.waitForNavigation({waitUntil: 'load'}), page.getByRole('button', {name: 'Save picture'}).click()]);
            await page.waitForSelector('.alert.tag-success');
            const color = (await state('admin')).center;
            assert((color & 255) > 240 && (color >>> 16) < 15, mimeType + ': oriented preview persists selected blue pixels');
        }
        await page.goto(origin + '/profile?role=admin');
        const portrait = Buffer.from(await page.evaluate(() => {
            const canvas = document.createElement('canvas'); canvas.width = 200; canvas.height = 400;
            const context = canvas.getContext('2d');
            context.fillStyle = '#ff0000'; context.fillRect(0, 0, 200, 200);
            context.fillStyle = '#0000ff'; context.fillRect(0, 200, 200, 200);
            return canvas.toDataURL().split(',')[1];
        }), 'base64');
        await page.locator('#profile_image').setInputFiles({name: 'portrait.png', mimeType: 'image/png', buffer: portrait});
        await page.waitForFunction(() => !document.getElementById('profile-picture-save').disabled);
        await page.getByLabel('Vertical position', {exact: true}).focus(); await page.keyboard.press('End');
        assert.equal(await page.locator('#profile-crop-y').inputValue(), '200', 'Keyboard vertical reposition reaches bottom');
        await Promise.all([page.waitForNavigation({waitUntil: 'load'}), page.getByRole('button', {name: 'Save picture'}).click()]);
        assert.equal((await state('admin')).center, 0x0000ff, 'Vertical crop persists bottom pixels');
        for (const width of [375, 768, 1440]) {
            await page.setViewportSize({width, height: 900});
            await page.goto(origin + '/profile?role=cashier'); await upload();
            const avatar = await page.locator('.profile-picture-avatar').boundingBox();
            assert.equal(avatar.width, 160, width + ': profile picture stays prominent');
            assert.equal(avatar.height, 160, width + ': profile picture stays square');
            await page.addStyleTag({url: origin + '/src/frontend/assets/css/theme.css'});
            for (const theme of ['light', 'dark']) {
                await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
                const colors = await page.locator('#full_name').evaluate(input => {
                    const styles = getComputedStyle(input);
                    return [styles.borderTopColor, styles.backgroundColor];
                });
                const luminance = color => {
                    const values = color.match(/\d+/g).slice(0, 3).map(value => {
                        const channel = Number(value) / 255;
                        return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
                    });
                    return values[0] * 0.2126 + values[1] * 0.7152 + values[2] * 0.0722;
                };
                const levels = colors.map(luminance).sort((a, b) => b - a);
                assert((levels[0] + 0.05) / (levels[1] + 0.05) >= 3, theme + ': field boundary contrast');
                for (const scale of ['100%', '200%']) {
                    await page.evaluate(value => { document.documentElement.style.fontSize = value; }, scale);
                    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, width + ': ' + theme + ' crop fits at ' + scale + ' text size');
                }
                await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
            }
        }
        console.log('Profile picture crop browser: passed (persisted pixels, four roles, keyboard zoom/reposition, Save/Cancel, replacements, invalid requests, rollback failures, mobile)');
    } catch (error) { console.error(errors.slice(-2000)); throw error; } finally {
        if (browser) await browser.close();
        server.kill();
        await new Promise(resolve => server.once('exit', resolve));
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
