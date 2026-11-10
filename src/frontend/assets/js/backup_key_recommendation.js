// Receive the complete attachment before offering the next step; saving it is still the operator's choice.
(function () {
    'use strict';
    async function downloadAttachment(url, options = {}) {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
        const disposition = response.headers.get('Content-Disposition') || '';
        if (!response.ok || !disposition.includes('attachment')) {
            if (response.headers.get('Content-Type')?.includes('application/json')) {
                throw new Error((await response.json()).message);
            }
            throw new Error('Download unavailable. Check your session and try again. If the backup has expired, create a new one.');
        }
        const blob = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = blob;
        link.download = disposition.match(/filename="?([^";]+)/)?.[1] || 'download';
        link.click();
        setTimeout(() => URL.revokeObjectURL(blob), 1000);
    }
    function initialize() {
        const dialog = document.querySelector('[data-backup-key-dialog]');
        if (!dialog || typeof dialog.showModal !== 'function' || dialog.dataset.initialized) return;
        dialog.dataset.initialized = '1';
        const form = dialog.querySelector('[data-backup-key-export]');
        const password = form.elements.namedItem('key_password');
        const error = dialog.querySelector('[data-backup-key-error]');
        const key = 'retailmind.backup-key-recommendation.' + dialog.dataset.fingerprint;
        const download = document.querySelector('[data-backup-download]');
        function show() {
            try { sessionStorage.setItem(key, '1'); } catch (error) { /* storage unavailable */ }
            if (!dialog.open) dialog.showModal();
        }
        dialog.addEventListener('close', () => {
            password.value = '';
            if (download?.hidden) document.querySelector('[data-backup-create]')?.focus();
        });
        dialog.querySelector('[data-backup-key-dialog-close]').addEventListener('click', () => dialog.close());
        dialog.querySelector('[data-backup-key-manage]').addEventListener('click', () => dialog.close());
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            if (button.disabled) return;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            error.hidden = true;
            try {
                await downloadAttachment(form.action, {
                    method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' },
                });
                dialog.close();
            } catch (failure) {
                error.textContent = failure.message;
                error.hidden = false;
            } finally {
                password.value = '';
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        });
        if (download && dialog.dataset.afterDownload === '1') {
            const status = document.createElement('p');
            status.setAttribute('role', 'status');
            status.hidden = true;
            download.parentElement.after(status);
            download.addEventListener('click', async event => {
                event.preventDefault();
                if (download.getAttribute('aria-busy') === 'true') return;
                download.setAttribute('aria-busy', 'true');
                download.setAttribute('aria-disabled', 'true');
                status.textContent = 'Downloading backup…';
                status.hidden = false;
                try {
                    await downloadAttachment(download.href);
                    download.hidden = true;
                    status.textContent = 'Backup sent to your browser. Keep the downloaded file safe.';
                    show();
                } catch (failure) {
                    status.textContent = failure.message;
                } finally {
                    download.removeAttribute('aria-busy');
                    download.removeAttribute('aria-disabled');
                }
            });
            return;
        }
        let seen = false;
        try { seen = sessionStorage.getItem(key) !== null; } catch (error) { /* storage unavailable */ }
        if (!seen) show();
    }
    if (window.RetailMindNavigation) window.RetailMindNavigation.initializePage(initialize);
    else initialize();
})();
