(() => {
    const opener = document.getElementById('backup-choose-folder');
    const dialog = document.getElementById('backup-folder-dialog');
    if (!opener || !dialog || dialog.dataset.initialized) return;
    dialog.dataset.initialized = 'true';
    const destination = document.getElementById('backup_destination_path');
    const path = document.getElementById('backup-folder-path');
    const list = document.getElementById('backup-folder-list');
    const status = document.getElementById('backup-folder-status');
    const up = document.getElementById('backup-folder-up');
    const use = document.getElementById('backup-folder-use');
    let current = null;
    let request = null;

    async function browse(folder) {
        request?.abort();
        const pending = new AbortController();
        request = pending;
        current = null;
        use.disabled = true;
        up.disabled = true;
        list.replaceChildren();
        list.setAttribute('aria-busy', 'true');
        status.textContent = 'Loading Store PC folders…';
        try {
            const body = new URLSearchParams({
                action: 'browse_backup_destination', path: folder,
                csrf_token: document.querySelector('#backup-schedule-form input[name="csrf_token"]').value,
            });
            const response = await fetch(location.href, {method: 'POST', body, signal: pending.signal, headers: {Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Store PC folders are unavailable. Try again.');
            current = data;
            path.value = data.path;
            up.disabled = data.parent === null;
            use.disabled = !data.selectable;
            for (const folder of data.folders) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'backup-folder-entry';
                const icon = document.createElement('i');
                icon.className = 'bi bi-folder2';
                icon.setAttribute('aria-hidden', 'true');
                const name = document.createElement('span');
                name.textContent = folder.name;
                button.append(icon, name);
                button.addEventListener('click', () => browse(folder.path));
                list.append(button);
            }
            status.textContent = data.path === '' ? 'Choose a drive to open its folders.'
                : !data.selectable ? 'Open a writable folder outside the application, web root and private recovery store.'
                : data.folders.length === 0 ? 'No subfolders. You can use this folder.' : 'Open a subfolder, or use the current folder.';
        } catch (error) {
            if (pending.signal.aborted) return;
            status.textContent = error instanceof SyntaxError ? 'Could not load folders. Reload the page and try again.' : error.message;
        } finally {
            if (request === pending) list.setAttribute('aria-busy', 'false');
        }
    }

    opener.addEventListener('click', () => {
        dialog.showModal();
        browse(destination.value.trim());
    });
    dialog.querySelectorAll('[data-folder-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => {
        request?.abort();
        opener.focus();
    });
    document.getElementById('backup-folder-path-form').addEventListener('submit', event => {
        event.preventDefault();
        browse(path.value.trim());
    });
    document.getElementById('backup-folder-drives').addEventListener('click', () => browse(''));
    up.addEventListener('click', () => { if (current) browse(current.parent); });
    use.addEventListener('click', () => {
        if (!current?.selectable) return;
        destination.value = current.path;
        destination.dispatchEvent(new Event('change', {bubbles: true}));
        dialog.close();
    });
})();
