(function () {
    'use strict';
    const form = document.getElementById('profile-picture-form');
    if (!form) return;
    const input = document.getElementById('profile_image');
    const editor = document.getElementById('profile-crop-editor');
    const canvas = document.getElementById('profile-crop-preview');
    const zoom = document.getElementById('profile-crop-zoom');
    const horizontal = document.getElementById('profile-crop-horizontal');
    const vertical = document.getElementById('profile-crop-vertical');
    const save = document.getElementById('profile-picture-save');
    const cancel = document.getElementById('profile-picture-cancel');
    const error = document.getElementById('profile-crop-error');
    const filename = document.getElementById('profile-picture-filename');
    let image = null;
    let selection = 0;

    function reset() {
        selection++;
        if (image) image.close();
        image = null;
        input.value = '';
        editor.hidden = true;
        cancel.hidden = true;
        save.disabled = true;
        filename.textContent = 'No file selected';
        error.textContent = '';
        ['x', 'y', 'size'].forEach(key => { document.getElementById('profile-crop-' + key).value = ''; });
    }

    function draw() {
        if (!image) return;
        const size = Math.max(1, Math.floor(Math.min(image.width, image.height) / Number(zoom.value)));
        const x = Math.round((image.width - size) * Number(horizontal.value) / 100);
        const y = Math.round((image.height - size) * Number(vertical.value) / 100);
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        canvas.getContext('2d').drawImage(image, x, y, size, size, 0, 0, canvas.width, canvas.height);
        Object.entries({x, y, size}).forEach(([key, value]) => {
            document.getElementById('profile-crop-' + key).value = value;
        });
    }

    function checkDimensions(width, height) {
        if (!width || !height || width > Number(form.dataset.maxDimension) || height > Number(form.dataset.maxDimension)
            || width * height > Number(form.dataset.maxPixels)) {
            throw new Error('Image dimensions too large');
        }
    }

    // Read dimensions before decoding; preview the same unrotated raster GD crops.
    async function rasterBlob(file) {
        const bytes = new Uint8Array(await file.arrayBuffer());
        const view = new DataView(bytes.buffer);
        let dimensionsFound = false;
        if (file.type !== 'image/jpeg') {
            const png = file.type === 'image/png';
            if (png) {
                if (view.getUint32(0) !== 0x89504e47 || view.getUint32(4) !== 0x0d0a1a0a
                    || view.getUint32(8) !== 13 || view.getUint32(12) !== 0x49484452) throw new Error('Invalid PNG');
                checkDimensions(view.getUint32(16), view.getUint32(20));
                dimensionsFound = true;
            } else if (view.getUint32(0) !== 0x52494646 || view.getUint32(8) !== 0x57454250) {
                throw new Error('Invalid WebP');
            }
            const parts = [bytes.slice(0, png ? 8 : 12)];
            for (let offset = png ? 8 : 12; offset < bytes.length;) {
                const length = view.getUint32(offset + (png ? 0 : 4), !png);
                const type = String.fromCharCode(...bytes.subarray(offset + (png ? 4 : 0), offset + (png ? 8 : 4)));
                const end = offset + (png ? 12 : 8) + length + (png ? 0 : length % 2);
                if (end > bytes.length) throw new Error('Incomplete image');
                if (!png) {
                    const start = offset + 8;
                    if (type === 'VP8X' && length >= 10) {
                        checkDimensions(1 + (view.getUint32(start + 4, true) & 0xffffff),
                            1 + (view.getUint32(start + 6, true) >>> 8));
                        dimensionsFound = true;
                    } else if (type === 'VP8 ' && length >= 10) {
                        checkDimensions(view.getUint16(start + 6, true) & 0x3fff, view.getUint16(start + 8, true) & 0x3fff);
                        dimensionsFound = true;
                    } else if (type === 'VP8L' && length >= 5) {
                        const bits = view.getUint32(start + 1, true);
                        checkDimensions(1 + (bits & 0x3fff), 1 + ((bits >>> 14) & 0x3fff));
                        dimensionsFound = true;
                    } else if (type === 'ANIM' || type === 'ANMF') {
                        throw new Error('Animation is not supported');
                    }
                }
                if (type !== (png ? 'eXIf' : 'EXIF')) {
                    const chunk = bytes.slice(offset, end);
                    if (!png && type === 'VP8X') chunk[8] &= ~8;
                    parts.push(chunk);
                }
                offset = end;
            }
            if (!dimensionsFound) throw new Error('Image dimensions missing');
            if (!png) new DataView(parts[0].buffer).setUint32(4, parts.reduce((sum, part) => sum + part.length, 0) - 8, true);
            return new Blob(parts, {type: file.type});
        }
        if (view.getUint16(0) !== 0xffd8) throw new Error('Invalid JPEG');
        const parts = [bytes.subarray(0, 2)];
        let offset = 2;
        while (offset + 4 <= bytes.length && bytes[offset] === 255) {
            if (bytes[offset + 1] === 255) { offset++; continue; }
            const marker = bytes[offset + 1];
            if (marker === 218 || marker === 217) break;
            const end = offset + 2 + bytes[offset + 2] * 256 + bytes[offset + 3];
            if (end <= offset + 2 || end > bytes.length) break;
            if (marker >= 0xc0 && marker <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(marker)) {
                if (end < offset + 9) throw new Error('Incomplete JPEG frame');
                checkDimensions(view.getUint16(offset + 7), view.getUint16(offset + 5));
                dimensionsFound = true;
            }
            if (marker !== 225) parts.push(bytes.subarray(offset, end));
            offset = end;
        }
        if (!dimensionsFound) throw new Error('Image dimensions missing');
        parts.push(bytes.subarray(offset));
        return new Blob(parts, {type: file.type});
    }

    input.addEventListener('change', async function () {
        const file = input.files && input.files[0];
        const attempt = ++selection;
        if (image) image.close();
        image = null;
        editor.hidden = true;
        save.disabled = true;
        cancel.hidden = !file;
        error.textContent = '';
        filename.textContent = file ? file.name : 'No file selected';
        if (!file) return;
        if (!input.accept.split(',').includes(file.type) || file.size > Number(form.dataset.maxBytes)) {
            reset();
            error.textContent = 'Choose a still JPG, PNG, or WebP image no larger than 5 MB.';
            return;
        }
        try {
            const raster = await rasterBlob(file);
            if (attempt !== selection) return;
            const decoded = await createImageBitmap(raster);
            if (attempt !== selection) { decoded.close(); return; }
            if (decoded.width > Number(form.dataset.maxDimension) || decoded.height > Number(form.dataset.maxDimension)
                || decoded.width * decoded.height > Number(form.dataset.maxPixels)) {
                decoded.close();
                throw new Error('Image dimensions too large');
            }
            image = decoded;
            zoom.value = '1'; horizontal.value = vertical.value = '50';
            editor.hidden = false;
            draw();
            save.disabled = false;
        } catch {
            if (attempt !== selection) return;
            reset();
            error.textContent = 'This picture could not be previewed. Choose a smaller, valid image and try again.';
        }
    });
    [zoom, horizontal, vertical].forEach(control => control.addEventListener('input', draw));
    cancel.addEventListener('click', function () { reset(); input.focus(); });
    form.addEventListener('submit', function (event) {
        if (!image) { event.preventDefault(); return; }
        draw();
        save.disabled = true;
    });
})();
