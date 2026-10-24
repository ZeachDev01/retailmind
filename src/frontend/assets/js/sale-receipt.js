/* Printing never writes sale data. Keep the copy until the dialog is dismissed. */
(function () {
    let printRoot = null;

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
