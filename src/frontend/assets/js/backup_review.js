// Keep credentials in this page only; the session draft resumes review after reload.
(function () {
    'use strict';
    function initializeAccountChoices(root) {
        root.querySelectorAll('[data-account-action]').forEach(select => {
            function update() {
                select.closest('fieldset').querySelectorAll('[data-account-fields]').forEach(group => {
                    const active = group.dataset.accountFields === select.value;
                    group.hidden = !active;
                    group.querySelectorAll('input, select').forEach(input => {
                        input.disabled = !active;
                        if (input.name.endsWith('[destination_id]') || input.name.endsWith('[username]')) input.required = active;
                    });
                });
            }
            select.addEventListener('change', update);
            update();
        });
    }
    function initializeAuthorization(root) {
        root.querySelectorAll('[data-password-authorization]').forEach(form => {
            const password = form.querySelector('input[type="password"]');
            const dialog = document.createElement('dialog');
            dialog.className = 'backup-authorization';
            dialog.setAttribute('aria-labelledby', password.id + '-authorization-title');
            const title = document.createElement('h2');
            title.id = password.id + '-authorization-title';
            let warning = form.closest('#restore-package')?.querySelector('.restore-introduction');
            if (!warning && password.name === 'restore_password' && !form.matches('[data-identity-form]')) {
                warning = document.createElement('div');
                warning.className = 'restore-introduction';
                const text = document.createElement('p');
                const emphasis = document.createElement('strong');
                emphasis.textContent = 'Restore replaces newer records, including sales.';
                text.append(emphasis, ' Stop source sales and background workers. The Store stays closed until recovery is verified.');
                warning.append(text);
            }
            dialog.append(title);
            if (warning) dialog.append(warning);
            dialog.append(password.closest('.form-group'));
            const actions = document.createElement('div');
            actions.className = 'backup-authorization-actions';
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-secondary';
            cancel.textContent = 'Cancel';
            const confirm = document.createElement('button');
            confirm.type = 'button';
            confirm.className = 'btn btn-primary';
            actions.append(cancel, confirm);
            dialog.append(actions);
            form.append(dialog);
            password.disabled = true;
            let submitter;
            let authorized = false;
            form.addEventListener('submit', event => {
                if (authorized) { authorized = false; return; }
                event.preventDefault();
                event.stopImmediatePropagation();
                submitter = event.submitter;
                title.textContent = submitter.textContent.trim();
                confirm.textContent = title.textContent;
                password.disabled = false;
                dialog.returnValue = '';
                dialog.showModal();
                password.focus();
            });
            function clearPassword() {
                password.value = '';
                password.disabled = true;
            }
            cancel.addEventListener('click', () => { clearPassword(); dialog.close(); });
            dialog.addEventListener('cancel', clearPassword);
            confirm.addEventListener('click', () => {
                if (!password.reportValidity()) return;
                authorized = true;
                dialog.close('authorized');
                form.requestSubmit(submitter);
            });
            password.addEventListener('keydown', event => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                confirm.click();
            });
        });
    }
    function initialize() {
        initializeAuthorization(document);
        initializeAccountChoices(document);
        const controller = new AbortController();
        window.addEventListener('pagehide', () => controller.abort());
        let pending = false;
        document.addEventListener('submit', async function (event) {
            const form = event.target;
            if (!form.matches('[data-identity-form]')) return;
            if (event.submitter?.matches('[data-start-restore][formaction]')) return;
            event.preventDefault();
            if (pending) return;
            const fields = ['restore_password'];
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
                await Promise.all(Array.from(page.querySelectorAll('link[rel="stylesheet"]'), link => {
                    if (Array.from(document.styleSheets).some(sheet => sheet.href === link.href)) return;
                    return new Promise((resolve, reject) => {
                        const stylesheet = document.createElement('link');
                        stylesheet.rel = 'stylesheet';
                        stylesheet.href = link.href;
                        stylesheet.onload = resolve;
                        stylesheet.onerror = () => { stylesheet.remove(); reject(new Error('Review styles could not load. Try again.')); };
                        document.head.append(stylesheet);
                    });
                }));
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
                    const summary = next.querySelector('[data-reviewed-package]');
                    if (summary && upload.files.length && next.elements.namedItem('restore_password').value) {
                        summary.querySelector('[data-selected-package-name]').textContent = upload.files[0].name;
                        summary.hidden = false;
                        next.querySelector('[data-package-inputs]').hidden = true;
                    }
                });
                initializeAuthorization(main);
                initializeAccountChoices(main);
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
