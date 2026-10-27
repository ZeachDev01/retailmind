/* Private device-local recovery; never posts an inventory operation on resume. */
window.RetailMindInventoryDrafts?.destroy?.();
window.RetailMindInventoryDrafts = (() => {
    let config, key, prefix, busy = 0, restoring = false, active = false;
    const lifetime = new AbortController();
    let observer;
    const encoder = new TextEncoder(), decoder = new TextDecoder(), snapshots = new Map();
    const encode = bytes => btoa(Array.from(bytes, byte => String.fromCharCode(byte)).join(''));
    const decode = value => Uint8Array.from(atob(value), char => char.charCodeAt(0));
    const resubmitting = new WeakSet(), submitted = new WeakMap();
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
            (location.pathname.endsWith('/products.php') && form.method.toLowerCase() === 'post' && !form.hidden && form.id !== 'generate-barcode-form' && !!form.querySelector('[name="action"]')?.value);
    }
    function hidden(form, name, value) {
        let field = form.elements.namedItem(name);
        if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = name; form.append(field); }
        if (!field.value) field.value = value;
        return field;
    }
    function prepare(form) {
        hidden(form, 'inventory_draft_id', identity(form));
        hidden(form, 'operation_token', config.retainedForm === identity(form) && /^[a-f0-9]{48}$/.test(config.retainedToken || '') ? config.retainedToken : token());
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
        const draft = {id: identity(form), formId: form.id, values, context: {operationKind: form.dataset.inventorySubmissionKind || '', submittedFingerprint: submitted.get(form)?.original || null, latestSubmittedFingerprint: submitted.get(form)?.latest || null}};
        document.dispatchEvent(new CustomEvent('inventorydraft:capture', {detail: {draft, form}}));
        return draft;
    }
    async function fingerprint(values) {
        return encode(new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(JSON.stringify(values)))));
    }
    async function persist(draft) {
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const ciphertext = await crypto.subtle.encrypt({name: 'AES-GCM', iv}, key, encoder.encode(JSON.stringify(draft)));
        localStorage.setItem(prefix + draft.id, JSON.stringify({iv: encode(iv), ciphertext: encode(new Uint8Array(ciphertext))}));
    }
    function submissionKind(form, submitter) {
        const action = submitter?.name === 'action' ? submitter.value : form.querySelector('[name="action"]')?.value;
        if (form.id === 'receiving_form') return 'receiving';
        if (location.pathname.endsWith('/inventory_counts.php')) return ({record:'count', approve:'approveCount', reject:'rejectCount'})[action] || '';
        if (location.pathname.endsWith('/stock_issues.php')) return ({approve:'approveReport', reject:'rejectReport', return:'returnReport'})[action] || '';
        if (location.pathname.endsWith('/products.php')) return ({create:'createProduct', adjust:'adjustStock', reconcile_sales_pack_stock:'reconcileStock'})[action] || '';
        if (form.id === 'pullout-form') return 'pullout';
        return '';
    }
    async function keep(form, submitting = false) {
        if (!active || restoring || !eligible(form)) return;
        prepare(form);
        const id = identity(form);
        busy++;
        if (form.id !== 'add-product-form') notice('Keeping unsaved values...');
        try {
            const prior = snapshots.get(id) || Promise.resolve();
            const next = prior.catch(() => {}).then(async () => {
                const draft = await capture(form);
                if (submitting) {
                    const operationToken = draft.values.find(item => item.name === 'operation_token')?.value;
                    const currentFingerprint = await fingerprint(draft.values);
                    if (submitted.get(form)?.token !== operationToken) submitted.set(form, {token:operationToken, original:currentFingerprint});
                    submitted.get(form).latest = currentFingerprint;
                    draft.context.submittedFingerprint = submitted.get(form).original;
                    draft.context.latestSubmittedFingerprint = currentFingerprint;
                }
                await persist(draft);
            });
            snapshots.set(id, next);
            await next;
            if (busy === 1 && form.id !== 'add-product-form') notice('Unsaved values kept on this device. Inventory changes only after server confirmation.');
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
            const operationToken = draft.values.find(item => item.name === 'operation_token')?.value;
            if (draft.context.operationKind && operationToken) {
                let response;
                try { response = await fetch(config.statusUrl + '?kind=' + encodeURIComponent(draft.context.operationKind) + '&token=' + encodeURIComponent(operationToken), {cache:'no-store', credentials:'same-origin', headers:{Accept:'application/json'}}); }
                catch (_) { /* Offline recovery still restores unsent values; final saving requires the server. */ }
                if (response) {
                    if (response.redirected || !response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Session or connection unavailable');
                    const status = await response.json();
                    if (!status.success) throw new Error('The saved operation could not be checked. Check your connection and active workspace, then try Resume again.');
                    if (status.completed && draft.context.submittedFingerprint !== await fingerprint(draft.values)) {
                        const panel = Array.from(document.querySelectorAll('[data-recovery-id]')).find(item => item.dataset.recoveryId === draft.id);
                        if (panel && !panel.querySelector('details')) {
                            const details = document.createElement('details'), summary = document.createElement('summary');
                            summary.textContent = 'Review newer unsaved values'; details.append(summary);
                            for (const item of draft.values.filter(item => !['operation_token','inventory_draft_id','action'].includes(item.name))) {
                                const value = item.files?.map(file => file.name).join(', ') || item.selected?.join(', ') || item.value;
                                const row = document.createElement('p'); row.textContent = item.name.replace(/[\[\]_]/g, ' ').trim() + ': ' + String(value ?? ''); details.append(row);
                            }
                            panel.append(details); details.open = true;
                        }
                        notice('The earlier operation was already saved. Newer unsaved values are still kept for review below. Check the saved record before explicitly discarding this draft.', true);
                        return false;
                    }
                    if (status.completed) {
                        await discard(draft.id);
                        notice('This inventory operation was already saved. No stock change was repeated. Review its saved record.');
                        return true;
                    }
                }
            }
            const promises = [];
            document.dispatchEvent(new CustomEvent('inventorydraft:prepare', {detail: {draft, waitUntil: promise => promises.push(promise)}}));
            await Promise.all(promises);
            const form = Array.from(document.forms).find(item => identity(item) === draft.id || (draft.formId && item.id === draft.formId));
            if (!form) throw new Error('The form is no longer available with your current access.');
            prepare(form);
            if (draft.context.submittedFingerprint) submitted.set(form, {token:operationToken, original:draft.context.submittedFingerprint, latest:draft.context.latestSubmittedFingerprint});
            if (draft.context.operationKind) form.dataset.inventorySubmissionKind = draft.context.operationKind;
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
                field.dispatchEvent(new Event('retailmind:fieldrestore'));
            }
            document.dispatchEvent(new CustomEvent('inventorydraft:restored', {detail: {draft, form}}));
            form.scrollIntoView({block: 'nearest'});
            notice('Draft resumed. Review current Products and quantities before saving. Nothing has been posted.');
            return true;
        } catch (_) { notice('Draft could not be resumed. Sign in again if your session expired, check your connection and current Products, then try Resume again. Your draft is still kept.', true); return false; }
        finally { restoring = false; }
    }
    async function offer(storageKey) {
        if (location.pathname.endsWith('/products.php') && storageKey === prefix + 'entry:') return;
        try {
            const stored = JSON.parse(localStorage.getItem(storageKey));
            const plain = await crypto.subtle.decrypt({name: 'AES-GCM', iv: decode(stored.iv)}, key, decode(stored.ciphertext));
            const draft = JSON.parse(decoder.decode(plain));
            if (config.confirmed && config.confirmedForm === draft.id && draft.context.latestSubmittedFingerprint === await fingerprint(draft.values)) { await discard(draft.id); return; }
            const retained = Array.from(document.forms).find(form => identity(form) === draft.id && form.elements.operation_token?.value === draft.values.find(item => item.name === 'operation_token')?.value);
            if (retained && draft.context.submittedFingerprint) {
                submitted.set(retained, {token:retained.elements.operation_token.value, original:draft.context.submittedFingerprint, latest:draft.context.latestSubmittedFingerprint});
                retained.dataset.inventorySubmissionKind = draft.context.operationKind || '';
            }
            const panel = document.createElement('div'); panel.className = 'alert alert-info inventory-recovery-alert'; panel.dataset.recoveryId = draft.id;
            const text = document.createElement('span'); text.textContent = 'Unfinished inventory work (' + draft.id + '). ';
            const actions = document.createElement('div'); actions.className = 'inventory-recovery-actions';
            const restore = document.createElement('button'); restore.type = 'button'; restore.className = 'btn btn-small'; restore.textContent = 'Resume';
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-small btn-secondary'; remove.textContent = 'Discard';
            restore.addEventListener('click', async () => { restore.disabled = true; if (await resume(draft)) panel.remove(); else restore.disabled = false; });
            remove.addEventListener('click', () => discard(draft.id));
            actions.append(restore, remove); panel.append(text, actions); (document.querySelector('main') || document.body).prepend(panel);
        } catch (_) { notice('A draft could not be unlocked. Sign in with its original account; do not clear browser data.', true); }
    }
    async function legacyDrafts() {
        if (localStorage.getItem('retail_mind_add_product_state') || localStorage.getItem('retail_mind_add_product_step')) {
            const panel = document.createElement('div'); panel.className = 'alert alert-warning'; panel.dataset.legacyProductDraft = '1';
            const text = document.createElement('p'); text.textContent = 'An older Product draft has no account owner and predates private storage. It cannot be restored safely on this shared device. It remains here until you explicitly discard it.';
            const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-small btn-secondary'; button.textContent = 'Discard older Product draft';
            button.addEventListener('click', () => { try { localStorage.removeItem('retail_mind_add_product_state'); localStorage.removeItem('retail_mind_add_product_step'); panel.remove(); } catch (_) { notice('The older draft could not be discarded. Browser storage is unavailable.', true); } });
            panel.append(text, button); (document.querySelector('main') || document.body).prepend(panel);
        }
        if (!location.pathname.endsWith('/stock_receiving.php')) return;
        const legacyKey = 'receiving-draft:' + config.user;
        const raw = sessionStorage.getItem(legacyKey);
        if (!raw) return;
        if (localStorage.getItem(prefix + 'receiving_form')) {
            notice('An older receiving draft is still kept in this browser session. Finish or discard the current draft before recovering the older one.');
            return;
        }
        const old = JSON.parse(raw);
        if (!old || !Array.isArray(old.lines)) throw new Error('Older receiving draft cannot be read.');
        await persist({id:'receiving_form', formId:'receiving_form', values:[{name:'supplier', value:String(old.supplier || '')}, {name:'invoice_number', value:String(old.invoice_number || '')}], context:{lines:old.lines, step:2}});
        sessionStorage.removeItem(legacyKey);
        notice('Your older receiving draft was moved into private recovery. Review receipt history before retrying an older entry.');
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
            await legacyDrafts();
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
                form.dataset.inventorySubmissionKind = submissionKind(form, submitter);
                keep(form, true).then(() => setTimeout(() => { resubmitting.add(form); form.requestSubmit(submitter); }, 0));
            }, {capture: true, signal: lifetime.signal});
            window.addEventListener('beforeunload', event => { if (busy) { event.preventDefault(); event.returnValue = ''; } }, {signal: lifetime.signal});
        } catch (_) {
            config.key = null;
            if (!location.pathname.endsWith('/products.php')) {
                notice('Draft recovery is unavailable. Use HTTPS and allow browser storage. Keep this page open until the server confirms saving.', true);
            }
        }
    }
    function destroy() { active = false; lifetime.abort(); observer?.disconnect(); key = null; }
    return {
        start(options) {
            config = options;
            window.addEventListener('pagehide', destroy, {once:true, signal:lifetime.signal});
            window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); }, {signal:lifetime.signal});
            document.addEventListener('DOMContentLoaded', initialize, {once:true, signal:lifetime.signal});
        },
        discard, keep, identity, destroy,
        async flush() {
            for (;;) {
                const pending = Array.from(snapshots.values());
                await Promise.allSettled(pending);
                if (pending.length === snapshots.size && pending.every(promise => Array.from(snapshots.values()).includes(promise))) return;
            }
        }
    };
})();
