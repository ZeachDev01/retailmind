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
        const scan = control('add-product-scan-btn');
        const scannerSection = control('add-product-scanner-section');
        const stopScan = control('stop-add-product-scan');
        const modal = form.closest('.rm-modal-overlay');
        const change = control('create-barcode-change');
        const keep = control('create-barcode-keep');
        const catalogFields = [
            ['name', control('product-name'), 'Product Name'],
            ['declared_size', control('product-declared-size'), 'Declared Size'],
            ['brand', control('product-brand'), 'Brand']
        ];
        const providerValues = new Map();
        let committedCode = unit.value.trim();
        let candidateCode;
        let catalogMatch;
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
            if (!isUnit) {
                const field = fields[type.value];
                packaging.value = field.value;
                packaging.maxLength = field.maxLength;
                packaging.labels[0].textContent = labels[type.value] + ' Barcode';
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

        function useValue(field, value) {
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function clearSuggestions() {
            form.querySelectorAll('[data-catalog-suggestion]').forEach(button => button.remove());
        }

        function renderSuggestions(product, fillEmpty = true) {
            clearSuggestions();
            for (const [key, field, label] of catalogFields) {
                const value = product[key];
                if (!value || field.value.trim() === value) continue;
                const apply = () => {
                    useValue(field, value);
                    providerValues.set(field, field.value);
                };
                const detail = field.closest('details');
                if (detail) detail.open = true;
                if (fillEmpty && !field.value.trim()) {
                    apply();
                    continue;
                }
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-quiet product-catalog-suggestion';
                button.dataset.catalogSuggestion = '';
                button.textContent = 'Apply ' + label + ' suggestion · ' + value;
                button.addEventListener('click', () => {
                    if (unit.value.trim() !== committedCode) return;
                    apply();
                    field.focus();
                    button.remove();
                });
                field.closest('.form-group').append(button);
            }
        }

        function clearCatalog() {
            clearSuggestions();
            for (const [field, value] of providerValues) {
                if (field.value === value) useValue(field, '');
            }
            providerValues.clear();
            catalogMatch = null;
            form.dispatchEvent(new CustomEvent('product-create:catalog-clear'));
        }

        function populated() {
            return Array.from(form.elements).some(field => {
                if (!field.name || field === unit || field.type === 'hidden') return false;
                if (field.type === 'file') return field.files.length > 0;
                if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
                if (field.tagName === 'SELECT') {
                    const first = Array.from(field.options).find(option => option.defaultSelected) ?? field.options[0];
                    return !!first && field.value !== first.value;
                }
                return 'defaultValue' in field && field.value !== field.defaultValue && field.value !== '';
            }) || !!catalogMatch;
        }

        function acceptUnitCode(code) {
            if (code === committedCode) return true;
            if (committedCode && populated()) {
                clearFeedback();
                clearSuggestions();
                candidateCode = code;
                // Keep the previous barcode paired with its details until the choice is made.
                useValue(unit, committedCode);
                control('create-barcode-change-message').textContent = code
                    ? 'Replace ' + committedCode + ' with ' + code + '?'
                    : 'Remove the current Individual Item barcode?';
                change.hidden = false;
                keep.focus();
                return false;
            }
            if (committedCode) clearCatalog();
            committedCode = code;
            return true;
        }

        function useUnitCode(code) {
            if (!acceptUnitCode(code)) return;
            setCode('unit_barcode', code);
            lookup();
        }

        keep.addEventListener('click', () => {
            change.hidden = true;
            feedback.textContent = 'Current barcode kept.';
            if (catalogMatch) renderSuggestions(catalogMatch.product, false);
            unit.focus();
        });
        control('create-barcode-keep-details').addEventListener('click', () => {
            const code = candidateCode;
            change.hidden = true;
            clearCatalog();
            committedCode = code;
            setCode('unit_barcode', code);
            unit.focus();
            if (code) lookup();
        });
        control('create-barcode-start-new').addEventListener('click', async event => {
            const code = candidateCode;
            const button = event.currentTarget;
            button.disabled = true;
            await window.RetailMindInventoryDrafts?.discard(form);
            button.disabled = false;
            form.reset();
            queueMicrotask(() => {
                type.value = 'unit_barcode';
                sync();
                unit.focus();
                if (code) useUnitCode(code);
            });
        });

        function showCatalog(code, catalog) {
            if (catalog.status === 'found') {
                catalogMatch = catalog;
                feedback.textContent = 'Open Food Facts match. Compare the details with the package.';
                renderSuggestions(catalog.product);
                form.dispatchEvent(new CustomEvent('product-create:catalog-match', {
                    detail: { barcode: code, product: catalog.product, source: catalog.source }
                }));
            } else {
                feedback.textContent = catalog.status === 'not_found'
                    ? 'No catalog match. Continue entering product details.'
                    : 'Open Food Facts is unavailable. Try again or continue entering product details.';
            }
        }

        function checkScannedCode(kind, code) {
            const maxLength = fields[kind].maxLength;
            if (!code || code.length > maxLength) {
                clearFeedback();
                feedback.textContent = `Scan a barcode of ${maxLength} characters or fewer.`;
                return;
            }
            if (kind === 'unit_barcode') {
                useUnitCode(code);
                return;
            }
            setCode(kind, code);
            lookup();
        }

        async function lookup() {
            const kind = type.value;
            const field = fields[kind];
            const code = field.value.trim();
            if (kind === 'unit_barcode' && !acceptUnitCode(code)) return;
            change.hidden = true;
            const current = ++requestId;
            if (!code || code.length > field.maxLength) {
                feedback.textContent = code ? `Use ${field.maxLength} characters or fewer.` : 'Enter or scan a barcode to look it up.';
                (field === unit ? unit : packaging).focus();
                return;
            }
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
                } else if (result.catalog) {
                    showCatalog(code, result.catalog);
                } else {
                    feedback.textContent = result.outcome === 'ambiguous'
                        ? 'This barcode matches more than one Store identifier. Check the Products list.'
                        : 'No Store product uses this code. Continue entering product details.';
                }
            } catch (_) {
                if (current !== requestId) return;
                feedback.textContent = navigator.onLine === false
                    ? 'You’re offline. Continue entering product details or retry when connected.'
                    : 'Store lookup is unavailable. Try again or continue entering product details.';
            }
        }

        type.addEventListener('change', () => { change.hidden = true; clearFeedback(); sync(); });
        packaging.addEventListener('input', () => setCode(type.value, packaging.value));
        unit.addEventListener('retailmind:fieldrestore', () => {
            clearCatalog();
            clearFeedback();
            change.hidden = true;
            committedCode = unit.value.trim();
        });
        form.addEventListener('focusin', event => {
            if (event.target === unit && !committedCode) committedCode = unit.value.trim();
        });
        form.addEventListener('input', event => {
            const field = event.target;
            if (providerValues.has(field) && providerValues.get(field) !== field.value) providerValues.delete(field);
            if (field === unit && unit.value.trim() !== committedCode) {
                change.hidden = true;
                clearSuggestions();
            }
            if (field === fields[type.value]) {
                clearFeedback();
                sync();
            }
        });
        form.addEventListener('submit', event => {
            if (!change.hidden || !acceptUnitCode(unit.value.trim())) {
                event.preventDefault();
                event.stopImmediatePropagation();
                keep.focus();
            }
        }, true);
        form.addEventListener('reset', () => {
            providerValues.clear();
            clearCatalog();
            clearFeedback();
            change.hidden = true;
            queueMicrotask(() => {
                committedCode = unit.value.trim();
                sync();
            });
        });
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
                if (restoreFocus && change.hidden && modal.classList.contains('open')) scan.focus();
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
                    checkScannedCode(type.value, String(decoded).trim());
                    stopCamera(true);
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
            change.hidden = true;
            clearFeedback();
            if (!scannerSection.hidden) stopCamera();
        });
        modal.addEventListener('retailmind:overlayopen', sync);
        sync();
        return { lookup, sync };
    }
};
