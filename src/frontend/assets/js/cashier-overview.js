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
        status.textContent = 'Loading GCash overview…';
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

            if (expCash) expCash.textContent = formatPhp(data.expected_service_cash || 0);
            if (expWallet) expWallet.textContent = formatPhp(data.expected_wallet_balance || 0);

            const lastCheckedStr = data.last_checked_formatted ? ('Last actual check: ' + data.last_checked_formatted) : 'Last actual check: Not checked yet';
            if (cashChecked) cashChecked.textContent = lastCheckedStr;

            const ciPrin = document.getElementById('gcash-metric-cash-in-principal');
            const coPrin = document.getElementById('gcash-metric-cash-out-principal');
            const feesCash = document.getElementById('gcash-metric-fees-cash');
            const feesWallet = document.getElementById('gcash-metric-fees-wallet');

            if (ciPrin) ciPrin.textContent = formatPhp(data.cash_in_principal || 0);
            if (coPrin) coPrin.textContent = formatPhp(data.cash_out_principal || 0);
            if (feesCash) feesCash.textContent = formatPhp(data.gross_fees_cash || 0);
            if (feesWallet) feesWallet.textContent = formatPhp(data.gross_fees_wallet || 0);

        } catch (_) {
            status.textContent = 'GCash overview is unavailable. Try again later.';
        }
    }

})();
