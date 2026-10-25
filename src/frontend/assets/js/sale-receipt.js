/* Printing never writes sale data. Keep the copy until the dialog is dismissed. */
(function () {
    let printRoot = null;

    // Software keyboards can shrink only the visual viewport on tablet browsers.
    if (window.visualViewport) {
        const fitViewport = () => {
            document.documentElement.style.setProperty('--cashier-visible-height', window.visualViewport.height + 'px');
            document.documentElement.style.setProperty('--cashier-visible-top', window.visualViewport.offsetTop + 'px');
        };
        window.visualViewport.addEventListener('resize', fitViewport);
        window.visualViewport.addEventListener('scroll', fitViewport);
        fitViewport();
    }

    function clearPrintCopy() {
        if (printRoot) printRoot.remove();
        printRoot = null;
        document.body.classList.remove('sale-receipt-printing');
    }

    window.addEventListener('afterprint', clearPrintCopy);
    window.printReceiptSection = function (trigger) {
        const receipt = trigger
            ? trigger.closest('.receipt-container')?.querySelector('.receipt-print-area')
            : document.querySelector('.receipt-print-area');
        if (!receipt) return;

        clearPrintCopy();
        printRoot = document.createElement('div');
        printRoot.className = 'receipt-print-root';
        printRoot.setAttribute('aria-hidden', 'true');
        printRoot.appendChild(receipt.cloneNode(true));
        document.body.appendChild(printRoot);
        document.body.classList.add('sale-receipt-printing');
        try {
            window.print();
        } catch (error) {
            clearPrintCopy();
            throw error;
        }
    };
    window.exportReceiptPdf = window.printReceiptSection;

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-store-print]');
        if (!button || button.disabled) return;
        let status = button.closest('.receipt-container').querySelector('[data-print-status]');
        if (!status) {
            status = document.createElement('p');
            status.dataset.printStatus = '';
            status.className = 'receipt-dialog-status no-print';
            status.setAttribute('role', 'status');
            const actions = button.closest('.receipt-actions');
            if (actions) actions.insertAdjacentElement('afterend', status);
            else button.closest('.receipt-container').appendChild(status);
        }
        const reprint = button.dataset.printJob || '';
        if (reprint && button.dataset.printOutcome !== 'failed' && !window.confirm('Delivery may already have printed a copy. Check the Store Printer before requesting another copy. Print another copy?')) return;
        button.disabled = true;
        status.textContent = 'Receipt saved. Contacting the Store Printer…';
        const send = async (action, extra) => {
            const response = await fetch(button.dataset.printEndpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                body: new URLSearchParams({action, csrf_token: button.dataset.csrf, receipt_type: button.dataset.receiptType, receipt_id: button.dataset.receiptId, ...extra})
            });
            if (!response.ok || response.redirected) throw new Error('Print request unavailable');
            return response.json();
        };
        try {
            let job = await send('submit', {reprint_of: reprint});
            for (let attempt = 0; ; attempt++) {
                button.dataset.printJob = job.job_id;
                button.dataset.printOutcome = job.status;
                if (job.status === 'spooled') {
                    status.textContent = 'Receipt saved. Sent to the Windows print queue; paper output is not confirmed. Check the Store Printer.';
                    break;
                }
                if (job.status === 'uncertain') {
                    status.textContent = 'Receipt saved. Print delivery is uncertain. Check for an existing copy before reprinting.';
                    break;
                }
                if (job.status === 'failed') {
                    status.textContent = 'Receipt saved. Printing could not start. Check the Store PC and printer, then retry.';
                    break;
                }
                status.textContent = 'Receipt saved. Queued for the Store PC. Keep the PC printing service and printer available.';
                if (attempt >= 15 || !button.isConnected) {
                    // A later tap reconciles the same queued job; it never replays delivery.
                    delete button.dataset.printJob;
                    break;
                }
                await new Promise(resolve => setTimeout(resolve, 1000));
                job = await send('status', {job_id: job.job_id});
            }
        } catch (error) {
            status.textContent = 'Receipt saved. Print delivery could not be checked. Check the Store PC and printer, then try again. A copy may already exist.';
            delete button.dataset.printJob;
        } finally {
            button.textContent = button.dataset.printJob ? 'Reprint to Store Printer' : 'Print to Store Printer';
            button.disabled = false;
        }
    });

    function autoPrintReceipt() {
        const dialog = document.querySelector('#receiptModal[data-auto-print="1"]');
        if (!dialog) return;
        if (!dialog.open) {
            dialog.addEventListener('retailmind:receiptopen', autoPrintReceipt, {once: true});
            return;
        }
        // Consume before printing, including cancellation or a printer failure.
        dialog.removeAttribute('data-auto-print');
        requestAnimationFrame(() => {
            const button = dialog.querySelector('.receipt-actions button');
            if (!button || !dialog.open || !dialog.isConnected) return;
            try {
                window.printReceiptSection(button);
            } catch (error) {
                window.RetailMindUI?.toast('Receipt saved. Printing could not start. Use the receipt’s Print button to try again.', 'error');
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoPrintReceipt, {once: true});
    else autoPrintReceipt();
}());
