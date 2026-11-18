'use strict';

window.RetailMindProductCreateBarcode = {
    init(form) {
        const control = id => form.querySelector('#' + id);
        const type = control('create-barcode-type');
        const unit = control('create-barcode-input');
        const packaging = control('create-packaging-lookup-code');
        const fields = {
            unit_barcode: unit,
            pack_barcode: control('create-pack-barcode'),
            case_barcode: control('create-receiving-barcode')
        };
        const labels = {
            unit_barcode: 'Individual Item',
            pack_barcode: 'Sales Pack',
            case_barcode: 'Receiving Carton',
            sku: 'Store SKU'
        };
        const lookupButton = control('create-barcode-lookup');
        const feedback = control('create-barcode-feedback');
        const sellingPacks = control('create-sell-packs');
        const cartonItemScan = control('create-carton-item-scan');
        const scan = control('add-product-scan-btn');
        const scannerSection = control('add-product-scanner-section');
        const stopScan = control('stop-add-product-scan');
        const modal = form.closest('.rm-modal-overlay');
        let requestId = 0;
        let burst;
        let camera;
        let openingCamera;
        let stoppingCamera;

        function clearFeedback() {
            requestId++;
            feedback.textContent = '';
        }

        function sync() {
            const isUnit = type.value === 'unit_barcode';
            control('create-unit-lookup-field').hidden = !isUnit;
            control('create-packaging-lookup-field').hidden = isUnit;
            cartonItemScan.hidden = type.value !== 'case_barcode' || !fields.case_barcode.value.trim();
            if (!isUnit) {
                const field = fields[type.value];
                packaging.value = field.value;
                packaging.maxLength = field.maxLength;
                packaging.labels[0].textContent = labels[type.value] + ' Barcode';
            }
        }

        function focusPackagingDetails(kind) {
            if (kind === 'case_barcode') {
                control('create-receiving-options').open = true;
                control('units-per-package').focus();
            } else if (kind === 'pack_barcode') {
                const size = control('create-pack-size');
                (size.validity.valid ? control('create-pack-price') : size).focus();
            }
        }

        function setCode(kind, code) {
            const field = fields[kind];
            if (kind === 'pack_barcode' && code && !sellingPacks.checked) {
                sellingPacks.checked = true;
                sellingPacks.dispatchEvent(new Event('change', { bubbles: true }));
            }
            field.value = code;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function checkScannedCode(kind, code) {
            const maxLength = fields[kind].maxLength;
            if (!code || code.length > maxLength) {
                clearFeedback();
                feedback.textContent = `Scan a barcode of ${maxLength} characters or fewer.`;
                return;
            }
            setCode(kind, code);
            lookup();
            return kind !== 'unit_barcode';
        }

        async function lookup() {
            const kind = type.value;
            const field = fields[kind];
            const code = field.value.trim();
            const current = ++requestId;
            if (!code || code.length > field.maxLength) {
                feedback.textContent = code ? `Use ${field.maxLength} characters or fewer.` : 'Enter or scan a barcode to look it up.';
                (field === unit ? unit : packaging).focus();
                return;
            }
            focusPackagingDetails(kind);
            feedback.textContent = 'Checking Store products…';
            try {
                const url = new URL(lookupButton.dataset.url, window.location.href);
                url.search = new URLSearchParams({ context: 'add_product', barcode_type: kind, code });
                const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                const result = await response.json();
                if (current !== requestId || type.value !== kind || field.value.trim() !== code) return;
                if (!response.ok || !result.success) throw new Error('Store lookup failed');
                if (result.outcome === 'match') {
                    const product = result.product;
                    const status = product.status === 'active' ? 'Active' : 'Inactive';
                    feedback.textContent = `${product.product_name} · ${labels[result.matched_code_kind]} · ${status}`;
                    if (product.manage_url) {
                        const link = document.createElement('a');
                        link.href = product.manage_url;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.textContent = 'Manage Product';
                        feedback.append(document.createElement('br'), link);
                    }
                } else {
                    feedback.textContent = result.outcome === 'ambiguous'
                        ? 'This barcode matches more than one Store identifier. Check the Products list.'
                        : kind === 'case_barcode' ? 'No Store product uses this code. Enter the number of items per carton.'
                        : kind === 'pack_barcode' ? 'No Store product uses this code. Enter the Sales Pack size and price.'
                        : 'No Store product uses this code. Continue entering product details.';
                }
            } catch (_) {
                if (current === requestId) feedback.textContent = 'Store lookup is unavailable. Try again or continue entering product details.';
            }
        }

        type.addEventListener('change', () => { clearFeedback(); sync(); });
        cartonItemScan.addEventListener('click', () => {
            if (!scannerSection.hidden) stopCamera();
            type.value = 'unit_barcode';
            type.dispatchEvent(new Event('change', { bubbles: true }));
            unit.focus();
        });
        packaging.addEventListener('input', () => setCode(type.value, packaging.value));
        form.addEventListener('input', event => {
            if (event.target === fields[type.value]) {
                clearFeedback();
                sync();
            }
        });
        form.addEventListener('reset', () => queueMicrotask(() => { clearFeedback(); sync(); }));
        lookupButton.addEventListener('click', lookup);

        form.addEventListener('keydown', event => {
            if (event.isComposing || event.ctrlKey || event.metaKey || event.altKey) return;
            const field = event.target;
            const kind = field === packaging ? type.value : Object.keys(fields).find(key => fields[key] === field);
            if (!kind) return;
            const now = performance.now();
            // shortcut: wedge bursts end with Enter; verify the 80 ms interval on Store hardware.
            if (burst?.field !== field || now - burst.at > 80) burst = null;
            if (event.key === 'Enter') {
                event.preventDefault();
                const scanned = burst?.code;
                burst = null;
                if (kind !== type.value) {
                    type.value = kind;
                    sync();
                }
                if (scanned?.length >= 3) checkScannedCode(kind, scanned);
                else lookup();
            } else if (event.key.length === 1 && /^[\x21-\x7E]$/.test(event.key)) {
                burst ??= { field, code: '', at: now };
                if (burst.code.length <= field.maxLength) burst.code += event.key;
                burst.at = now;
            } else if (event.key !== 'Shift') burst = null;
        });

        async function stopCamera(restoreFocus = false) {
            if (stoppingCamera) return;
            stoppingCamera = true;
            scannerSection.hidden = true;
            scan.setAttribute('aria-expanded', 'false');
            try {
                await openingCamera;
                if (camera?.isScanning) await camera.stop();
            } catch (error) {
                console.error('Add Product camera stopped with an error:', error);
            } finally {
                openingCamera = null;
                stoppingCamera = false;
                scan.disabled = false;
                if (restoreFocus && modal.classList.contains('open')) scan.focus();
            }
        }

        scan.addEventListener('click', async () => {
            clearFeedback();
            if (!window.Html5Qrcode) {
                feedback.textContent = 'Camera scanning is unavailable. Enter the barcode manually.';
                return;
            }
            scan.disabled = true;
            scan.setAttribute('aria-expanded', 'true');
            scannerSection.hidden = false;
            stopScan.focus();
            try {
                camera ??= new window.Html5Qrcode('add-product-scanner-reader');
                openingCamera = camera.start({ facingMode: 'environment' }, { fps: 10 }, decoded => {
                    if (stoppingCamera || scannerSection.hidden) return;
                    const directed = checkScannedCode(type.value, String(decoded).trim());
                    stopCamera(!directed && control('create-barcode-change')?.hidden !== false);
                }, () => {});
                await openingCamera;
            } catch (error) {
                if (!scannerSection.hidden) {
                    console.error('Add Product camera could not start:', error);
                    feedback.textContent = 'The camera could not start. Allow camera access or enter the barcode manually.';
                    stopCamera(true);
                }
            }
        });
        stopScan.addEventListener('click', () => stopCamera(true));
        modal.addEventListener('retailmind:overlayclose', () => {
            burst = null;
            clearFeedback();
            if (!scannerSection.hidden) stopCamera();
        });
        modal.addEventListener('retailmind:overlayopen', sync);
        sync();
        return { lookup, sync };
    }
};
