(() => {
    const script = document.currentScript;
    const {endpoint, closeUrl, cashier} = script.dataset;
    if (window.rmShiftReminder) return;
    window.rmShiftReminder = true;
    let loading = false;
    let showing = false;
    let snoozedUntil = 0;
    const keyFor = status => 'retailmind.shift-reminder.' + cashier + '.' + status.shift_id;
    function unsafe(status) {
        return document.hidden || status.locked || window.cartWorkspace?.busy ||
            document.activeElement?.id === 'cash-received' ||
            window.__retailMindActionInProgress === true || window.Swal?.isVisible() ||
            !!document.querySelector('.main-content[aria-busy="true"]') ||
            Array.from(document.querySelectorAll('dialog[open], [role="dialog"]')).some(dialog =>
                dialog.getClientRects().length > 0 && getComputedStyle(dialog).visibility !== 'hidden') ||
            new URL(location.href).pathname.endsWith('/shifts.php') && new URL(location.href).searchParams.get('tab') === 'close';
    }
    async function check() {
        if (loading || showing || document.hidden) return;
        loading = true;
        const startedAt = Date.now();
        try {
            const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) return;
            const status = await response.json();
            if (!status.shift_id || status.due_at === null || status.now < status.due_at || unsafe(status) || !window.Swal) return;
            let saved = 0;
            try { saved = Number(localStorage.getItem(keyFor(status))) || 0; } catch (_) {}
            if (Math.max(saved, snoozedUntil) > status.now) return;
            showing = true;
            const choice = await Swal.fire({
                icon: 'info', title: 'Close your shift',
                text: 'It is time to count your drawer and close your Cashier Shift.',
                showCancelButton: true, confirmButtonText: 'Close shift', cancelButtonText: 'Later',
                allowOutsideClick: false, allowEscapeKey: false,
                buttonsStyling: false,
                customClass: {confirmButton: 'btn btn-primary rm-swal-confirm', cancelButton: 'btn btn-quiet rm-swal-cancel'},
                hideClass: {popup: '', backdrop: ''},
            });
            if (!choice.isConfirmed && choice.dismiss !== Swal.DismissReason.cancel) return;
            snoozedUntil = status.now + Math.ceil((Date.now() - startedAt) / 1000) + 600;
            try { localStorage.setItem(keyFor(status), String(snoozedUntil)); } catch (_) {}
            if (choice.isConfirmed && (!window.cartWorkspace || await window.cartWorkspace.resolveWork())) {
                window.RetailMindUI.navigate(closeUrl);
            }
        } catch (_) {
            // A later poll retries without interrupting the Cashier's current work.
        } finally { loading = false; showing = false; }
    }
    const poll = () => check();
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    window.setInterval(poll, 30000);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', poll, {once: true});
    else poll();
})();
