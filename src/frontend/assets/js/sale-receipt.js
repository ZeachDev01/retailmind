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
}());
