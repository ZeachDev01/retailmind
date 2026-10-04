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

    // Preview the same unrotated raster GD crops; never alter the uploaded original.
    async function rasterBlob(file) {
        const bytes = new Uint8Array(await file.arrayBuffer());
        if (file.type !== 'image/jpeg') {
            const png = file.type === 'image/png';
            const parts = [bytes.slice(0, png ? 8 : 12)];
            const view = new DataView(bytes.buffer);
            for (let offset = png ? 8 : 12; offset < bytes.length;) {
                const length = view.getUint32(offset + (png ? 0 : 4), !png);
                const type = String.fromCharCode(...bytes.subarray(offset + (png ? 4 : 0), offset + (png ? 8 : 4)));
                const end = offset + (png ? 12 : 8) + length + (png ? 0 : length % 2);
                if (end > bytes.length) throw new Error('Incomplete image');
                if (type !== (png ? 'eXIf' : 'EXIF')) {
                    const chunk = bytes.slice(offset, end);
                    if (!png && type === 'VP8X') chunk[8] &= ~8;
                    parts.push(chunk);
                }
                offset = end;
            }
            if (!png) new DataView(parts[0].buffer).setUint32(4, parts.reduce((sum, part) => sum + part.length, 0) - 8, true);
            return new Blob(parts, {type: file.type});
        }
        const parts = [bytes.subarray(0, 2)];
        let offset = 2;
        while (offset + 4 <= bytes.length && bytes[offset] === 255) {
            const marker = bytes[offset + 1];
            if (marker === 218 || marker === 217) break;
            const end = offset + 2 + bytes[offset + 2] * 256 + bytes[offset + 3];
            if (end <= offset + 2 || end > bytes.length) break;
            if (marker !== 225) parts.push(bytes.subarray(offset, end));
            offset = end;
        }
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
            const decoded = await createImageBitmap(await rasterBlob(file));
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
