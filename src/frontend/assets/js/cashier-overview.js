(() => {
    'use strict';
    const workspace = document.getElementById('cashier-overview');
    if (!workspace) return;
    const status = document.getElementById('gcash-overview-status');
    const formatPhp = value => new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(value);
    const tabs = [...workspace.querySelectorAll('[role="tab"]')];
    function selectTab(tab, focus = false) {
        for (const item of tabs) {
            const selected = item === tab;
            item.classList.toggle('active', selected);
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
        }
        if (focus) tab.focus();
        if (tab.id === 'tab-gcash') loadOverview();
        history.replaceState(null, '', tab.id === 'tab-gcash' ? '#gcash' : '#cashier');
        window.dispatchEvent(new Event('resize'));
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectTab(tab));
        tab.addEventListener('keydown', event => {
            let next;
            if (['ArrowRight', 'ArrowDown'].includes(event.key)) next = (index + 1) % tabs.length;
            if (['ArrowLeft', 'ArrowUp'].includes(event.key)) next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            selectTab(tabs[next], true);
        });
    });
    if (location.hash === '#gcash') selectTab(document.getElementById('tab-gcash'));
    async function loadOverview() {
        status.textContent = 'Loading GCash overview...';
        try {
            const response = await fetch(workspace.dataset.api, {headers: {Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok || !data.success) {
                status.textContent = data.message || 'GCash overview is unavailable. Try again later.';
                return;
            }
            status.textContent = '';
            const expCash = document.getElementById('gcash-overview-expected-cash');
            const expWallet = document.getElementById('gcash-overview-expected-wallet');
            const cashChecked = document.getElementById('gcash-overview-cash-checked');
            const walletChecked = document.getElementById('gcash-overview-wallet-checked');

            if (expCash) expCash.textContent = formatPhp(data.expected_service_cash || 0);
            if (expWallet) expWallet.textContent = formatPhp(data.expected_wallet_balance || 0);

            const lastCheckedStr = data.last_checked_formatted ? ('Last actual check: ' + data.last_checked_formatted) : 'Last actual check: Not checked yet';
            if (cashChecked) cashChecked.textContent = lastCheckedStr;
            if (walletChecked) walletChecked.textContent = lastCheckedStr;

            const ciPrin = document.getElementById('gcash-metric-cash-in-principal');
            const coPrin = document.getElementById('gcash-metric-cash-out-principal');
            const feesCash = document.getElementById('gcash-metric-fees-cash');
            const feesWallet = document.getElementById('gcash-metric-fees-wallet');

            if (ciPrin) ciPrin.textContent = formatPhp(data.cash_in_principal || 0);
            if (coPrin) coPrin.textContent = formatPhp(data.cash_out_principal || 0);
            if (feesCash) feesCash.textContent = formatPhp(data.gross_fees_cash || 0);
            if (feesWallet) feesWallet.textContent = formatPhp(data.gross_fees_wallet || 0);

            const ncCount = document.getElementById('gcash-overview-nc-count');
            const ncList = document.getElementById('gcash-overview-nc-list');

            if (ncCount) ncCount.textContent = (data.needs_checking_count || 0) + ' open';

            if (ncList) {
                ncList.replaceChildren();
                const items = data.needs_checking || [];
                if (items.length === 0) {
                    const empty = document.createElement('p');
                    empty.className = 'gcash-empty-msg';
                    empty.textContent = 'No unresolved Needs checking transactions.';
                    ncList.append(empty);
                } else {
                    for (const item of items) {
                        const card = document.createElement('div');
                        card.className = 'gcash-nc-card';
                        const details = document.createElement('div');
                        details.className = 'gcash-nc-details';

                        const title = document.createElement('div');
                        title.className = 'gcash-nc-title';
                        title.innerHTML = `<strong>#${item.transaction_id}</strong> · <span>${item.transaction_type}</span> <span class="gcash-status gcash-status-needs-checking">Needs checking</span>`;

                        const meta = document.createElement('div');
                        meta.className = 'gcash-nc-meta';
                        const prinAmt = formatPhp(item.principal_amount || item.amount || 0);
                        const feeAmt = formatPhp(item.gross_fee || item.fee_amount || 0);
                        const ref = item.reference_number || 'No reference';
                        const time = item.time_formatted || item.created_at;
                        const shift = item.shift_id ? ('Shift #' + item.shift_id) : 'No shift';
                        const cashier = item.cashier_name || ('Cashier #' + item.cashier_id);
                        meta.textContent = `Principal: ${prinAmt} · Fee: ${feeAmt} (${item.fee_method || 'cash'}) · Ref: ${ref} · ${cashier} · ${shift} · ${time}`;

                        details.append(title, meta);
                        if (item.notes) {
                            const notesEl = document.createElement('small');
                            notesEl.style.color = 'var(--muted)';
                            notesEl.textContent = item.notes;
                            details.append(notesEl);
                        }

                        const resolveBtn = document.createElement('a');
                        resolveBtn.href = workspace.dataset.history;
                        resolveBtn.className = 'btn btn-secondary';
                        resolveBtn.textContent = 'Review in GCash';

                        card.append(details, resolveBtn);
                        ncList.append(card);
                    }
                }
            }
        } catch (_) {
            status.textContent = 'GCash overview is unavailable. Try again later.';
        }
    }

})();
