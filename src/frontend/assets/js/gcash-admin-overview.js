(() => {
    'use strict';
    const workspace = document.getElementById('gcash-admin-workspace');
    if (!workspace) return;

    const api = workspace.dataset.api;
    const csrf = workspace.dataset.csrf;
    const waiverDialog = document.getElementById('gcash-admin-waiver-dialog');
    const waiverForm = document.getElementById('admin-waiver-form');
    const waiverCancelBtn = document.getElementById('admin-waiver-cancel-btn');
    const waiverError = document.getElementById('admin-waiver-error');
    const waiverNotes = document.getElementById('admin-waiver-notes');

    function formatMoney(val) {
        const num = parseFloat(val);
        if (isNaN(num)) return '—';
        return '₱' + num.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // Attach waiver dialog openers
    document.querySelectorAll('.admin-btn-waive').forEach(btn => {
        btn.addEventListener('click', () => {
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

            waiverNotes.value = '';
            if (waiverError) {
                waiverError.hidden = true;
                waiverError.textContent = '';
            }

            if (typeof waiverDialog.showModal === 'function') {
                waiverDialog.showModal();
            } else {
                waiverDialog.setAttribute('open', '');
            }
        });
    });

    if (waiverCancelBtn && waiverDialog) {
        waiverCancelBtn.addEventListener('click', () => {
            if (typeof waiverDialog.close === 'function') {
                waiverDialog.close();
            } else {
                waiverDialog.removeAttribute('open');
            }
        });
    }

    if (waiverForm && waiverDialog) {
        waiverForm.addEventListener('submit', async e => {
            e.preventDefault();
            const sessId = parseInt(document.getElementById('waiver-session-id').value, 10);
            const notes = waiverNotes.value.trim();

            if (notes.length < 5 || notes.length > 500) {
                if (waiverError) {
                    waiverError.textContent = 'Please enter waiver notes between 5 and 500 characters.';
                    waiverError.hidden = false;
                }
                return;
            }

            const confirmBtn = document.getElementById('admin-waiver-confirm-btn');
            if (confirmBtn) confirmBtn.disabled = true;

            try {
                const res = await fetch(api, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        action: 'waive_discrepancy',
                        session_id: sessId,
                        waiver_notes: notes
                    })
                });

                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Failed to record discrepancy waiver.');
                }

                if (typeof waiverDialog.close === 'function') {
                    waiverDialog.close();
                } else {
                    waiverDialog.removeAttribute('open');
                }

                // Reload to refresh the tables and audit records
                window.location.reload();
            } catch (err) {
                if (waiverError) {
                    waiverError.textContent = err.message || 'An unexpected error occurred.';
                    waiverError.hidden = false;
                }
            } finally {
                if (confirmBtn) confirmBtn.disabled = false;
            }
        });
    }
})();
