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
    window.printReceiptSection = async function (trigger) {
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
        const copy = printRoot;
        try {
            const images = [...copy.querySelectorAll('img')];
            if (images.length) await Promise.all(images.map(image => image.decode()));
            if (printRoot !== copy || !copy.isConnected) return;
            window.print();
        } catch (error) {
            if (printRoot !== copy) return;
            clearPrintCopy();
            window.RetailMindUI?.toast('Receipt saved. Printing could not start. Use the receipt’s Print button to try again.', 'error');
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
            window.printReceiptSection(button);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoPrintReceipt, {once: true});
    else autoPrintReceipt();
}());
