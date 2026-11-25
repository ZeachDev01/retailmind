(() => {
        const issueForm = document.getElementById('stock-issue-form');
        const productsApiUrl = issueForm.dataset.productsApi;
        const barcodeInput = document.getElementById('barcode-input');
        const searchInput = document.getElementById('product-search');
        const resultsBox = document.getElementById('product-results');
        const productIdField = document.getElementById('product_id');
        const selectedCard = document.getElementById('selected-product');
        let searchTimer = null;
        const productSuggestions = document.getElementById('stock-issue-products');
        let matchingProducts = [];
        let selectedProductId = '';

        function updateFormSelect(packSize) {
            const formSelect = document.getElementById('form');
            const packOpt = document.getElementById('form-option-pack');
            if (!formSelect || !packOpt) return;
            if (packSize && packSize > 1) {
                packOpt.disabled = false;
                packOpt.textContent = 'Sealed packs (Pack of ' + packSize + ')';
            } else {
                packOpt.disabled = true;
                packOpt.textContent = 'Sealed packs';
                formSelect.value = 'piece';
            }
        }

        function selectProduct(product, scannedCode = '') {
            RetailMindUI.closeSearchDropdown(searchInput);
            productIdField.value = product.product_id;
            selectedProductId = String(product.product_id);
            document.getElementById('selected-product-name').textContent = product.name || product.product_name;
            document.getElementById('selected-product-code').textContent = product.sku || product.barcode || '';
            document.getElementById('selected-product-stock').textContent = 'Stock ' + (product.quantity_on_hand ?? '?');
            selectedCard.hidden = false;
            resultsBox.innerHTML = '';
            const pSize = Number(product.pack_size || 0);
            updateFormSelect(pSize);
            const formSelect = document.getElementById('form');
            if (formSelect && scannedCode && product.pack_barcode && scannedCode === product.pack_barcode && pSize > 1) {
                formSelect.value = 'pack';
            } else if (formSelect) {
                formSelect.value = 'piece';
            }
            selectedCard.scrollIntoView({
                block: 'nearest'
            });
        }

        document.getElementById('clear-product').addEventListener('click', () => {
            productIdField.value = '';
            selectedCard.hidden = true;
            updateFormSelect(0);
            searchInput.value = '';
            searchInput.focus();
        });

        function renderResults(products, emptyMessage) {
            matchingProducts = products;
            productSuggestions.replaceChildren();
            productSuggestions.dataset.emptyMessage = emptyMessage;
            products.forEach(product => {
                const option = document.createElement('option');
                option.value = (product.sku || product.barcode || 'No code') + ' — ' + product.name + ' (Stock ' + Number(product.quantity_on_hand || 0) + ')';
                option.label = [option.value, product.barcode, product.case_barcode, product.pack_barcode].filter(Boolean).join(' ');
                productSuggestions.append(option);
            });
            resultsBox.textContent = products.length ? '' : emptyMessage;
            RetailMindUI.showSearchDropdown(searchInput);
        }

        searchInput.addEventListener('change', () => {
            const index = Array.from(productSuggestions.options).findIndex(option => option.value === searchInput.value);
            if (index < 0) return;
            clearTimeout(searchTimer);
            selectProduct(matchingProducts[index]);
        });

        let lookupAbortController = null;
        async function lookup(url, emptyMessage, selectMatch = false, scannedCode = '') {
            if (lookupAbortController) lookupAbortController.abort();
            lookupAbortController = new AbortController();
            try {
                const response = await fetch(url, { signal: lookupAbortController.signal });
                const data = await response.json();
                const products = response.ok && data.success ? (data.products || []) : [];
                if (selectMatch && products.length === 1) selectProduct(products[0], scannedCode);
                else renderResults(products, products.length > 1 ? 'More than one product matches. Select the correct product.' : emptyMessage);
            } catch (error) {
                if (error && error.name === 'AbortError') return;
                renderResults([], 'Unable to load products.');
            }
        }

        function scanStockIssueCode(code) {
            if (barcodeInput.disabled || !code) return;
            clearTimeout(searchTimer);
            searchInput.value = '';
            productIdField.value = '';
            selectedCard.hidden = true;
            return lookup(productsApiUrl + '&code=' + encodeURIComponent(code) + '&limit=5', 'No active product with recorded stock matches that barcode.', true, code);
        }
        barcodeInput.addEventListener('keydown', event => {
            if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return;
            event.preventDefault();
            if (event.repeat) return;
            const code = barcodeInput.value.trim();
            barcodeInput.value = '';
            scanStockIssueCode(code);
        });

        let stockIssueBarcode = '';
        let stockIssueBarcodeAt = 0;
        function resetStockIssueBarcode() {
            stockIssueBarcode = '';
            stockIssueBarcodeAt = 0;
        }
        document.addEventListener('keydown', event => {
            if (barcodeInput.disabled || event.defaultPrevented || event.isComposing || event.repeat
                || event.ctrlKey || event.metaKey || event.altKey || document.hidden
                || event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]')
                || document.querySelector('dialog[open], [aria-modal="true"].open, .rm-modal-overlay.open, .command-overlay.open, .swal2-container')) {
                resetStockIssueBarcode();
                return;
            }
            const now = performance.now();
            // shortcut: scanners must send characters within 80ms; tune for slower hardware if needed.
            if (now - stockIssueBarcodeAt > 80) resetStockIssueBarcode();
            if (event.key === 'Enter') {
                const code = stockIssueBarcode;
                resetStockIssueBarcode();
                if (code.length < 3) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                scanStockIssueCode(code);
            } else if (event.key.length === 1 && /^[\x21-\x7E]$/.test(event.key)) {
                stockIssueBarcode += event.key;
                stockIssueBarcodeAt = now;
                if (stockIssueBarcode.length > 255) resetStockIssueBarcode();
            } else if (event.key !== 'Shift') resetStockIssueBarcode();
        }, true);
        window.addEventListener('blur', resetStockIssueBarcode);
        document.addEventListener('visibilitychange', resetStockIssueBarcode);

        searchInput.addEventListener('input', event => {
            if (event.detail === 'search-dropdown') return;
            clearTimeout(searchTimer);
            if (lookupAbortController) lookupAbortController.abort();
            productSuggestions.replaceChildren();
            productIdField.value = '';
            selectedCard.hidden = true;
            searchTimer = setTimeout(() => {
                const term = searchInput.value.trim();
                if (term === '') {
                    resultsBox.innerHTML = '';
                    return;
                }
                lookup(productsApiUrl + '&q=' + encodeURIComponent(term) + '&limit=12', 'No matching active product was found.');
            }, 250);
        });

        async function restoreProduct() {
            const id = productIdField.value;
            if (!id || (id === selectedProductId && !selectedCard.hidden)) return;
            const stockForm = issueForm.elements.form.value;
            try {
                const response = await fetch(productsApiUrl + '&id=' + encodeURIComponent(id));
                const data = await response.json();
                if (response.ok && data.product && String(productIdField.value) === id) {
                    selectProduct(data.product);
                    issueForm.elements.form.value = stockForm;
                }
            } catch (_) { resultsBox.textContent = 'Unable to load the selected product. Try searching again.'; }
        }
        restoreProduct();
        issueForm.addEventListener('change', restoreProduct);
        productIdField.addEventListener('retailmind:fieldrestore', () => setTimeout(restoreProduct, 0));
        document.getElementById('stock-issue-form').addEventListener('submit', event => {
            if (productIdField.value === '') {
                event.preventDefault();
                RetailMindUI.toast('Select a product by barcode scan or search first.', 'error');
            }
        });

})();
