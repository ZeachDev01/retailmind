(() => {
    'use strict';
    const workspace = document.getElementById('gcash-admin-workspace');
    if (!workspace) return;

    const tabs = [...workspace.querySelectorAll('.gcash-section-nav [role="tab"]')];
    function selectTab(tab, focus = false) {
        const target = tab.getAttribute('aria-controls');
        tabs.forEach(item => {
            const selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
        });
        tab.parentElement.scrollLeft += tab.getBoundingClientRect().left - tab.parentElement.getBoundingClientRect().left;
        const url = new URL(location.href);
        url.searchParams.set('tab', tab.getAttribute('aria-controls').replace('tab-', ''));
        history.replaceState(null, '', url);
        const tabInput = document.querySelector('#gcash-admin-filter-form [name="tab"]');
        if (tabInput) tabInput.value = url.searchParams.get('tab');
        document.getElementById('btn-reset-filters').search = new URLSearchParams({tab: url.searchParams.get('tab')});
        if (focus) {
            tab.focus({preventScroll: true});
            tab.scrollIntoView({block: 'nearest', inline: 'nearest'});
        }
        if (target === 'tab-money' && document.getElementById(target).dataset.loaded !== 'true' && focus) window.location.assign(url);
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            selectTab(tab, true);
        });
        tab.addEventListener('keydown', event => {
            const next = {ArrowRight: (index + 1) % tabs.length, ArrowLeft: (index + tabs.length - 1) % tabs.length, Home: 0, End: tabs.length - 1}[event.key];
            if (next === undefined) return;
            event.preventDefault();
            selectTab(tabs[next], true);
        });
    });
    selectTab(tabs.find(tab => tab.getAttribute('aria-selected') === 'true'));

    // Browser scroll restoration runs after initialization on reload.
    window.addEventListener('pageshow', () => {
        const tab = tabs.find(item => item.getAttribute('aria-selected') === 'true');
        requestAnimationFrame(() => {
            tab.parentElement.scrollLeft += tab.getBoundingClientRect().left - tab.parentElement.getBoundingClientRect().left;
        });
    });

    workspace.addEventListener('click', event => {
        const review = event.target.closest('[data-review-tab]');
        if (review) selectTab(document.getElementById('gcash-tab-' + review.dataset.reviewTab), true);
    });

    // Overview Coin View Controller (Balances <-> Activity & Completed)
    const coinDeck = document.getElementById('gcash-coin-deck');
    const coinPills = [...workspace.querySelectorAll('[data-coin-face]')];

    function switchCoinFace(targetFace, animate = true) {
        if (!coinDeck) return;
        const nextFace = targetFace;

        if (nextFace !== 'balances' && nextFace !== 'activity') return;

        coinDeck.dataset.activeFace = nextFace;

        coinPills.forEach(pill => {
            const isMatch = pill.dataset.coinFace === nextFace;
            pill.classList.toggle('active', isMatch);
            pill.setAttribute('aria-pressed', String(isMatch));
        });

        const balancesFace = document.getElementById('gcash-face-balances');
        const activityFace = document.getElementById('gcash-face-activity');

        if (balancesFace && activityFace) {
            balancesFace.hidden = (nextFace !== 'balances');
            activityFace.hidden = (nextFace !== 'activity');

            const activeFaceEl = nextFace === 'balances' ? balancesFace : activityFace;
            if (animate) {
                activeFaceEl.classList.remove('gcash-face-entering');
                void activeFaceEl.offsetWidth;
                activeFaceEl.classList.add('gcash-face-entering');
            }
        }

        try {
            sessionStorage.setItem('gcash_overview_face', nextFace);
        } catch (_) {}
    }

    coinPills.forEach(pill => {
        pill.addEventListener('click', () => switchCoinFace(pill.dataset.coinFace, true));
    });

    try {
        const urlParams = new URLSearchParams(window.location.search);
        const hasCustomPeriod = urlParams.has('period') && urlParams.get('period') !== 'today';
        const hasDateFilters = urlParams.has('start_date') || urlParams.has('end_date') || urlParams.get('face') === 'activity';
        if (!hasCustomPeriod && !hasDateFilters) {
            const savedFace = sessionStorage.getItem('gcash_overview_face');
            if (savedFace && (savedFace === 'balances' || savedFace === 'activity') && coinDeck.dataset.activeFace !== savedFace) {
                switchCoinFace(savedFace, false);
            }
        }
    } catch (_) {}

    function swapFragments(html) {
        for (const incoming of html.querySelectorAll('[data-fragment]')) {
            const name = incoming.dataset.fragment;
            const live = workspace.querySelector(`[data-fragment="${name}"]`);
            if (live) live.replaceWith(incoming.cloneNode(true));
        }
    }

    function markReviewsUnavailable() {
        document.getElementById('admin-nc-count').textContent = 'Unavailable';
        for (const [id, columns] of [['gcash-admin-diff-body', 8], ['gcash-admin-nc-table tbody', 9]]) {
            const body = workspace.querySelector('#' + id);
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = columns;
            cell.textContent = 'Review data unavailable. Refresh to try again.';
            cell.setAttribute('role', 'alert');
            row.append(cell);
            body.replaceChildren(row);
        }
        for (const name of ['reconciliation-badge', 'unresolved-badge']) workspace.querySelector(`[data-fragment="${name}"]`).textContent = 'Unavailable';
        for (const alert of workspace.querySelectorAll('#gcash-outstanding-reviews [data-review-tab]')) {
            alert.textContent = 'Review data unavailable. Refresh to try again';
            alert.disabled = true;
        }
    }

    const api = workspace.dataset.api;
    const csrf = workspace.dataset.csrf;
    const waiverDialog = document.getElementById('gcash-admin-waiver-dialog');
    const waiverForm = document.getElementById('admin-waiver-form');
    const waiverCancelBtn = document.getElementById('admin-waiver-cancel-btn');
    const waiverError = document.getElementById('admin-waiver-error');
    const waiverNotes = document.getElementById('admin-waiver-notes');

    const moneyFormatter = new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    function formatMoney(val) {
        const num = parseFloat(val);
        if (isNaN(num)) return '—';
        return (num < 0 ? '−' : '') + '₱' + moneyFormatter.format(Math.abs(num));
    }

    const filterForm = document.getElementById('gcash-admin-filter-form');
    const search = document.getElementById('gcash-search');
    const typeInput = document.getElementById('gcash-type-filter');
    const periodInput = document.getElementById('gcash-reporting-period');
    const startInput = document.getElementById('filter-start-date');
    const endInput = document.getElementById('filter-end-date');
    periodInput.addEventListener('change', () => {
        const today = workspace.querySelector('[data-today]').dataset.today;
        if (periodInput.value !== 'custom') {
            startInput.value = periodInput.value === 'month_to_date' ? today.slice(0, 7) + '-01' : today;
            endInput.value = today;
        }
    });
    [startInput, endInput].forEach(input => input.addEventListener('change', () => { periodInput.value = 'custom'; }));
    let searchTimer;
    let filterRequest;
    const fragmentsUrl = workspace.dataset.fragments;
    async function filterTransactions() {
        clearTimeout(searchTimer);
        filterRequest?.abort();
        const controller = new AbortController();
        filterRequest = controller;
        const url = new URL(fragmentsUrl, location.href);
        url.search = new URLSearchParams(new FormData(filterForm)).toString();
        const pageUrl = new URL(filterForm.action, location.href);
        pageUrl.search = url.search;
        const status = document.getElementById('gcash-filter-status');
        status.textContent = 'Loading selected report…';
        document.getElementById('gcash-completed-activity').setAttribute('aria-busy', 'true');
        document.getElementById('gcash-admin-history-table').setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {signal: controller.signal});
            if (!response.ok) throw new Error('Unable to load transactions. Try again.');
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (controller.signal.aborted || filterRequest !== controller) return false;
            const error = html.querySelector('.gcash-filter-error');
            if (error) throw new Error(error.textContent.trim());
            if (!html.querySelector('[data-fragment="transactions-body"]')) throw new Error('Unable to load transactions. Reload the page and try again.');
            const focusedType = document.activeElement?.closest?.('[data-type]')?.dataset.type;
            swapFragments(html);
            if (focusedType !== undefined) workspace.querySelector(`[data-type="${focusedType}"]`)?.focus({preventScroll: true});
            const count = document.getElementById('gcash-matching-record-count').textContent;
            pageUrl.searchParams.set('tab', workspace.querySelector('[role="tab"][aria-selected="true"]').getAttribute('aria-controls').replace('tab-', ''));
            history.replaceState(null, '', pageUrl);
            const exportLink = document.getElementById('gcash-export');
            if (exportLink) exportLink.href = pageUrl + '&export=csv';
            status.textContent = `${count} matching transactions`;
            return true;
        } catch (error) {
            if (error.name !== 'AbortError' && filterRequest === controller) {
                markReviewsUnavailable();
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 9;
                cell.textContent = 'Transaction history unavailable for this selection. Try again.';
                cell.setAttribute('role', 'alert');
                row.append(cell);
                document.getElementById('gcash-admin-history-body').replaceChildren(row);
                workspace.querySelector('[data-fragment="transactions-pagination"]').replaceChildren();
                workspace.querySelector('[data-fragment="transactions-badge"]').textContent = 'Unavailable';
                document.getElementById('gcash-matching-record-count').textContent = 'Unavailable';
                document.getElementById('gcash-session-status').textContent = 'Unavailable';
                document.getElementById('gcash-session-status').classList.remove('gcash-status-completed');
                for (const button of workspace.querySelectorAll('[data-type]')) button.textContent = `${button.dataset.type === 'all' ? 'All' : button.dataset.type} (Unavailable)`;
                workspace.querySelectorAll('#admin-expected-cash, #admin-expected-wallet').forEach(value => { value.textContent = 'Unavailable. Refresh to try again.'; });
                status.textContent = error.message + ' Report totals are unavailable for this selection.';
                workspace.querySelectorAll('#gcash-completed-activity .gcash-card-value, #admin-metric-total-tx').forEach(value => { value.textContent = 'Unavailable'; });
            }
            return false;
        } finally {
            if (filterRequest === controller) {
                document.getElementById('gcash-admin-history-table').removeAttribute('aria-busy');
                document.getElementById('gcash-completed-activity').removeAttribute('aria-busy');
            }
        }
    }
    filterForm.addEventListener('submit', event => {
        event.preventDefault();
        filterTransactions();
    });
    search?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        filterRequest?.abort();
        searchTimer = setTimeout(filterTransactions, 200);
    });
    workspace.addEventListener('click', event => {
        const button = event.target.closest('[data-type]');
        if (!button) return;
        typeInput.value = button.dataset.type;
        filterTransactions();
    });
    workspace.addEventListener('click', event => {
        const link = event.target.closest('#gcash-export');
        if (!link) return;
        const url = new URL(filterForm.action);
        url.search = new URLSearchParams(new FormData(filterForm)).toString();
        url.searchParams.set('export', 'csv');
        link.href = url;
    });
    document.getElementById('gcash-record')?.addEventListener('click', () => document.getElementById('gcash-record-dialog').showModal());
    workspace.querySelectorAll('dialog').forEach(dialog => {
        dialog.addEventListener('click', event => {
            if (event.target.closest('[data-close-dialog]')) dialog.close();
            if (event.target === dialog) {
                const rect = dialog.getBoundingClientRect();
                if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
            }
        });
    });
    const detailDialog = document.getElementById('txDetailModal');
    workspace.addEventListener('click', event => {
        const receipt = event.target.closest('.gcash-receipt');
        if (receipt) {
            window.viewReceipt(receipt.dataset.transactionId, receipt);
            return;
        }
        const row = event.target.closest('tr[data-transaction]');
        if (!row || event.target.closest('a')) return;
        const tx = JSON.parse(row.dataset.transaction);
        const fields = document.getElementById('tx-detail-fields');
        fields.replaceChildren();
        const signedMoney = value => (Number(value) < 0 ? '−' : '+') + formatMoney(Math.abs(Number(value)));
        const values = {
            'Status': tx.verification_status,
            'Reference No.': tx.reference_number || '—',
            'Timestamp': tx.time_formatted,
            'Cashier & Shift': `${tx.cashier_name || tx.cashier_username} / ${tx.shift_id === null ? 'Legacy reference' : '#' + tx.shift_id}`,
            'Exchange Type': tx.transaction_type,
            'Customer Principal': tx.is_legacy ? 'Legacy reference — ' + formatMoney(tx.amount) : formatMoney(tx.principal_amount),
            'Convenience Fee': tx.is_legacy ? 'Not recorded' : formatMoney(tx.gross_fee),
            'Fee Deducted At': tx.fee_location || 'Not recorded',
            'GCash Service Cash': tx.is_legacy ? 'Not recorded' : signedMoney(tx.service_cash_delta),
            'GCash App Balance': tx.is_legacy ? 'Not recorded' : signedMoney(tx.wallet_delta),
            'Notes': tx.notes || tx.verification_note || '—'
        };
        Object.entries(values).forEach(([label, value]) => {
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = label;
            description.textContent = value;
            if (label === 'GCash Service Cash' || label === 'GCash App Balance') {
                description.className = value.startsWith('−') ? 'gcash-variance-negative' : 'gcash-variance-positive';
            }
            fields.append(term, description);
        });
        for (const adjustment of tx.adjustments || []) {
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = `Linked adjustment #${adjustment.adjustment_id}`;
            description.textContent = `Service Cash ${signedMoney(adjustment.service_cash_delta)}; Service Wallet ${signedMoney(adjustment.wallet_delta)} — ${adjustment.reason}`;
            fields.append(term, description);
        }
        detailDialog.showModal();
    });

    workspace.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const row = event.target.closest('tr[data-transaction]');
        if (!row || event.target.closest('button, a, input, select, textarea')) return;
        event.preventDefault();
        row.click();
    });

    const verifyDialog = document.getElementById('gcash-verify-dialog');
    const verifyForm = document.getElementById('gcash-verify-form');
    workspace.addEventListener('click', event => {
        const button = event.target.closest('.gcash-verify');
        if (!button) return;
        verifyForm.reset();
        document.getElementById('gcash-verify-tx').value = button.dataset.transactionId;
        const cidEl = document.getElementById('gcash-verify-cashier-id');
        if (cidEl) cidEl.value = button.dataset.cashierId || '';
        if (button.dataset.confirmedCash === '1') {
            const cCb = document.getElementById('gcash-verify-cash');
            if (cCb) cCb.checked = true;
            const cFind = document.getElementById('gcash-verify-cash-finding');
            if (cFind) cFind.value = 'Physical service cash confirmed by responsible Cashier';
        }
        if (button.dataset.confirmedWallet === '1') {
            const wCb = document.getElementById('gcash-verify-wallet');
            if (wCb) wCb.checked = true;
            const wFind = document.getElementById('gcash-verify-wallet-finding');
            if (wFind) wFind.value = 'Wallet transfer confirmed in GCash app';
        }
        const verifyId = document.getElementById('gcash-verify-id');
        if (verifyId) verifyId.textContent = button.dataset.transactionId;
        document.getElementById('gcash-verify-reference').value = button.dataset.reference;
        document.getElementById('gcash-verify-error').textContent = '';
        verifyDialog.showModal();
    });
    verifyForm.addEventListener('submit', async event => {
        event.preventDefault();
        const button = verifyForm.querySelector('[type="submit"]');
        button.disabled = true;
        const originalBtnText = button.textContent;
        button.textContent = 'Verifying…';
        const autoPrint = document.getElementById('gcash-verify-print-receipt').checked;
        try {
            const response = await fetch(api, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({
                    action: 'resolve_exchange',
                    transaction_id: Number(document.getElementById('gcash-verify-tx').value),
                    reference: document.getElementById('gcash-verify-reference').value,
                    confirmed_wallet: document.getElementById('gcash-verify-wallet').checked,
                    confirmed_cash: document.getElementById('gcash-verify-cash').checked,
                    cash_finding: document.getElementById('gcash-verify-cash-finding') ? document.getElementById('gcash-verify-cash-finding').value : '',
                    wallet_finding: document.getElementById('gcash-verify-wallet-finding') ? document.getElementById('gcash-verify-wallet-finding').value : '',
                    cash_checked_by: document.getElementById('gcash-verify-cashier-id') && document.getElementById('gcash-verify-cashier-id').value ? Number(document.getElementById('gcash-verify-cashier-id').value) : undefined
                })
            });
            const result = await response.json();
            if (!response.ok || result.error) throw new Error(result.error || 'Unable to verify the exchange. Try again.');
            verifyDialog.close();
            await filterTransactions();
            if (result.receipt_id) await window.viewReceipt(result.receipt_id, document.getElementById('gcash-tab-unresolved'), autoPrint);
            button.disabled = false;
            button.textContent = originalBtnText;
        } catch (error) {
            document.getElementById('gcash-verify-error').textContent = error.message;
            button.disabled = false;
            button.textContent = originalBtnText;
        }
    });

    // Attach waiver dialog openers
    workspace.addEventListener('click', event => {
            const btn = event.target.closest('.admin-btn-waive');
            if (!btn) return;
            if (!waiverDialog) return;
            const sessId = btn.dataset.sessionId;
            const cashier = btn.dataset.cashier;
            const cashVar = parseFloat(btn.dataset.cashVariance) || 0;
            const walletVar = parseFloat(btn.dataset.walletVariance) || 0;

            document.getElementById('waiver-session-id').value = sessId;
            document.getElementById('waiver-target-session').textContent = sessId;
            document.getElementById('waiver-target-cashier').textContent = cashier;
            document.getElementById('waiver-target-cash-var').textContent = (cashVar > 0 ? '+' : '') + formatMoney(cashVar);
            document.getElementById('waiver-target-wallet-var').textContent = (walletVar > 0 ? '+' : '') + formatMoney(walletVar);

            waiverForm.reset();
            waiverNotes.value = '';
            if (waiverError) {
                waiverError.hidden = true;
                waiverError.textContent = '';
            }

            waiverDialog.showModal();
            waiverNotes.focus();
    });

    if (waiverCancelBtn && waiverDialog) {
        waiverCancelBtn.addEventListener('click', () => {
            waiverDialog.close();
        });
    }

    if (waiverForm && waiverDialog) {
        waiverForm.addEventListener('submit', async e => {
            e.preventDefault();
            const sessId = parseInt(document.getElementById('waiver-session-id').value, 10);
            const notes = waiverNotes.value.trim();

            if (notes.length < 5 || notes.length > 500) {
                if (waiverError) {
                    waiverError.textContent = 'Please enter review explanation between 5 and 500 characters.';
                    waiverError.hidden = false;
                }
                return;
            }

            const confirmBtn = document.getElementById('admin-waiver-confirm-btn');
            const originalConfirmText = confirmBtn ? confirmBtn.textContent : '';
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Saving…';
            }

            try {
                const res = await fetch(api, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        action: 'mark_reviewed',
                        cash_checked: waiverForm.elements.cash_checked.checked,
                        wallet_checked: waiverForm.elements.wallet_checked.checked,
                        cash_explanation: waiverForm.elements.cash_explanation.value,
                        wallet_explanation: waiverForm.elements.wallet_explanation.value,
                        session_id: sessId,
                        waiver_notes: notes
                    })
                });

                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Failed to record closing review.');
                }

                waiverDialog.close();

                await filterTransactions();
            } catch (err) {
                if (waiverError) {
                    waiverError.textContent = err.message || 'An unexpected error occurred.';
                    waiverError.hidden = false;
                }
            } finally {
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = originalConfirmText;
                }
            }
        });
    }
})();
