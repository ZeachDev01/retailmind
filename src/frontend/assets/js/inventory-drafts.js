/* Private device-local recovery; never posts an inventory operation on resume. */
window.RetailMindInventoryDrafts = (() => {
    let config, key, prefix, busy = 0, restoring = false, active = false;
    const lifetime = new AbortController();
    let observer;
    const encoder = new TextEncoder(), decoder = new TextDecoder(), snapshots = new Map();
    const encode = bytes => btoa(Array.from(bytes, byte => String.fromCharCode(byte)).join(''));
    const decode = value => Uint8Array.from(atob(value), char => char.charCodeAt(0));
    const resubmitting = new WeakSet();
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    function notice(message, error = false) {
        let status = document.getElementById('inventory-draft-status');
        if (!status) {
            status = document.createElement('p'); status.id = 'inventory-draft-status'; status.role = 'status';
            (document.querySelector('main') || document.body).prepend(status);
        }
        status.textContent = message;
        status.setAttribute('role', error ? 'alert' : 'status');
    }
    function token() { return Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => byte.toString(16).padStart(2, '0')).join(''); }
    function identity(form) {
        return form.dataset.inventoryDraftId || (form.dataset.inventoryDraftId = form.id ||
            [form.querySelector('[name="action"]')?.value || 'entry', form.querySelector('[name="adjustment_id"], [name="count_id"]')?.value || ''].join(':'));
    }
    function eligible(form) {
        return form.matches('#add-product-form, #product-edit-form, #pack-reconcile-form, #pullout-form, .count-form, .review-form, #receiving_form, .inline-form') ||
            (location.pathname.endsWith('/products.php') && form.method.toLowerCase() === 'post' && !form.hidden && form.id !== 'generate-barcode-form');
    }
    function hidden(form, name, value) {
        let field = form.elements.namedItem(name);
        if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = name; form.append(field); }
        if (!field.value) field.value = value;
        return field;
    }
    function prepare(form) {
        hidden(form, 'inventory_draft_id', identity(form));
        hidden(form, 'operation_token', token());
    }
    async function capture(form) {
        prepare(form);
        const values = [];
        for (const field of form.elements) {
            if (!field.name || ['csrf_token','receiving_nonce'].includes(field.name) || ['submit','button','reset'].includes(field.type)) continue;
            const item = {name: field.name, value: field.value, checked: field.checked};
            if (field.multiple) item.selected = Array.from(field.selectedOptions, option => option.value);
            if (field.type === 'file') {
                item.files = await Promise.all(Array.from(field.files, async file => ({name: file.name, type: file.type, bytes: encode(new Uint8Array(await file.arrayBuffer()))})));
                item.value = '';
            }
            values.push(item);
        }
        const draft = {id: identity(form), formId: form.id, values, context: {}};
        document.dispatchEvent(new CustomEvent('inventorydraft:capture', {detail: {draft, form}}));
        return draft;
    }
    async function keep(form) {
        if (!active || restoring || !eligible(form)) return;
        prepare(form);
        const id = identity(form);
        busy++;
        notice('Keeping unsaved values…');
        try {
            const prior = snapshots.get(id) || Promise.resolve();
            const next = prior.catch(() => {}).then(async () => {
                const draft = await capture(form);
                const iv = crypto.getRandomValues(new Uint8Array(12));
                const ciphertext = await crypto.subtle.encrypt({name: 'AES-GCM', iv}, key, encoder.encode(JSON.stringify(draft)));
                localStorage.setItem(prefix + id, JSON.stringify({iv: encode(iv), ciphertext: encode(new Uint8Array(ciphertext))}));
            });
            snapshots.set(id, next);
            await next;
            if (busy === 1) notice('Unsaved values kept on this device. Inventory changes only after server confirmation.');
        } catch (_) { notice('Draft could not be kept. Keep this page open; these values may be lost if you close it.', true); }
        finally { busy--; }
    }
    async function discard(formOrId) {
        const id = typeof formOrId === 'string' ? formOrId : identity(formOrId);
        await snapshots.get(id)?.catch(() => {});
        if (typeof formOrId !== 'string' && formOrId.elements.operation_token) formOrId.elements.operation_token.value = token();
        try { localStorage.removeItem(prefix + id); document.querySelectorAll('[data-recovery-id]').forEach(panel => { if (panel.dataset.recoveryId === id) panel.remove(); }); }
        catch (_) { notice('Draft could not be discarded. Browser storage is unavailable.', true); }
    }
    async function resume(draft) {
        if (restoring) return false;
        restoring = true;
        try {
            const promises = [];
            document.dispatchEvent(new CustomEvent('inventorydraft:prepare', {detail: {draft, waitUntil: promise => promises.push(promise)}}));
            await Promise.all(promises);
            const form = Array.from(document.forms).find(item => identity(item) === draft.id || (draft.formId && item.id === draft.formId));
            if (!form) throw new Error('The form is no longer available with your current access.');
            prepare(form);
            // Product selection loads current batches; adapters await that request before restoring quantities.
            const ordinals = new Map();
            for (const item of draft.values) {
                const fields = Array.from(form.elements).filter(field => field.name === item.name);
                const ordinal = ordinals.get(item.name) || 0;
                const field = fields[ordinal];
                ordinals.set(item.name, ordinal + 1);
                if (!field) continue;
                if (field.type === 'file') {
                    const transfer = new DataTransfer();
                    for (const file of item.files || []) transfer.items.add(new File([decode(file.bytes)], file.name, {type: file.type}));
                    field.files = transfer.files;
                } else if (field.multiple) {
                    for (const option of field.options) option.selected = (item.selected || []).includes(option.value);
                } else if (['checkbox','radio'].includes(field.type)) field.checked = !!item.checked;
                else field.value = item.value;
                field.dispatchEvent(new Event('input', {bubbles: true}));
            }
            document.dispatchEvent(new CustomEvent('inventorydraft:restored', {detail: {draft, form}}));
            form.scrollIntoView({block: 'nearest'});
            notice('Draft resumed. Review current Products and quantities before saving. Nothing has been posted.');
            return true;
        } catch (error) { notice('Draft could not be resumed. ' + error.message, true); return false; }
        finally { restoring = false; }
    }
    async function offer(storageKey) {
        try {
            const stored = JSON.parse(localStorage.getItem(storageKey));
            const plain = await crypto.subtle.decrypt({name: 'AES-GCM', iv: decode(stored.iv)}, key, decode(stored.ciphertext));
            const draft = JSON.parse(decoder.decode(plain));
            if (config.confirmed && config.confirmedForm === draft.id) { await discard(draft.id); return; }
            const panel = document.createElement('div'); panel.className = 'alert alert-info'; panel.dataset.recoveryId = draft.id;
            const text = document.createElement('span'); text.textContent = 'Unfinished inventory work (' + draft.id + '). ';
            const restore = document.createElement('button'); restore.type = 'button'; restore.className = 'btn btn-small'; restore.textContent = 'Resume';
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-small btn-secondary'; remove.textContent = 'Discard';
            restore.addEventListener('click', async () => { restore.disabled = true; if (await resume(draft)) panel.remove(); else restore.disabled = false; });
            remove.addEventListener('click', () => discard(draft.id));
            panel.append(text, restore, remove); (document.querySelector('main') || document.body).prepend(panel);
        } catch (_) { notice('A draft could not be unlocked. Sign in with its original account; do not clear browser data.', true); }
    }
    async function initialize() {
        prefix = 'retailmind:inventory:v1:' + config.user + ':' + location.pathname + ':';
        for (const form of document.forms) if (eligible(form)) prepare(form);
        try {
            if (!config.key || !crypto.subtle) throw new Error('Secure storage unavailable');
            key = await crypto.subtle.importKey('raw', decode(config.key), 'AES-GCM', false, ['encrypt','decrypt']);
            config.key = null;
            if (lifetime.signal.aborted) return;
            active = true;
            const probe = prefix + 'probe'; localStorage.setItem(probe, '1'); localStorage.removeItem(probe);
            // Old unowned Product data must never be offered to a different operator.
            localStorage.removeItem('retail_mind_add_product_state'); localStorage.removeItem('retail_mind_add_product_step');
            for (const legacy of Object.keys(sessionStorage)) if (legacy.startsWith('receiving-draft:')) sessionStorage.removeItem(legacy);
            for (const form of document.forms) if (eligible(form)) prepare(form);
            for (const storageKey of Object.keys(localStorage)) if (storageKey.startsWith(prefix)) await offer(storageKey);
            observer = new MutationObserver(() => { for (const form of document.forms) if (eligible(form)) prepare(form); });
            observer.observe(document.body, {childList: true, subtree: true});
            const changed = event => { const form = event.target.closest('form'); if (form) keep(form); };
            document.addEventListener('input', changed, {signal: lifetime.signal}); document.addEventListener('change', changed, {signal: lifetime.signal});
            document.addEventListener('submit', event => {
                if (!eligible(event.target)) return;
                if (resubmitting.has(event.target)) { resubmitting.delete(event.target); return; }
                event.preventDefault(); event.stopImmediatePropagation();
                const form = event.target, submitter = event.submitter;
                keep(form).then(() => setTimeout(() => { resubmitting.add(form); form.requestSubmit(submitter); }, 0));
            }, {capture: true, signal: lifetime.signal});
            window.addEventListener('beforeunload', event => { if (busy) { event.preventDefault(); event.returnValue = ''; } }, {signal: lifetime.signal});
        } catch (_) {
            config.key = null;
            notice('Draft recovery is unavailable. Use HTTPS and allow browser storage. Keep this page open until the server confirms saving.', true);
        }
    }
    return {
        start(options) { config = options; window.addEventListener('pagehide', () => { active = false; lifetime.abort(); observer?.disconnect(); key = null; }, {once: true}); document.addEventListener('DOMContentLoaded', initialize, {once: true}); },
        discard, keep, identity,
        async waitFor(test) { for (let attempt = 0; attempt < 100; attempt++) { if (test()) return; await sleep(50); } throw new Error('Current Product details could not be loaded. Try Resume again.'); }
    };
})();
window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
