(() => {
    const {endpoint, tasksUrl, user, workspace} = document.currentScript.dataset;
    if (window.rmFiscalClosingReminder) return;
    window.rmFiscalClosingReminder = true;
    const key = 'retailmind.fiscal-closing.' + user + '.' + workspace;
    let loading = false;
    let shownDay = '';
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
        if (loading || document.hidden) return;
        loading = true;
        try {
            const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store', headers: {'X-RetailMind-Background': '1'}});
            if (!response.ok) return;
            const status = await response.json();
            if (status.workspace !== workspace) return;
            for (const bell of document.querySelectorAll('.global-notification-button')) {
                bell.querySelector('strong')?.remove();
                bell.setAttribute('aria-label', 'Notifications' + (status.unread ? ': ' + status.unread + ' unread' : ''));
                if (status.unread) {
                    const badge = document.createElement('strong');
                    badge.textContent = status.unread > 99 ? '99+' : status.unread;
                    bell.append(badge);
                }
            }
            if (!status.reminders.length || unsafe(status) || !window.Swal || shownDay === status.day) return;
            try { if (localStorage.getItem(key) === status.day) return; } catch (_) {}
            shownDay = status.day;
            try { localStorage.setItem(key, status.day); } catch (_) {}
            const choice = await Swal.fire({
                icon: 'warning', title: status.reminders[0].title,
                text: status.reminders[0].explanation +
                    (status.reminders.length > 1 ? ' Review Notifications for your other closing tasks.' : ''),
                showCancelButton: true, confirmButtonText: 'Review closing tasks', cancelButtonText: 'Later',
                allowOutsideClick: false, allowEscapeKey: false, buttonsStyling: false,
                customClass: {confirmButton: 'btn btn-primary rm-swal-confirm', cancelButton: 'btn btn-quiet rm-swal-cancel'},
                hideClass: {popup: '', backdrop: ''},
            });
            if (choice.isConfirmed && (!window.cartWorkspace || await window.cartWorkspace.resolveWork())) {
                window.RetailMindUI.navigate(tasksUrl);
            }
        } catch (_) {
            // A later poll retries without interrupting current work.
        } finally { loading = false; }
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    window.setInterval(check, 60000);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check, {once: true});
    else check();
})();
