// Keep the selected file and credentials in this page only; reload clears them.
(function () {
    'use strict';
    function initialize() {
        const controller = new AbortController();
        window.addEventListener('pagehide', () => controller.abort());
        let pending = false;
        document.addEventListener('submit', async function (event) {
            const form = event.target;
            if (!form.matches('[data-identity-form]')) return;
            if (event.submitter?.matches('[data-start-restore][formaction]')) return;
            event.preventDefault();
            if (pending) return;
            const fields = ['restore_password', 'backup_passphrase'];
            const values = fields.map(name => form.elements.namedItem(name)?.value || '');
            const files = form.elements.namedItem('backup_file').files;
            const body = new FormData(form);
            if (event.submitter?.name) body.set(event.submitter.name, event.submitter.value);
            const button = event.submitter;
            pending = true;
            if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
            try {
                const response = await fetch(form.getAttribute('action'), { method: 'POST', body, signal: controller.signal });
                if (!response.ok) throw new Error('Review failed. Check your session and try again.');
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const main = page.querySelector('main');
                if (!main || !main.querySelector('[data-identity-form]')) throw new Error('Your session expired. Reload and sign in again.');
                document.querySelector('main').replaceWith(main);
                document.title = page.title;
                document.body.className = page.body.className;
                history.replaceState(null, '', response.url);
                main.querySelectorAll('form').forEach(next => {
                    const upload = next.elements.namedItem('backup_file');
                    if (!upload) return;
                    upload.files = files;
                    fields.forEach((name, index) => {
                        const input = next.elements.namedItem(name);
                        if (input) input.value = values[index];
                    });
                });
                const target = main.querySelector('#restore-package') || main;
                target.scrollIntoView({ block: 'start' });
            } catch (error) {
                if (controller.signal.aborted) return;
                let message = form.querySelector('[data-review-error]');
                if (!message) {
                    message = document.createElement('p');
                    message.dataset.reviewError = '';
                    message.setAttribute('role', 'alert');
                    form.prepend(message);
                }
                message.textContent = error.message;
            } finally {
                pending = false;
                if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
            }
        });
    }
    if (window.RetailMindNavigation) window.RetailMindNavigation.initializePage(initialize);
    else initialize();
})();
