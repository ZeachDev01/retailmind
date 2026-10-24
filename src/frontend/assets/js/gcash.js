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
    let currentSession = null, lastClosedSession = null, resolveTx = null, correctionTx = null, waiverSession = null;
    const notify = (message, type = 'info') => window.RetailMindUI.toast(message, type);
    const scanStatus = message => { el('scan-status').textContent = message; };
    const updateSave = () => {
        const canSave = Boolean(currentSession?.is_controlling_cashier);
        const type = el('type')?.value || 'Cash-In';
        const isCashIn = type === 'Cash-In';
        const isCashOut = type === 'Cash-Out';
        const validRef = parser.valid(reference.value, rules);
        const validPrincipal = validatePrincipal(el('amount')?.value) !== null;

        save.disabled = !canSave || saving || checking || duplicate || !validRef;

        const completeBtn = el('complete-cash-in');
        if (completeBtn) {
            completeBtn.hidden = !isCashIn;
            const walletConfirmed = Boolean(el('confirm-wallet')?.checked);
            const cashConfirmed = Boolean(el('confirm-cash')?.checked);
            completeBtn.disabled = !canSave || saving || checking || duplicate || !validRef || !validPrincipal || !walletConfirmed || !cashConfirmed;
        }

        const completeOutBtn = el('complete-cash-out');
        if (completeOutBtn) {
            completeOutBtn.hidden = !isCashOut;
            const walletConfirmed = Boolean(el('confirm-wallet')?.checked);
            const cashConfirmed = Boolean(el('confirm-cash')?.checked);
            completeOutBtn.disabled = !canSave || saving || checking || duplicate || !validRef || !validPrincipal || !walletConfirmed || !cashConfirmed;
        }

        const ncBtn = el('needs-checking-btn');
        if (ncBtn) {
            ncBtn.hidden = !(isCashIn || isCashOut);
            ncBtn.disabled = !canSave || saving || duplicate || !validPrincipal;
        }

        const confirmGroup = el('cash-in-confirm-group');
        if (confirmGroup) {
            confirmGroup.hidden = !validPrincipal || !(isCashIn || isCashOut);
        }
    };

    function disableTransactionForm(disabled) {
        reference.disabled = disabled;
        el('amount').disabled = disabled;
        el('type').disabled = disabled;
        if (el('fee-method')) el('fee-method').disabled = disabled;
        if (el('confirm-wallet')) el('confirm-wallet').disabled = disabled;
        if (el('confirm-cash')) el('confirm-cash').disabled = disabled;
        if (el('print-receipt')) el('print-receipt').disabled = disabled;
        el('manual').disabled = disabled;
        el('camera-open').disabled = disabled;
        updateSave();
    }

    function renderSessionStatus() {
        renderBalances();
        const banner = el('session-banner');
        banner.hidden = Boolean(currentSession?.is_controlling_cashier);
        const statusPill = el('session-status');
        const msg = el('session-message');
        const openBtn = el('open-session-btn');
        const closeBtn = el('close-session-btn');
        const handoverBtn = el('handover-session-btn');
        const fundingBtn = el('funding-btn');
        const waiveBtn = el('waive-btn');

        if (!currentSession) {
            statusPill.textContent = 'Closed';
            statusPill.className = 'gcash-status';
            banner.className = 'gcash-session-banner closed';
            msg.textContent = 'Open GCash to start. Count the service cash and check the Store wallet balance.';
            openBtn.hidden = false;
            closeBtn.hidden = true;
            handoverBtn.hidden = true;
            if (fundingBtn) fundingBtn.hidden = true;
            if (waiveBtn) {
                const hasVariance = lastClosedSession && (!lastClosedSession.waived_at) && (Number(lastClosedSession.cash_variance || 0) !== 0 || Number(lastClosedSession.wallet_variance || 0) !== 0);
                waiveBtn.hidden = !hasVariance;
            }
            disableTransactionForm(true);
            scanStatus('');
            return;
        }

        if (waiveBtn) waiveBtn.hidden = true;

        if (currentSession.is_controlling_cashier) {
            statusPill.textContent = 'Active (You)';
            statusPill.className = 'gcash-status gcash-status-verified';
            banner.className = 'gcash-session-banner active';
            msg.textContent = 'You are responsible for the GCash wallet and service cash box.';
            openBtn.hidden = true;
            closeBtn.hidden = false;
            handoverBtn.hidden = false;
            if (fundingBtn) fundingBtn.hidden = false;
            disableTransactionForm(false);
            scanStatus(duplicate ? 'Duplicate reference. Save blocked.' : '');
            return;
        }

        const cashierName = currentSession.cashier_name || ('Cashier #' + currentSession.cashier_id);
        statusPill.textContent = `Under control of ${cashierName}`;
        statusPill.className = 'gcash-status gcash-status-rejected';
        banner.className = 'gcash-session-banner locked';
        msg.textContent = `GCash is being handled by ${cashierName}. Only that Cashier can record transactions. Point of Sale remains available.`;
        openBtn.hidden = true;
        closeBtn.hidden = true;
        handoverBtn.hidden = true;
        if (fundingBtn) fundingBtn.hidden = true;
        disableTransactionForm(true);
        scanStatus(`Controlled by ${cashierName}. Transactions disabled.`);
    }

    async function loadSessionStatus() {
        try {
            const result = await request({action: 'session_status'});
            currentSession = result.session;
            lastClosedSession = result.last_closed_session || null;
            renderSessionStatus();
            el('session-panel').hidden = false;
        } catch (e) {
            currentSession = null;
            renderSessionStatus();
            el('balances-message').textContent = 'Balances unavailable. Reload to try again.';
            el('session-panel').hidden = true;
            notify(e.message || 'Could not verify GCash session status.', 'error');
        }
    }
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

    function renderBalances() {
        const active = Boolean(currentSession?.is_controlling_cashier);
        const principal = validatePrincipal(el('amount').value);
        el('projected-balances').hidden = !active || principal === null;
        if (!currentSession) {
            el('expected-wallet').textContent = '—';
            el('expected-cash').textContent = '—';
            el('cash-checked').textContent = 'Last check: —';
            el('balances-message').textContent = 'Open GCash to view the wallet and service cash balances.';
            return;
        }

        const wallet = Number(currentSession.expected_wallet_balance);
        const cash = Number(currentSession.expected_service_cash);
        el('expected-wallet').textContent = formatPhp(wallet, wallet < 0);
        el('expected-cash').textContent = formatPhp(cash, cash < 0);
        const checked = new Date(currentSession.opened_at.replace(' ', 'T') + '+08:00').toLocaleString('en-PH', {
            timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
        });
        el('cash-checked').textContent = 'Last check: ' + checked;
        el('balances-message').textContent = '';
        if (!active || principal === null) return;

        const preview = calculatePreview(el('type').value, principal, el('fee-method').value);
        const projectedWallet = Math.round((wallet + preview.netWallet) * 100) / 100;
        const projectedCash = Math.round((cash + preview.netServiceCash) * 100) / 100;
        el('projected-wallet').textContent = formatPhp(projectedWallet, projectedWallet < 0);
        el('projected-cash').textContent = formatPhp(projectedCash, projectedCash < 0);
    }

    function updatePreview() {
        renderBalances();
        el('confirm-wallet').checked = false;
        el('confirm-cash').checked = false;
        if (el('print-receipt')) el('print-receipt').checked = false;
        const type = el('type')?.value || 'Cash-In';
        const feeMethodGroup = el('fee-method-group');
        const isCashOut = type === 'Cash-Out';
        el('review-type').textContent = type;
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
            el('preview-empty').hidden = false;
            statusEl.hidden = false;
            if (raw === '') {
                statusEl.textContent = isCashOut ? 'Enter an amount to see the fee and cash to pay out.' : 'Enter an amount to see the fee and cash to collect.';
                amountInput.setCustomValidity('');
            } else {
                statusEl.textContent = 'Enter a whole-peso principal amount from 1 to 50,000. Principal excludes the fee.';
                amountInput.setCustomValidity('Enter a whole-peso principal amount from 1 to 50,000.');
            }
            updateSave();
            return;
        }

        amountInput.setCustomValidity('');
        const feeMethod = isCashOut ? (el('fee-method')?.value || 'Fee via GCash') : 'Fee in cash';
        const preview = calculatePreview(type, principal, feeMethod);

        statusEl.hidden = true;
        el('preview-empty').hidden = true;
        contentEl.hidden = false;
        el('preview-instructions').textContent = preview.instructions;
        document.getElementById('preview-fee').textContent = formatPhp(preview.fee);
        document.getElementById('preview-customer-transfer').textContent = formatPhp(preview.customerTransfer);
        document.getElementById('preview-counter-collect').textContent = formatPhp(preview.counterCollect);
        document.getElementById('preview-cash-payout').textContent = formatPhp(preview.cashPayout);
        document.getElementById('preview-wallet-transfer').textContent = formatPhp(preview.walletTransfer);
        document.getElementById('preview-customer-transfer').parentElement.hidden = !isCashOut;
        document.getElementById('preview-counter-collect').parentElement.hidden = preview.counterCollect === 0;
        document.getElementById('preview-cash-payout').parentElement.hidden = !isCashOut;
        document.getElementById('preview-wallet-transfer').parentElement.hidden = isCashOut;
        document.getElementById('preview-counter-label').textContent = isCashOut ? 'Collect cash fee from customer' : 'Collect cash from customer';
        document.getElementById('preview-fee-label').textContent = isCashOut
            ? (preview.feeLocation === 'cash' ? 'Service fee · paid in cash' : 'Service fee · included in GCash received')
            : 'Service fee · included in cash collected';
        document.getElementById('preview-net-cash').textContent = formatPhp(preview.netServiceCash, true);
        document.getElementById('preview-net-wallet').textContent = formatPhp(preview.netWallet, true);

        if (type === 'Cash-In') {
            if (el('confirm-wallet-text')) {
                el('confirm-wallet-text').textContent = 'I checked the authorized GCash app: ' + formatPhp(preview.walletTransfer) + ' sent to the customer.';
            }
            if (el('confirm-cash-text')) {
                el('confirm-cash-text').textContent = 'I collected ' + formatPhp(preview.counterCollect) + ' cash, including the service fee.';
            }
        } else {
            if (el('confirm-wallet-text')) {
                el('confirm-wallet-text').textContent = 'I checked the authorized GCash app: ' + formatPhp(preview.customerTransfer) + ' received from the customer.';
            }
            if (el('confirm-cash-text')) {
                if (feeMethod === 'Fee in cash') {
                    el('confirm-cash-text').textContent = 'I collected the ' + formatPhp(preview.counterCollect) + ' cash fee and paid ' + formatPhp(preview.cashPayout) + ' cash to the customer.';
                } else {
                    el('confirm-cash-text').textContent = 'I paid ' + formatPhp(preview.cashPayout) + ' cash to the customer.';
                }
            }
        }
        if (el('nc-preview-principal')) {
            el('nc-preview-principal').textContent = formatPhp(preview.principal);
        }
        if (el('nc-preview-fee')) {
            el('nc-preview-fee').textContent = formatPhp(preview.fee);
        }
        if (el('nc-cash-text')) {
            el('nc-cash-text').textContent = formatPhp(type === 'Cash-In' ? preview.counterCollect : (feeMethod === 'Fee in cash' ? (preview.cashPayout - preview.counterCollect) : preview.cashPayout));
        }
        if (el('nc-wallet-text')) {
            el('nc-wallet-text').textContent = formatPhp(type === 'Cash-In' ? preview.walletTransfer : preview.customerTransfer);
        }
        updateSave();
    }

    window.GCashPreview = { calculateFee, calculatePreview, validatePrincipal };

    el('amount').addEventListener('input', updatePreview);
    el('type').addEventListener('change', updatePreview);
    el('fee-method')?.addEventListener('change', updatePreview);
    el('confirm-wallet')?.addEventListener('change', updateSave);
    el('confirm-cash')?.addEventListener('change', updateSave);

    el('manual').addEventListener('click', () => { reference.focus(); reference.select(); if (reference.value.trim()) checkReference(); });

    if (el('complete-cash-in')) {
        el('complete-cash-in').addEventListener('click', async () => {
            if (saving) return;
            const validPrincipal = validatePrincipal(el('amount').value);
            if (!validPrincipal) {
                notify('Enter a whole-peso principal amount from 1 to 50,000.', 'warning');
                el('amount').focus();
                return;
            }
            if (!el('confirm-wallet')?.checked || !el('confirm-cash')?.checked) {
                notify('Confirm both wallet transfer and physical cash receipt before completing.', 'warning');
                return;
            }
            saving = true;
            updateSave();
            try {
                if (!await checkReference() || !el('form').reportValidity()) return;
                const preview = calculatePreview('Cash-In', validPrincipal);
                const body = {
                    action: 'record_cash_in',
                    outcome: 'Completed',
                    principal: validPrincipal,
                    reference: reference.value,
                    confirmed_wallet: true,
                    confirmed_cash: true,
                    preview_fee: preview.fee,
                    preview_counter_collect: preview.counterCollect,
                    preview_wallet_transfer: preview.walletTransfer,
                };
                const autoPrint = el('print-receipt')?.checked === true;
                for (const input of el('form').elements) input.disabled = true;
                const data = await request(null, body);
                el('form').reset();
                if (el('confirm-wallet')) el('confirm-wallet').checked = false;
                if (el('confirm-cash')) el('confirm-cash').checked = false;
                updatePreview();
                duplicate = false;
                scanStatus('Cash-In completed successfully.');
                notify('GCash Cash-In #' + data.id + ' completed.', 'success');
                if (data.receipt_id) await window.viewReceipt(data.receipt_id, el('amount'), autoPrint);
                page = 1;
                await loadHistory();
                await loadSessionStatus();
            } catch (error) {
                notify(error.message + ' Refresh history before retrying if the connection was interrupted.', 'error');
                await loadHistory();
                await loadSessionStatus();
            } finally {
                saving = false;
                if (!currentSession || currentSession.is_controlling_cashier) {
                    for (const input of el('form').elements) input.disabled = false;
                }
                updateSave();
                updatePreview();
            }
        });
    }

    if (el('complete-cash-out')) {
        el('complete-cash-out').addEventListener('click', async () => {
            if (saving) return;
            const validPrincipal = validatePrincipal(el('amount').value);
            if (!validPrincipal) {
                notify('Enter a whole-peso principal amount from 1 to 50,000.', 'warning');
                el('amount').focus();
                return;
            }
            if (!el('confirm-wallet')?.checked || !el('confirm-cash')?.checked) {
                notify('Confirm both customer wallet transfer and physical cash handover before completing.', 'warning');
                return;
            }
            saving = true;
            updateSave();
            try {
                if (!await checkReference() || !el('form').reportValidity()) return;
                const feeMethod = el('fee-method')?.value || 'Fee via GCash';
                const preview = calculatePreview('Cash-Out', validPrincipal, feeMethod);
                const body = {
                    action: 'record_cash_out',
                    outcome: 'Completed',
                    principal: validPrincipal,
                    fee_method: feeMethod,
                    reference: reference.value,
                    confirmed_wallet: true,
                    confirmed_cash: true,
                    preview_fee: preview.fee,
                    preview_customer_transfer: preview.customerTransfer,
                    preview_counter_collect: preview.counterCollect,
                    preview_cash_payout: preview.cashPayout,
                    net_service_cash: preview.netServiceCash,
                    net_wallet: preview.netWallet,
                };
                const autoPrint = el('print-receipt')?.checked === true;
                for (const input of el('form').elements) input.disabled = true;
                const data = await request(null, body);
                el('form').reset();
                if (el('confirm-wallet')) el('confirm-wallet').checked = false;
                if (el('confirm-cash')) el('confirm-cash').checked = false;
                updatePreview();
                duplicate = false;
                scanStatus('Cash-Out completed successfully.');
                notify('GCash Cash-Out #' + data.id + ' completed.', 'success');
                if (data.receipt_id) await window.viewReceipt(data.receipt_id, el('amount'), autoPrint);
                page = 1;
                await loadHistory();
                await loadSessionStatus();
            } catch (error) {
                notify(error.message + ' Refresh history before retrying if the connection was interrupted.', 'error');
                await loadHistory();
                await loadSessionStatus();
            } finally {
                saving = false;
                if (!currentSession || currentSession.is_controlling_cashier) {
                    for (const input of el('form').elements) input.disabled = false;
                }
                updateSave();
                updatePreview();
            }
        });
    }

    if (el('needs-checking-btn')) {
        el('needs-checking-btn').addEventListener('click', () => {
            const validPrincipal = validatePrincipal(el('amount').value);
            if (!validPrincipal) {
                notify('Enter a whole-peso principal amount from 1 to 50,000.', 'warning');
                el('amount').focus();
                return;
            }
            updatePreview();
            const type = el('type')?.value || 'Cash-In';
            const isCashOut = type === 'Cash-Out';
            const feeMethod = isCashOut ? (el('fee-method')?.value || 'Fee via GCash') : 'Fee in cash';
            const preview = calculatePreview(type, validPrincipal, feeMethod);

            if (isCashOut) {
                if (el('needs-checking-title')) el('needs-checking-title').textContent = 'Hold Cash-Out for checking';
                if (el('nc-cash-desc')) {
                    if (feeMethod === 'Fee in cash') {
                        el('nc-cash-desc').innerHTML = 'Cash payout handed over less cash fee collected (<span id="gcash-nc-cash-text">' + formatPhp(preview.cashPayout - preview.counterCollect) + '</span>)';
                    } else {
                        el('nc-cash-desc').innerHTML = 'Cash payout handed over (<span id="gcash-nc-cash-text">' + formatPhp(preview.cashPayout) + '</span>)';
                    }
                }
                if (el('nc-wallet-desc')) {
                    el('nc-wallet-desc').innerHTML = 'Customer wallet transfer received (<span id="gcash-nc-wallet-text">' + formatPhp(preview.customerTransfer) + '</span>)';
                }
            } else {
                if (el('needs-checking-title')) el('needs-checking-title').textContent = 'Hold Cash-In for checking';
                if (el('nc-cash-desc')) {
                    el('nc-cash-desc').innerHTML = 'Cash collected from customer (<span id="gcash-nc-cash-text">' + formatPhp(preview.counterCollect) + '</span>)';
                }
                if (el('nc-wallet-desc')) {
                    el('nc-wallet-desc').innerHTML = 'Wallet transfer sent to customer (<span id="gcash-nc-wallet-text">' + formatPhp(preview.walletTransfer) + '</span>)';
                }
            }

            el('nc-reference').value = reference.value.trim();
            el('nc-cash-collected').checked = Boolean(el('confirm-cash')?.checked);
            el('nc-wallet-sent').checked = Boolean(el('confirm-wallet')?.checked);
            el('nc-notes').value = '';
            el('needs-checking-dialog').showModal();
            el('nc-notes').focus();
        });
        el('nc-cancel')?.addEventListener('click', () => el('needs-checking-dialog').close());
        el('needs-checking-form')?.addEventListener('submit', async event => {
            event.preventDefault();
            const validPrincipal = validatePrincipal(el('amount').value);
            if (!validPrincipal) {
                notify('Enter a whole-peso principal amount from 1 to 50,000.', 'warning');
                return;
            }
            const notes = el('nc-notes').value.trim();
            if (notes.length < 5) {
                notify('Enter a reason for checking (at least 5 characters).', 'warning');
                el('nc-notes').focus();
                return;
            }
            const type = el('type')?.value || 'Cash-In';
            const isCashOut = type === 'Cash-Out';
            const feeMethod = isCashOut ? (el('fee-method')?.value || 'Fee via GCash') : 'Fee in cash';
            const refVal = el('nc-reference').value.trim();
            const preview = calculatePreview(type, validPrincipal, feeMethod);
            const submitBtn = el('nc-submit');
            submitBtn.disabled = true;
            try {
                const body = {
                    action: isCashOut ? 'record_cash_out' : 'record_cash_in',
                    outcome: 'Needs checking',
                    principal: validPrincipal,
                    fee_method: isCashOut ? feeMethod : 'cash',
                    reference: refVal || null,
                    confirmed_cash: el('nc-cash-collected').checked,
                    confirmed_wallet: el('nc-wallet-sent').checked,
                    notes: notes,
                    preview_fee: preview.fee,
                    preview_counter_collect: preview.counterCollect,
                    preview_customer_transfer: preview.customerTransfer,
                    preview_cash_payout: preview.cashPayout,
                    preview_wallet_transfer: preview.walletTransfer,
                    net_service_cash: preview.netServiceCash,
                    net_wallet: preview.netWallet,
                };
                const data = await request(null, body);
                el('needs-checking-dialog').close();
                el('form').reset();
                if (el('confirm-wallet')) el('confirm-wallet').checked = false;
                if (el('confirm-cash')) el('confirm-cash').checked = false;
                updatePreview();
                duplicate = false;
                notify((isCashOut ? 'Cash-Out' : 'Cash-In') + ' held as Needs checking (#' + data.id + ').', 'success');
                page = 1;
                await loadHistory();
                await loadSessionStatus();
            } catch (error) {
                notify(error.message + ' Refresh history before retrying.', 'error');
                await loadHistory();
                await loadSessionStatus();
            } finally {
                submitBtn.disabled = false;
            }
        });
    }

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
            await loadSessionStatus();
        } catch (error) {
            notify(error.message + ' Refresh history before retrying if the connection was interrupted.', 'error');
        } finally {
            saving = false;
            if (!currentSession || currentSession.is_controlling_cashier) {
                for (const input of el('form').elements) input.disabled = false;
            }
            updateSave();
            updatePreview();
        }
    });
    const cell = (row, value) => { const td = row.insertCell(); td.textContent = value; return td; };

    const tabNewTx = document.getElementById('tab-new-transaction');
    const tabHistory = document.getElementById('tab-history');
    const panelNewTx = document.getElementById('panel-new-transaction');
    const panelHistory = document.getElementById('panel-history');
    const tabs = [
        { id: 'new-transaction', btn: tabNewTx, panel: panelNewTx },
        { id: 'history', btn: tabHistory, panel: panelHistory }
    ];

    function switchTab(targetId, focusTarget = false) {
        tabs.forEach(t => {
            if (!t.btn || !t.panel) return;
            const isTarget = t.id === targetId;
            t.btn.classList.toggle('active', isTarget);
            t.btn.setAttribute('aria-selected', isTarget ? 'true' : 'false');
            t.btn.setAttribute('tabindex', isTarget ? '0' : '-1');
            t.panel.hidden = !isTarget;
            if (isTarget && focusTarget) t.btn.focus();
        });
        if (targetId === 'history') loadHistory();
    }

    tabs.forEach((t, idx) => {
        if (!t.btn) return;
        t.btn.addEventListener('click', () => switchTab(t.id));
        t.btn.addEventListener('keydown', e => {
            let nextIdx = -1;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                nextIdx = (idx + 1) % tabs.length;
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                nextIdx = (idx - 1 + tabs.length) % tabs.length;
            } else if (e.key === 'Home') {
                nextIdx = 0;
            } else if (e.key === 'End') {
                nextIdx = tabs.length - 1;
            }
            if (nextIdx !== -1) {
                e.preventDefault();
                switchTab(tabs[nextIdx].id, true);
            }
        });
    });

    function selectAndStart(type) {
        switchTab('new-transaction');
        const typeSelect = el('type');
        if (typeSelect) {
            typeSelect.value = type;
            updatePreview();
        }
        el('amount')?.focus();
    }

    ['history-action-cash-in'].forEach(id => {
        const btn = document.getElementById('gcash-' + id) || document.getElementById(id);
        if (btn) btn.addEventListener('click', () => selectAndStart('Cash-In'));
    });
    ['history-action-cash-out'].forEach(id => {
        const btn = document.getElementById('gcash-' + id) || document.getElementById(id);
        if (btn) btn.addEventListener('click', () => selectAndStart('Cash-Out'));
    });

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
                cell(row, transaction.time_formatted || transaction.created_at);
                const details = document.createElement('details');
                details.className = 'gcash-history-details';
                const summary = document.createElement('summary');
                summary.textContent = 'Details';
                details.append(summary);
                const identity = document.createElement('small');
                identity.textContent = '#' + transaction.transaction_id + ' · ' + (transaction.shift_id ? 'Shift #' + transaction.shift_id : 'No shift');
                details.append(identity);
                const cashierInfo = document.createElement('small');
                cashierInfo.textContent = transaction.cashier_name || ('Cashier #' + transaction.cashier_id);
                details.append(cashierInfo);

                cell(row, transaction.reference_number || '—');
                cell(row, transaction.transaction_type);

                const amountCell = cell(row, '');
                const feeCell = cell(row, '—');
                if (transaction.is_legacy) {
                    amountCell.textContent = formatPhp(transaction.amount);
                    const legInfo = document.createElement('small');
                    legInfo.textContent = 'Legacy reference record';
                    details.append(legInfo);
                } else {
                    amountCell.textContent = formatPhp(transaction.principal_amount || transaction.amount);
                    const feeInfo = document.createElement('small');
                    const fee = formatPhp(transaction.gross_fee || transaction.fee_amount || 0);
                    feeCell.textContent = fee;
                    const feeMethod = transaction.fee_method || 'cash';
                    feeInfo.textContent = `Fee: ${fee} (${feeMethod})`;
                    details.append(feeInfo);

                    if (Number(transaction.service_cash_delta || 0) !== 0 || Number(transaction.wallet_delta || 0) !== 0) {
                        const deltas = document.createElement('small');
                        const cashDelta = formatPhp(Number(transaction.service_cash_delta || 0), true);
                        const walletDelta = formatPhp(Number(transaction.wallet_delta || 0), true);
                        deltas.textContent = `Cash: ${cashDelta} · Wallet: ${walletDelta}`;
                        details.append(deltas);
                    }
                }
                if (transaction.adjustments && transaction.adjustments.length > 0) {
                    for (const adj of transaction.adjustments) {
                        const adjInfo = document.createElement('small');
                        adjInfo.style.color = 'var(--color-warning, #d97706)';
                        adjInfo.textContent = `Adj #${adj.adjustment_id}: Cash ${formatPhp(adj.service_cash_delta, true)} · Wallet ${formatPhp(adj.wallet_delta, true)} (${adj.reason})`;
                        details.append(adjInfo);
                    }
                }

                const statusCell = cell(row, '');
                const status = document.createElement('span');
                const statusKey = String(transaction.verification_status).toLowerCase().replace(/\s+/g, '-');
                status.className = 'gcash-status gcash-status-' + statusKey;
                status.textContent = transaction.verification_status;
                statusCell.append(status);
                if (transaction.is_legacy || transaction.session_id === null || transaction.session_id === undefined) {
                    const legacy = document.createElement('span');
                    legacy.className = 'gcash-badge gcash-badge-legacy';
                    legacy.textContent = 'Legacy Reference';
                    statusCell.append(legacy);
                }
                if (transaction.notes) {
                    const note = document.createElement('small');
                    note.textContent = transaction.notes;
                    details.append(note);
                }
                if (transaction.verification_note) {
                    const note = document.createElement('small');
                    note.textContent = transaction.verification_note + ' · ' + (transaction.reviewed_at_formatted || transaction.reviewed_at);
                    details.append(note);
                }
                const actionCell = cell(row, '');
                const actions = document.createElement('div');
                actions.className = 'gcash-history-row-actions';
                actionCell.append(actions, details);
                if (transaction.has_receipt) {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'btn btn-secondary'; button.textContent = 'Receipt';
                    button.addEventListener('click', () => window.viewReceipt(transaction.transaction_id, button));
                    actions.append(button);
                }
                if (transaction.verification_status === 'Pending') {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'btn btn-secondary'; button.textContent = 'Verify / Reject';
                    button.addEventListener('click', () => {
                        reviewRow = transaction;
                        el('review-form').reset();
                        el('review-summary').textContent = '#' + transaction.transaction_id + ' · ' + (transaction.reference_number || 'No ref') + ' · ' + transaction.transaction_type + ' · PHP ' + transaction.amount;
                        el('review-dialog').showModal();
                    });
                    actions.append(button);
                } else if (transaction.verification_status === 'Needs checking') {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-secondary';
                    button.textContent = 'Resolve';
                    button.addEventListener('click', () => {
                        openResolveDialog(transaction);
                    });
                    actions.append(button);
                } else if (transaction.verification_status === 'Completed') {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-secondary';
                    button.textContent = 'Correct';
                    button.addEventListener('click', () => {
                        openCorrectionDialog(transaction);
                    });
                    actions.append(button);
                } else { actions.textContent = 'Reviewed'; }
                el('history-body').append(row);
            }
            el('history-status').textContent = rows.length ? '' : 'No transactions on this page.';
            el('page').textContent = 'Page ' + requestedPage;
            el('prev').disabled = requestedPage <= 1;
            el('next').disabled = !data.has_next;
        } catch (error) {
            if (version !== historyRequest || leaving) return;
            el('history-status').textContent = error.message;
            notify(error.message, 'error');
        }
    }
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
            await loadSessionStatus();
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
        el('ocr-status').textContent = 'Reading receipt…';
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
    // Session dialogs
    el('open-session-btn').addEventListener('click', () => {
        el('open-cash').value = '';
        el('open-wallet').value = '';
        el('open-dialog').showModal();
        el('open-cash').focus();
    });
    el('open-cancel').addEventListener('click', () => el('open-dialog').close());
    el('open-form').addEventListener('submit', async event => {
        event.preventDefault();
        const cash = Number(el('open-cash').value);
        const wallet = Number(el('open-wallet').value);
        if (isNaN(cash) || cash < 0 || isNaN(wallet) || wallet < 0) {
            notify('Enter opening service cash and wallet balance (0 or greater).', 'warning');
            return;
        }
        el('open-submit').disabled = true;
        try {
            await request(null, {action: 'open_session', opening_cash: cash, opening_wallet: wallet});
            el('open-dialog').close();
            notify('GCash responsibility opened.', 'success');
            await loadSessionStatus();
        } catch (error) {
            notify(error.message, 'error');
        } finally {
            el('open-submit').disabled = false;
        }
    });

    function updateCloseVariances() {
        const expCash = Number(currentSession?.expected_service_cash || 0);
        const expWallet = Number(currentSession?.expected_wallet_balance || 0);
        const actCash = Number(el('close-cash').value || 0);
        const actWallet = Number(el('close-wallet').value || 0);
        const cashVar = Math.round((actCash - expCash) * 100) / 100;
        const walletVar = Math.round((actWallet - expWallet) * 100) / 100;

        const cashEl = el('close-cash-variance');
        if (cashVar > 0) {
            cashEl.textContent = `+₱${cashVar.toFixed(2)} (Excess)`;
            cashEl.className = 'gcash-variance-positive';
        } else if (cashVar < 0) {
            cashEl.textContent = `-₱${Math.abs(cashVar).toFixed(2)} (Shortage)`;
            cashEl.className = 'gcash-variance-negative';
        } else {
            cashEl.textContent = '₱0.00 (Balanced)';
            cashEl.className = 'gcash-variance-zero';
        }

        const walletEl = el('close-wallet-variance');
        if (walletVar > 0) {
            walletEl.textContent = `+₱${walletVar.toFixed(2)} (Excess)`;
            walletEl.className = 'gcash-variance-positive';
        } else if (walletVar < 0) {
            walletEl.textContent = `-₱${Math.abs(walletVar).toFixed(2)} (Shortage)`;
            walletEl.className = 'gcash-variance-negative';
        } else {
            walletEl.textContent = '₱0.00 (Balanced)';
            walletEl.className = 'gcash-variance-zero';
        }
    }

    el('close-session-btn').addEventListener('click', () => {
        if (!currentSession) return;
        el('close-expected-cash').textContent = '₱' + Number(currentSession.expected_service_cash || 0).toLocaleString('en-PH', {minimumFractionDigits: 2});
        el('close-expected-wallet').textContent = '₱' + Number(currentSession.expected_wallet_balance || 0).toLocaleString('en-PH', {minimumFractionDigits: 2});
        el('close-cash').value = '';
        el('close-wallet').value = '';
        el('close-carryover').value = '0';
        el('close-notes').value = '';
        updateCloseVariances();
        el('close-dialog').showModal();
        el('close-cash').focus();
    });
    el('close-cancel').addEventListener('click', () => el('close-dialog').close());
    el('close-cash').addEventListener('input', updateCloseVariances);
    el('close-wallet').addEventListener('input', updateCloseVariances);
    el('close-form').addEventListener('submit', async event => {
        event.preventDefault();
        const cash = Number(el('close-cash').value);
        const wallet = Number(el('close-wallet').value);
        if (isNaN(cash) || cash < 0 || isNaN(wallet) || wallet < 0) {
            notify('Enter closing service cash and wallet balance (0 or greater).', 'warning');
            return;
        }
        el('close-submit').disabled = true;
        try {
            await request(null, {
                action: 'close_session',
                closing_cash: cash,
                closing_wallet: wallet,
                carryover: Number(el('close-carryover').value || 0),
                notes: el('close-notes').value
            });
            el('close-dialog').close();
            notify('GCash responsibility closed.', 'success');
            await loadSessionStatus();
        } catch (error) {
            notify(error.message, 'error');
        } finally {
            el('close-submit').disabled = false;
        }
    });

    if (el('handover-session-btn')) {
        el('handover-session-btn').addEventListener('click', () => {
            el('handover-cash').value = '';
            el('handover-wallet').value = '';
            el('handover-notes').value = '';
            el('handover-dialog').showModal();
        });
        el('handover-cancel').addEventListener('click', () => el('handover-dialog').close());
        el('handover-form').addEventListener('submit', async event => {
            event.preventDefault();
            const incomingId = Number(el('handover-incoming').value);
            const cash = Number(el('handover-cash').value);
            const wallet = Number(el('handover-wallet').value);
            if (!incomingId) {
                notify('Select an incoming Cashier.', 'warning');
                return;
            }
            if (isNaN(cash) || cash < 0 || isNaN(wallet) || wallet < 0) {
                notify('Enter counted cash and checked wallet balance (0 or greater).', 'warning');
                return;
            }
            el('handover-submit').disabled = true;
            try {
                await request(null, {
                    action: 'handover_session',
                    incoming_cashier_id: incomingId,
                    counted_cash: cash,
                    checked_wallet: wallet,
                    notes: el('handover-notes').value
                });
                el('handover-dialog').close();
                notify('GCash responsibility handed over.', 'success');
                await loadSessionStatus();
            } catch (error) {
                notify(error.message, 'error');
            } finally {
                el('handover-submit').disabled = false;
            }
        });
    }

    if (el('funding-btn')) {
        el('funding-btn').addEventListener('click', () => {
            el('funding-form').reset();
            el('funding-dialog').showModal();
        });
        el('funding-cancel')?.addEventListener('click', () => el('funding-dialog').close());
        el('funding-form')?.addEventListener('submit', async event => {
            event.preventDefault();
            const form = el('funding-form');
            const submitBtn = el('funding-submit');
            submitBtn.disabled = true;
            try {
                const body = {
                    action: 'funding_movement',
                    movement_type: form.movement_type.value,
                    fund_type: form.fund_type.value,
                    direction: form.direction.value,
                    amount: form.amount.value,
                    evidence_reference: form.evidence_reference.value,
                    reason: form.reason.value,
                    approver_username: form.approver_username.value,
                    approver_password: form.approver_password.value,
                };
                const res = await request(null, body);
                el('funding-dialog').close();
                notify('Funding movement #' + res.movement_id + ' recorded.', 'success');
                await loadSessionStatus();
                await loadHistory();
            } catch (err) {
                notify(err.message, 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });
    }

    function openResolveDialog(tx) {
        resolveTx = tx;
        el('resolve-form').reset();
        el('resolve-summary').textContent = `#${tx.transaction_id} · ${tx.transaction_type} · PHP ${tx.amount} (Cash delta: ${tx.service_cash_delta}, Wallet delta: ${tx.wallet_delta})`;
        el('resolve-ref-group').hidden = false;
        el('resolve-print-choice').hidden = false;
        el('resolve-auth-group').hidden = true;
        el('resolve-action').value = 'complete';
        el('resolve-reference').value = tx.reference_number || '';
        el('resolve-dialog').showModal();
    }
    el('resolve-action')?.addEventListener('change', () => {
        const isCancel = el('resolve-action').value === 'cancel';
        const hadMoney = resolveTx && (Number(resolveTx.service_cash_delta || 0) !== 0 || Number(resolveTx.wallet_delta || 0) !== 0);
        el('resolve-ref-group').hidden = isCancel;
        el('resolve-print-choice').hidden = isCancel;
        el('resolve-auth-group').hidden = !isCancel || !hadMoney;
        el('resolve-approver').required = isCancel && hadMoney;
        el('resolve-password').required = isCancel && hadMoney;
    });
    el('resolve-cancel')?.addEventListener('click', () => el('resolve-dialog').close());
    el('resolve-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!resolveTx) return;
        const form = el('resolve-form');
        const submitBtn = el('resolve-submit');
        submitBtn.disabled = true;
        try {
            const body = {
                action: 'resolve_needs_checking',
                transaction_id: resolveTx.transaction_id,
                resolve_action: form.resolve_action.value,
                reference: form.reference.value,
                notes: form.notes.value,
                approver_username: form.approver_username.value,
                approver_password: form.approver_password.value,
            };
            const autoPrint = el('resolve-print-receipt').checked;
            const result = await request(null, body);
            el('resolve-dialog').close();
            notify('Transaction #' + resolveTx.transaction_id + ' resolved.', 'success');
            await loadSessionStatus();
            await loadHistory();
            if (result.receipt_id) await window.viewReceipt(result.receipt_id, document.getElementById('tab-history'), autoPrint);
        } catch (err) {
            notify(err.message, 'error');
        } finally {
            submitBtn.disabled = false;
        }
    });

    function openCorrectionDialog(tx) {
        correctionTx = tx;
        el('correction-form').reset();
        el('correction-summary').textContent = `#${tx.transaction_id} · Ref: ${tx.reference_number || 'N/A'} · ${tx.transaction_type} · PHP ${tx.amount}`;
        el('correction-cash-delta').value = '0.00';
        el('correction-wallet-delta').value = '0.00';
        el('correction-dialog').showModal();
    }
    el('correction-cancel')?.addEventListener('click', () => el('correction-dialog').close());
    el('correction-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!correctionTx) return;
        const form = el('correction-form');
        const submitBtn = el('correction-submit');
        submitBtn.disabled = true;
        try {
            const body = {
                action: 'linked_correction',
                transaction_id: correctionTx.transaction_id,
                service_cash_delta: form.service_cash_delta.value,
                wallet_delta: form.wallet_delta.value,
                reason: form.reason.value,
                approver_username: form.approver_username.value,
                approver_password: form.approver_password.value,
            };
            const res = await request(null, body);
            el('correction-dialog').close();
            notify('Linked correction #' + res.adjustment_id + ' recorded.', 'success');
            await loadSessionStatus();
            await loadHistory();
        } catch (err) {
            notify(err.message, 'error');
        } finally {
            submitBtn.disabled = false;
        }
    });

    function openWaiveDialog(session) {
        waiverSession = session;
        el('waiver-form').reset();
        el('waiver-summary').textContent = `Session #${session.session_id} · Cash variance: PHP ${session.cash_variance} · Wallet variance: PHP ${session.wallet_variance}`;
        el('waiver-dialog').showModal();
    }
    el('waive-btn')?.addEventListener('click', () => {
        if (lastClosedSession) openWaiveDialog(lastClosedSession);
    });
    el('waiver-cancel')?.addEventListener('click', () => el('waiver-dialog').close());
    el('waiver-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!waiverSession) return;
        const form = el('waiver-form');
        const submitBtn = el('waiver-submit');
        submitBtn.disabled = true;
        try {
            const body = {
                action: 'waive_discrepancy',
                session_id: waiverSession.session_id,
                waiver_notes: form.waiver_notes.value,
                approver_username: form.approver_username.value,
                approver_password: form.approver_password.value,
            };
            await request(null, body);
            el('waiver-dialog').close();
            notify('Discrepancy for session #' + waiverSession.session_id + ' approved/waived.', 'success');
            await loadSessionStatus();
        } catch (err) {
            notify(err.message, 'error');
        } finally {
            submitBtn.disabled = false;
        }
    });

    window.addEventListener('pagehide', () => { leaving = true; clearTimeout(debounce); lookup?.abort(); closeCamera(); });
    const params = new URLSearchParams(window.location.search);
    if (params.get('type') === 'cash-out') el('type').value = 'Cash-Out';
    if (params.get('tab') === 'history') switchTab('history');
    updateSave(); updatePreview(); loadHistory(); loadSessionStatus();
})();

