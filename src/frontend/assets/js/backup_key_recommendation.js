// Show the recovery-key recommendation prompt once per session, per fingerprint.
// The banner itself is server-driven: it disappears after that account exports
// the key, and it stays visible while the dialog is suppressed for the session.
(function () {
    'use strict';
    function initialize() {
        const banner = document.querySelector('[data-backup-key-recommendation]');
        const dialog = document.querySelector('[data-backup-key-dialog]');
        if (!banner || !dialog || typeof dialog.showModal !== 'function') return;
        const key = 'retailmind.backup-key-recommendation.' + banner.dataset.fingerprint;
        let seen = false;
        try { seen = sessionStorage.getItem(key) !== null; } catch (error) { /* storage unavailable */ }
        if (seen) return;
        try { sessionStorage.setItem(key, '1'); } catch (error) { /* show once anyway */ }
        dialog.addEventListener('cancel', event => { event.preventDefault(); dialog.close(); });
        dialog.querySelector('[data-backup-key-dialog-close]')?.addEventListener('click', () => dialog.close());
        if (!dialog.open) dialog.showModal();
    }
    if (window.RetailMindNavigation) window.RetailMindNavigation.initializePage(initialize);
    else initialize();
})();
