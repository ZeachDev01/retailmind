(() => {
    'use strict';
    const workspace = document.getElementById('gcash-workspace');
    if (!workspace) return;
    const el = id => document.getElementById('gcash-' + id);
    const rules = {min: Number(workspace.dataset.minLength), max: Number(workspace.dataset.maxLength)};
    const parser = window.GCashReference;
    const reference = el('reference');
    const save = el('save');
    let saving = false, reviewing = false, checking = false, duplicate = false;
    let debounce, lookup, historyRequest = 0, page = 1, rows = [], reviewRow;
    let stream, worker, cameraVersion = 0, ocrBusy = false, leaving = false;
    const notify = (message, type = 'info') => window.RetailMindUI.toast(message, type);
    const scanStatus = message => { el('scan-status').textContent = message; };
    const updateSave = () => { save.disabled = saving || checking || duplicate || !parser.valid(reference.value, rules); };
    async function request(params, body, signal) {
        const url = new URL(workspace.dataset.api, location.href);
        for (const [key, value] of Object.entries(params || {})) url.searchParams.set(key, value);
        const response = await fetch(url, {
            credentials: 'same-origin', cache: 'no-store', signal,
            ...(body ? {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': workspace.dataset.csrf}, body: JSON.stringify(body)} : {})
        });
        let data;
        try { data = await response.json(); }
        catch (_) { throw new Error('Session or connection unavailable. Reload the page and sign in again if needed.'); }
        if (!response.ok || !data.success) throw new Error(data.message || 'The GCash request failed.');
        return data;
    }
    async function checkReference() {
        clearTimeout(debounce);
        lookup?.abort();
        lookup = new AbortController();
        const currentLookup = lookup;
        const candidate = parser.extract(reference.value, rules);
        duplicate = false;
        el('duplicate').hidden = true;
        reference.setCustomValidity('');
        if (!candidate) {
            checking = false;
            reference.setCustomValidity('Enter one numeric reference matching the configured length.');
            scanStatus('No valid reference found. Enter it manually or scan a code containing a reference.');
            updateSave();
            return false;
        }
        reference.value = candidate;
        checking = true;
        updateSave();
        scanStatus('Checking for a duplicate reference…');
        try {
            const result = await request({reference: candidate}, null, currentLookup.signal);
            if (currentLookup !== lookup || reference.value !== candidate) return false;
            duplicate = result.duplicate;
            el('duplicate').hidden = !duplicate;
            scanStatus(duplicate ? 'Duplicate reference. Save blocked.' : 'Reference captured. Review the number and amount; payment remains unverified.');
            if (duplicate) notify('This GCash reference has already been recorded.', 'warning');
            return !duplicate;
        } catch (error) {
            if (error.name !== 'AbortError') { scanStatus('Duplicate check unavailable. Retry before saving.'); notify(error.message, 'error'); }
            return false;
        } finally {
            if (currentLookup === lookup) { checking = false; updateSave(); }
        }
    }
    reference.addEventListener('input', () => {
        lookup?.abort();
        lookup = null;
        checking = true;
        duplicate = false;
        reference.setCustomValidity('');
        el('duplicate').hidden = true;
        scanStatus('Reading reference…');
        updateSave();
        clearTimeout(debounce);
        debounce = setTimeout(checkReference, 400);
    });
    // Focus-scoped HID input only. Product scanner handlers remain on the POS page.
    reference.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); checkReference(); }
    });
    function validatePrincipal(val) {
        if (typeof val !== 'string' && typeof val !== 'number') return null;
        const str = String(val).trim();
        if (!/^[1-9]\d{0,6}$/.test(str)) return null;
        const n = Number(str);
        if (!Number.isInteger(n) || n < 1 || n > 50000) return null;
        return n;
    }

    function calculateFee(principal) {
        if (principal < 500) return 5;
        if (principal <= 1000) return 10;
        if (principal <= 10000) return Math.ceil(principal / 500) * 5;
        return Math.round(principal * 0.01 * 100) / 100;
    }

    function calculatePreview(type, principal, feeMethod) {
        const fee = calculateFee(principal);
        if (type === 'Cash-In') {
            return {
                type: 'Cash-In',
                principal,
                fee,
                feeMethod: 'Fee in cash',
                feeLocation: 'cash',
                customerTransfer: 0,
                counterCollect: principal + fee,
                cashPayout: 0,
                walletTransfer: principal,
                netServiceCash: principal + fee,
                netWallet: -principal,
                instructions: `Customer pays ₱${(principal + fee).toLocaleString('en-PH', {minimumFractionDigits: 2})} cash at counter. Send ₱${principal.toLocaleString('en-PH', {minimumFractionDigits: 2})} from Store wallet to customer.`
            };
        }
        const isCashFee = feeMethod === 'Fee in cash';
        if (isCashFee) {
            return {
                type: 'Cash-Out',
                principal,
                fee,
                feeMethod: 'Fee in cash',
                feeLocation: 'cash',
                customerTransfer: principal,
                counterCollect: fee,
                cashPayout: principal,
                walletTransfer: 0,
                netServiceCash: -(principal - fee),
                netWallet: principal,
                instructions: `Customer transfers ₱${principal.toLocaleString('en-PH', {minimumFractionDigits: 2})} to Store wallet. Collect ₱${fee.toLocaleString('en-PH', {minimumFractionDigits: 2})} cash fee at counter. Hand over ₱${principal.toLocaleString('en-PH', {minimumFractionDigits: 2})} cash to customer.`
            };
        }
        return {
            type: 'Cash-Out',
            principal,
            fee,
            feeMethod: 'Fee via GCash',
            feeLocation: 'wallet',
            customerTransfer: principal + fee,
            counterCollect: 0,
            cashPayout: principal,
            walletTransfer: 0,
            netServiceCash: -principal,
            netWallet: principal + fee,
            instructions: `Customer transfers ₱${(principal + fee).toLocaleString('en-PH', {minimumFractionDigits: 2})} to Store wallet. Hand over ₱${principal.toLocaleString('en-PH', {minimumFractionDigits: 2})} cash to customer.`
        };
    }

    const formatPhp = (val, sign = false) => {
        const prefix = sign ? (val > 0 ? '+₱' : (val < 0 ? '-₱' : '₱')) : '₱';
        return prefix + Math.abs(val).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };

    function updatePreview() {
        const type = el('type')?.value || 'Cash-In';
        const feeMethodGroup = el('fee-method-group');
        const isCashOut = type === 'Cash-Out';
        if (feeMethodGroup) feeMethodGroup.hidden = !isCashOut;

        const amountInput = el('amount');
        if (!amountInput) return;
        const raw = amountInput.value.trim();
        const principal = validatePrincipal(raw);

        const statusEl = el('preview-status');
        const contentEl = el('preview-content');
        if (!statusEl || !contentEl) return;

        if (!principal) {
            contentEl.hidden = true;
            statusEl.hidden = false;
            if (raw === '') {
                statusEl.textContent = 'Enter a whole-peso principal amount from 1 to 50,000 to preview fees and instructions.';
                amountInput.setCustomValidity('');
            } else {
                statusEl.textContent = 'Enter a whole-peso principal amount from 1 to 50,000. Principal excludes the fee.';
                amountInput.setCustomValidity('Enter a whole-peso principal amount from 1 to 50,000.');
            }
            return;
        }

        amountInput.setCustomValidity('');
        const feeMethod = isCashOut ? (el('fee-method')?.value || 'Fee via GCash') : 'Fee in cash';
        const preview = calculatePreview(type, principal, feeMethod);

        statusEl.hidden = true;
        contentEl.hidden = false;
        el('preview-instructions').textContent = preview.instructions;
        document.getElementById('preview-fee').textContent = formatPhp(preview.fee);
        document.getElementById('preview-customer-transfer').textContent = formatPhp(preview.customerTransfer);
        document.getElementById('preview-counter-collect').textContent = formatPhp(preview.counterCollect);
        document.getElementById('preview-cash-payout').textContent = formatPhp(preview.cashPayout);
        document.getElementById('preview-wallet-transfer').textContent = formatPhp(preview.walletTransfer);
        document.getElementById('preview-net-cash').textContent = formatPhp(preview.netServiceCash, true);
        document.getElementById('preview-net-wallet').textContent = formatPhp(preview.netWallet, true);
    }

    window.GCashPreview = { calculateFee, calculatePreview, validatePrincipal };

    el('amount').addEventListener('input', updatePreview);
    el('type').addEventListener('change', updatePreview);
    el('fee-method')?.addEventListener('change', updatePreview);

    el('manual').addEventListener('click', () => { reference.focus(); reference.select(); if (reference.value.trim()) checkReference(); });
    el('form').addEventListener('submit', async event => {
        event.preventDefault();
        if (saving) return;
        updatePreview();
        const validPrincipal = validatePrincipal(el('amount').value);
        if (!validPrincipal) {
            notify('Enter a whole-peso principal amount from 1 to 50,000.', 'warning');
            el('amount').focus();
            return;
        }
        saving = true;
        updateSave();
        try {
            if (!await checkReference() || !el('form').reportValidity()) return;
            const body = {action: 'save', reference: reference.value, amount: el('amount').value, type: el('type').value};
            for (const input of el('form').elements) input.disabled = true;
            const data = await request(null, body);
            el('form').reset();
            updatePreview();
            duplicate = false;
            scanStatus('Saved as Pending. Open Verify in history after checking the actual GCash records.');
            notify('GCash transaction #' + data.id + ' saved as Pending.', 'success');
            page = 1;
            await loadHistory();
        } catch (error) {
            notify(error.message + ' Refresh history before retrying if the connection was interrupted.', 'error');
        } finally {
            saving = false;
            for (const input of el('form').elements) input.disabled = false;
            updateSave();
            updatePreview();
        }
    });
    const cell = (row, value) => { const td = row.insertCell(); td.textContent = value; return td; };
    async function loadHistory() {
        const version = ++historyRequest;
        const requestedPage = page;
        el('history-status').textContent = 'Loading transactions…';
        el('prev').disabled = el('next').disabled = true;
        try {
            const data = await request({page: requestedPage});
            if (version !== historyRequest || leaving) return;
            rows = data.transactions;
            el('history-body').replaceChildren();
            for (const transaction of rows) {
                const row = document.createElement('tr');
                const id = cell(row, '#' + transaction.transaction_id);
                const date = document.createElement('small');
                date.textContent = transaction.created_at;
                id.append(date);
                cell(row, transaction.reference_number);
                cell(row, transaction.transaction_type);
                cell(row, '₱' + Number(transaction.amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                const statusCell = cell(row, '');
                const status = document.createElement('span');
                status.className = 'gcash-status gcash-status-' + transaction.verification_status.toLowerCase();
                status.textContent = transaction.verification_status;
                statusCell.append(status);
                if (transaction.verification_note) {
                    const note = document.createElement('small');
                    note.textContent = transaction.verification_note + ' · ' + transaction.reviewed_at;
                    statusCell.append(note);
                }
                const actions = cell(row, '');
                if (transaction.verification_status === 'Pending') {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'btn btn-secondary'; button.textContent = 'Verify / Reject';
                    button.addEventListener('click', () => {
                        reviewRow = transaction;
                        el('review-form').reset();
                        el('review-summary').textContent = '#' + transaction.transaction_id + ' · ' + transaction.reference_number + ' · ' + transaction.transaction_type + ' · PHP ' + transaction.amount;
                        el('review-dialog').showModal();
                    });
                    actions.append(button);
                } else { actions.textContent = 'Reviewed'; }
                el('history-body').append(row);
            }
            el('history-status').textContent = rows.length ? rows.length + ' transactions on this page.' : 'No transactions on this page.';
            el('page').textContent = 'Page ' + requestedPage;
            el('prev').disabled = requestedPage <= 1;
            el('next').disabled = !data.has_next;
        } catch (error) {
            if (version !== historyRequest || leaving) return;
            el('history-status').textContent = error.message;
            notify(error.message, 'error');
        }
    }
    el('refresh').addEventListener('click', loadHistory);
    el('prev').addEventListener('click', () => { page--; loadHistory(); });
    el('next').addEventListener('click', () => { page++; loadHistory(); });
    el('review-cancel').addEventListener('click', () => el('review-dialog').close());
    el('review-dialog').addEventListener('cancel', event => { if (reviewing) event.preventDefault(); });
    async function review(status) {
        if (reviewing || !reviewRow || !el('review-form').reportValidity()) return;
        if (status === 'Verified' && !el('confirmed').checked) {
            notify('Confirm that you checked the actual GCash records first.', 'warning'); el('confirmed').focus(); return;
        }
        reviewing = true;
        for (const input of el('review-form').elements) input.disabled = true;
        try {
            await request(null, {action: 'review', id: Number(reviewRow.transaction_id), status, confirmed: el('confirmed').checked, note: el('review-note').value});
            el('review-dialog').close();
            notify('Transaction marked ' + status + '.', 'success');
            await loadHistory();
        } catch (error) { notify(error.message + ' Refresh history before retrying.', 'error'); }
        finally { reviewing = false; for (const input of el('review-form').elements) input.disabled = false; }
    }
    el('review-form').addEventListener('submit', event => { event.preventDefault(); review('Verified'); });
    el('reject').addEventListener('click', () => review('Rejected'));

    function stopCamera() {
        stream?.getTracks().forEach(track => track.stop()); stream = null;
        el('video').srcObject = null;
    }
    function closeCamera() {
        cameraVersion++;
        stopCamera();
        if (worker) { worker.terminate().catch(() => {}); worker = null; }
        ocrBusy = false;
        el('camera').hidden = true;
        el('capture').disabled = false;
    }
    el('camera-close').addEventListener('click', closeCamera);
    el('camera-open').addEventListener('click', async () => {
        closeCamera();
        const version = cameraVersion;
        el('camera').hidden = false;
        el('ocr-review').hidden = true;
        el('ocr-status').textContent = 'Requesting camera access…';
        el('capture').disabled = true;
        try {
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('Camera requires HTTPS or localhost. Use manual entry on this connection.');
            const capturedStream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}, width: {ideal: 1920}, height: {ideal: 1080}}, audio: false});
            if (version !== cameraVersion || leaving) { capturedStream.getTracks().forEach(track => track.stop()); return; }
            stream = capturedStream;
            el('video').srcObject = stream;
            await el('video').play();
            if (version !== cameraVersion) return;
            el('capture').disabled = false;
            el('ocr-status').textContent = 'Camera ready. Capture the reference label and number.';
        } catch (error) {
            if (version !== cameraVersion || leaving) return;
            stopCamera(); el('ocr-status').textContent = error.message + ' Manual entry is available.';
        }
    });
    let libraryPromise;
    function loadOCR() {
        if (window.Tesseract) return Promise.resolve();
        if (!libraryPromise) libraryPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@6.0.1/dist/tesseract.min.js';
            const timer = setTimeout(() => { script.remove(); libraryPromise = null; reject(new Error('OCR download timed out. Retry or use manual entry.')); }, 20000);
            script.onload = () => { clearTimeout(timer); resolve(); };
            script.onerror = () => { clearTimeout(timer); script.remove(); libraryPromise = null; reject(new Error('Free OCR could not load. Check internet access or enter the reference manually.')); };
            document.head.append(script);
        });
        return libraryPromise;
    }
    el('capture').addEventListener('click', async () => {
        if (ocrBusy || !stream || !el('video').videoWidth) return;
        ocrBusy = true; el('capture').disabled = true;
        const version = cameraVersion;
        const canvas = document.createElement('canvas');
        canvas.width = el('video').videoWidth; canvas.height = el('video').videoHeight;
        canvas.getContext('2d').drawImage(el('video'), 0, 0);
        stopCamera();
        el('ocr-status').textContent = 'Loading Tesseract.js — free OCR library — and reading receipt…';
        try {
            await loadOCR();
            if (version !== cameraVersion || leaving) return;
            const createdWorker = await window.Tesseract.createWorker('eng', 1, {logger: message => {
                if (version === cameraVersion && !leaving) el('ocr-status').textContent = message.status + (typeof message.progress === 'number' ? ' ' + Math.round(message.progress * 100) + '%' : '');
            }});
            if (version !== cameraVersion || leaving) { await createdWorker.terminate(); return; }
            worker = createdWorker;
            const result = await worker.recognize(canvas);
            if (version !== cameraVersion || leaving) return;
            const candidate = parser.extract(result.data.text, rules, true);
            el('ocr-candidate').value = candidate || '';
            el('ocr-review').hidden = false;
            el('ocr-status').textContent = candidate ? 'Suggested reference found. Check every digit before using it.' : 'No unambiguous labeled reference found. Type the reference manually or reopen the camera to retry.';
            el('ocr-candidate').focus();
        } catch (error) {
            if (version === cameraVersion && !leaving) { el('ocr-status').textContent = 'OCR could not read this receipt. Enter the reference manually or reopen the camera.'; notify(error.message, 'error'); }
        } finally {
            canvas.width = canvas.height = 0;
            if (version === cameraVersion) {
                if (worker) { worker.terminate().catch(() => {}); worker = null; }
                ocrBusy = false;
            }
        }
    });
    el('ocr-use').addEventListener('click', async () => {
        const candidate = parser.normalize(el('ocr-candidate').value);
        if (!parser.valid(candidate, rules)) { notify('Correct the suggested reference before using it.', 'warning'); return; }
        if (saving) return;
        reference.value = candidate;
        closeCamera(); reference.focus(); await checkReference();
    });
    window.addEventListener('pagehide', () => { leaving = true; clearTimeout(debounce); lookup?.abort(); closeCamera(); });
    updateSave(); updatePreview(); loadHistory();
})();
