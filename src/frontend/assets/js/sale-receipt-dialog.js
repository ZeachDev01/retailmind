/* Native dialog supplies modal focus containment and inert background. Reads only saved receipts. */
(function () {
    function initialize() {
        const dialog = document.getElementById('receiptModal');
        if (!dialog) return;
        const content = document.getElementById('receiptContent');
        const title = document.getElementById('saleReceiptTitle');
        let returnFocus = null;
        let pending = null;
        function open(trigger) {
            returnFocus = trigger || document.getElementById('receipt-table-title');
            if (!dialog.open) dialog.showModal();
            window.RetailMindUI?.syncScrollLock?.();
            const warning = document.querySelector('.theme-save-alert');
            if (warning) dialog.querySelector('.sale-receipt-dialog-header').append(warning);
            title.focus();
        }
        function close() {
            pending?.abort();
            dialog.close();
        }
        dialog.querySelector('[data-close-receipt]').addEventListener('click', close);
        dialog.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            const controls = Array.from(dialog.querySelectorAll('summary, button, a[href], input, select, textarea, [tabindex="0"]'))
                .filter(element => !element.disabled && element.getClientRects().length);
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (!first) { event.preventDefault(); title.focus(); return; }
            if (event.shiftKey && (document.activeElement === first || document.activeElement === title)) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault(); first.focus();
            }
        });
        dialog.addEventListener('cancel', () => pending?.abort());
        dialog.addEventListener('close', () => {
            window.RetailMindUI?.syncScrollLock?.();
            const warning = dialog.querySelector('.theme-save-alert');
            if (warning) document.body.append(warning);
            returnFocus?.focus({preventScroll: true});
            // Refreshing a historical view must never repeat a payment announcement.
            const url = new URL(window.location.href);
            url.searchParams.delete('checkout');
            url.searchParams.delete('sale_id');
            if (window.location.protocol.startsWith('http')) history.replaceState(null, '', url);
        });
        window.closeReceiptModal = close;
        window.viewReceipt = async function (saleId, trigger) {
            pending?.abort();
            pending = new AbortController();
            const request = pending;
            title.textContent = 'Sale Receipt #' + Number(saleId);
            content.replaceChildren();
            const status = document.createElement('p');
            status.setAttribute('role', 'status');
            status.className = 'receipt-dialog-status';
            status.textContent = 'Loading saved receipt…';
            content.appendChild(status);
            open(trigger || document.activeElement);
            try {
                const url = new URL(dialog.dataset.receiptEndpoint, window.location.href);
                url.searchParams.set('sale_id', Number(saleId));
                const response = await fetch(url, {signal: request.signal, headers: {'Accept': 'text/html'}});
                if (!response.ok || response.redirected) throw new Error('Receipt unavailable: ' + response.status);
                const html = await response.text();
                if (pending !== request || !dialog.open) return;
                content.innerHTML = html;
            } catch (error) {
                if (error.name === 'AbortError') return;
                console.error('Error fetching receipt:', error);
                const easy = 'The receipt could not be shown. Please try again. Tell your Administrator if this keeps happening.';
                const detail = window.RetailMindUI && window.RetailMindUI.isDebug() ? '\n' + String(error) : '';
                status.setAttribute('role', 'alert');
                status.textContent = easy;
                if (window.RetailMindUI) RetailMindUI.toast(easy + detail, 'error');
            }
        };
        if (dialog.dataset.initialReceipt === '1') open();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
}());
