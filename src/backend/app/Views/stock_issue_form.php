<?php
$issueValues = $directStockIssue && $error !== '' && ($_POST['action'] ?? '') === 'record' ? $_POST : [];
$issueValue = static fn(string $key): string => htmlspecialchars(is_scalar($issueValues[$key] ?? '') ? (string)($issueValues[$key] ?? '') : '');
?>
            <div class="report-form stock-issue-card">
                <div class="stock-card-header">
                    <h2><?= $directStockIssue ? 'Record Stock Issue' : 'New Report' ?></h2>
                </div>
                <form method="POST" id="stock-issue-form"<?= $directStockIssue ? '' : ' action="' . htmlspecialchars(app_url('components/cashier/stock_issues.php')) . '"' ?> data-products-api="<?= htmlspecialchars($productsApiUrl) ?>" <?= $stockIssueCanRecord ? '' : 'aria-disabled="true"' ?>>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <?php if ($directStockIssue): ?>
                    <input type="hidden" name="action" value="record">
                    <input type="hidden" name="operation_token" value="<?= htmlspecialchars($recordToken) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="product_id" id="product_id" value="<?= $issueValue('product_id') ?>">

                    <div class="form-section stock-step-section">
                        <div class="stock-step-header">
                            <h3>Product</h3>
                        </div>
                        <div>
                            <div class="form-group">
                                <label for="product-search"><i class="bi bi-upc-scan" aria-hidden="true"></i> Scan or search</label>
                                <div class="input-with-icon">
                                    <i class="bi bi-search input-icon" aria-hidden="true"></i>
                                    <input type="search" id="product-search" list="stock-issue-products" placeholder="Scan barcode or search by name, barcode, or SKU" autocomplete="off" <?= $stockIssueCanRecord ? '' : 'disabled' ?>>
                                </div>
                                <datalist id="stock-issue-products"></datalist>
                            </div>
                        </div>
                        <div id="product-results" class="search-results" aria-live="polite"></div>
                        <div id="selected-product" class="adjustment-card selected-product-box" role="status" hidden>
                            <div class="selected-product-main">
                                <div class="selected-product-avatar" aria-hidden="true">
                                    <i class="bi bi-box-seam-fill"></i>
                                </div>
                                <div class="selected-product-meta">
                                    <div class="adjustment-product" id="selected-product-name"></div>
                                    <div class="adjustment-sku-row">
                                        <span class="adjustment-sku-chip"><i class="bi bi-upc" aria-hidden="true"></i> <span id="selected-product-code"></span></span>
                                    </div>
                                </div>
                                <div class="selected-product-actions">
                                    <div class="stock-level-chip" id="selected-product-stock"></div>
                                    <button type="button" class="btn btn-secondary" id="clear-product">
                                        <i class="bi bi-x-circle" aria-hidden="true"></i> Change
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section stock-step-section">
                        <div class="stock-step-header">
                            <h3>Issue Details</h3>
                        </div>
                        <div class="form-grid stock-details-grid">
                            <div class="form-group">
                                <label for="category" class="required">Issue type</label>
                                <select name="category" id="category" required <?= $stockIssueCanRecord ? '' : 'disabled' ?>>
                                    <option value="">Select type</option>
                                    <option value="damaged" <?= ($issueValues['category'] ?? '') === 'damaged' ? 'selected' : '' ?>>Damaged</option>
                                    <option value="missing" <?= ($issueValues['category'] ?? '') === 'missing' ? 'selected' : '' ?>>Missing / Lost</option>
                                    <option value="expired" <?= ($issueValues['category'] ?? '') === 'expired' ? 'selected' : '' ?>>Expired</option>
                                    <option value="other" <?= ($issueValues['category'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="form" class="required">Stock form</label>
                                <select name="form" id="form" required <?= $stockIssueCanRecord ? '' : 'disabled' ?>>
                                    <option value="piece" <?= ($issueValues['form'] ?? '') === 'piece' ? 'selected' : '' ?>>Loose pieces</option>
                                    <option value="pack" <?= ($issueValues['form'] ?? '') === 'pack' ? 'selected' : '' ?> id="form-option-pack" disabled>Sealed packs</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="quantity" class="required"><?= $directStockIssue ? 'Confirmed quantity' : 'Quantity' ?></label>
                                <input type="number" name="quantity" id="quantity" value="<?= $issueValue('quantity') ?>" min="1" step="1" placeholder="1" autocomplete="off" inputmode="numeric" required <?= $stockIssueCanRecord ? '' : 'disabled' ?>>
                            </div>
                        </div>
                        <div class="form-group stock-explanation-group">
                            <label for="explanation" class="required">Reason</label>
                            <textarea name="explanation" id="explanation" placeholder="Reason for discrepancy…" required <?= $stockIssueCanRecord ? '' : 'disabled' ?>><?= $issueValue('explanation') ?></textarea>
                        </div>
                    </div>

                    <div class="stock-form-footer">
                        <button type="submit" class="btn btn-primary stock-submit-btn" <?= $stockIssueCanRecord ? '' : 'disabled' ?>>
                            <?= $directStockIssue ? 'Record & deduct stock' : 'Submit Report' ?>
                        </button>
                    </div>
                </form>
            </div>

