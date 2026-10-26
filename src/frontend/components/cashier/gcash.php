<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['cashier']);
$gcashRules = require __DIR__ . '/../../../backend/config/gcash.php';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/gcash.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/gcash.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css')) ?>">
</head>
<body class="cashier-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="gcash-workspace" data-api="<?= htmlspecialchars(app_url('components/cashier/gcash_api.php'), ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" data-min-length="<?= $gcashRules['min_length'] ?>" data-max-length="<?= $gcashRules['max_length'] ?>">
        <section id="gcash-session-panel" class="pos-panel gcash-session-panel" aria-labelledby="gcash-session-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <img id="gcash-session-title" class="gcash-session-logo" src="<?= htmlspecialchars(app_url('assets/img/gcash-logo.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="GCash" width="88" height="21">
                </div>
                <div class="gcash-session-header-actions">
                    <span id="gcash-session-status" class="gcash-status">Checking…</span>
                    <button class="btn btn-primary" type="button" id="gcash-open-session-btn" hidden>Open GCash</button>
                    <button class="btn btn-secondary" type="button" id="gcash-funding-btn" hidden>Move service funds</button>
                    <button class="btn btn-secondary" type="button" id="gcash-close-session-btn" hidden>Reconcile &amp; Close</button>
                    <button class="btn btn-secondary" type="button" id="gcash-handover-session-btn" hidden>Hand Over</button>
                    <button class="btn btn-secondary" type="button" id="gcash-waive-btn" hidden>Waive Variance</button>
                </div>
            </div>
            <div id="gcash-session-banner" class="gcash-session-banner" role="status" aria-live="polite">
                <p id="gcash-session-message">Loading responsibility status…</p>
            </div>
        </section>

        <div class="gcash-tabs-nav-wrap">
            <nav class="gcash-tablist" role="tablist" aria-label="GCash sections">
                <button type="button" role="tab" id="tab-new-transaction" class="gcash-tab active" aria-selected="true" aria-controls="panel-new-transaction" tabindex="0">
                    <i class="bi bi-plus-circle" aria-hidden="true"></i>New Transaction
                </button>
                <button type="button" role="tab" id="tab-history" class="gcash-tab" aria-selected="false" aria-controls="panel-history" tabindex="-1">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>History
                </button>
            </nav>
        </div>

        <div role="tabpanel" id="panel-new-transaction" aria-labelledby="tab-new-transaction" tabindex="0" class="gcash-tabpanel">
            <section id="gcash-balances" class="gcash-workspace-balances" aria-label="Expected GCash balances">
                <div class="gcash-overview-grid gcash-balances-grid" aria-live="polite">
                    <div class="gcash-card gcash-card-balance">
                        <div class="gcash-card-header">
                            <span class="gcash-card-label">Physical Service Cash</span>
                            <span class="gcash-status">Expected</span>
                        </div>
                        <strong class="gcash-card-value" id="gcash-expected-cash">—</strong>
                    </div>
                    <div class="gcash-card gcash-card-balance">
                        <div class="gcash-card-header">
                            <span class="gcash-card-label">GCash App Wallet</span>
                            <span class="gcash-status">Expected</span>
                        </div>
                        <strong class="gcash-card-value" id="gcash-expected-wallet">—</strong>
                    </div>
                </div>
                <details class="gcash-balance-details">
                    <summary>About these balances</summary>
                    <p class="gcash-help">Expected funds from recorded movements. Service cash is separate from the POS drawer. Check actual funds before exchanging money.</p>
                    <p class="gcash-card-sub" id="gcash-cash-checked">Last check: —</p>
                </details>
                <p class="gcash-help" id="gcash-balances-message" role="status">Loading recorded balances…</p>
            </section>
            <form id="gcash-form" class="gcash-form">
            <section class="pos-panel gcash-entry-panel" aria-labelledby="gcash-entry-title">
                <div class="pos-panel-header gcash-panel-header">
                    <div class="pos-panel-title">
                        <div class="pos-panel-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                        <div>
                            <h2 id="gcash-entry-title">Transaction details</h2>
                        </div>
                    </div>
                </div>
                <div class="gcash-entry-scroll">
                    <div class="gcash-entry-fields">
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-type"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Transaction type</label>
                        <select class="pos-input" id="gcash-type" name="type" required>
                            <option value="Cash-In">Cash-In</option>
                            <option value="Cash-Out">Cash-Out</option>
                        </select>
                    </div>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-amount">Principal amount (PHP)</label>
                        <input class="pos-input" id="gcash-amount" name="amount" type="number" inputmode="numeric" min="1" max="50000" step="1" required placeholder="1–50,000 · whole pesos" aria-describedby="gcash-amount-help">
                        <small id="gcash-amount-help" class="sr-only">₱1–₱50,000 in whole pesos, excluding the service fee.</small>
                    </div>
                    <div class="gcash-field" id="gcash-fee-method-group" hidden>
                        <label class="pos-field-label" for="gcash-fee-method"><i class="bi bi-receipt" aria-hidden="true"></i> Fee payment method</label>
                        <select class="pos-input" id="gcash-fee-method" name="fee_method">
                            <option value="Fee via GCash">Fee via GCash</option>
                            <option value="Fee in cash">Fee in cash</option>
                        </select>
                    </div>
                    <div class="gcash-field gcash-reference-field">
                        <label class="pos-field-label" for="gcash-reference">GCash reference number</label>
                        <input class="pos-input" id="gcash-reference" name="reference" type="text" autocomplete="off" spellcheck="false" maxlength="2048" inputmode="numeric" required aria-describedby="gcash-scan-status gcash-duplicate">
                        <span id="gcash-scan-status" role="status" aria-live="polite"></span>
                        <span id="gcash-duplicate" role="alert" hidden>This reference has already been recorded. Check transaction history before proceeding.</span>
                        <div class="gcash-reference-tools">
                            <button class="btn btn-secondary" type="button" id="gcash-manual"><i class="bi bi-upc-scan" aria-hidden="true"></i> Use USB scanner</button>
                            <button class="btn btn-secondary" type="button" id="gcash-camera-open"><i class="bi bi-camera" aria-hidden="true"></i> Scan receipt</button>
                        </div>
                    </div>
                    </div>
                <section id="gcash-camera" class="gcash-camera" aria-labelledby="gcash-camera-title" hidden>
                    <h3 id="gcash-camera-title">Scan receipt reference</h3>
                    <video id="gcash-video" autoplay playsinline muted aria-label="Receipt camera preview"></video>
                    <div class="gcash-actions">
                        <button class="btn" type="button" id="gcash-capture">Capture and read</button>
                        <button class="btn btn-secondary" type="button" id="gcash-camera-close">Close camera</button>
                    </div>
                    <p id="gcash-ocr-status" role="status" aria-live="polite"></p>
                    <div id="gcash-ocr-review" hidden>
                        <label class="pos-field-label" for="gcash-ocr-candidate">Suggested reference — correct before using</label>
                        <input class="pos-input" id="gcash-ocr-candidate" type="text" inputmode="numeric" autocomplete="off" maxlength="64">
                        <button class="btn" type="button" id="gcash-ocr-use">Use this reference</button>
                    </div>
                </section>
                </div>
            </section>
            <section class="pos-panel gcash-review-panel" aria-labelledby="gcash-preview-title">
                <div class="pos-panel-header gcash-panel-header">
                    <div class="pos-panel-title">
                        <div class="pos-panel-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></div>
                        <h2 id="gcash-preview-title">Exchange summary</h2>
                    </div>
                    <span id="gcash-review-type" class="gcash-status">Cash-In</span>
                </div>
                    <div class="gcash-exchange-review">
                    <section id="gcash-preview" class="gcash-preview-panel" aria-labelledby="gcash-preview-title">
                        <div id="gcash-preview-empty" class="gcash-preview-empty">
                            <p id="gcash-preview-status" class="gcash-preview-status" role="status" aria-live="polite">Enter an amount to see the fee and cash to collect.</p>
                        </div>
                        <div id="gcash-preview-content" class="gcash-preview-content" hidden>
                            <div class="gcash-preview-grid">
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label"><i class="bi bi-receipt" aria-hidden="true"></i><span id="preview-fee-label">Store service fee</span></span>
                                    <span class="gcash-preview-value" id="preview-fee">₱0.00</span>
                                </div>
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Receive GCash from customer</span></span>
                                    <span class="gcash-preview-value" id="preview-customer-transfer">₱0.00</span>
                                </div>
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label"><i class="bi bi-cash-stack" aria-hidden="true"></i><span id="preview-counter-label">Collect cash from customer</span></span>
                                    <span class="gcash-preview-value" id="preview-counter-collect">₱0.00</span>
                                </div>
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label"><i class="bi bi-cash-stack" aria-hidden="true"></i><span>Pay cash to customer</span></span>
                                    <span class="gcash-preview-value" id="preview-cash-payout">₱0.00</span>
                                </div>
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Send GCash to customer</span></span>
                                    <span class="gcash-preview-value" id="preview-wallet-transfer">₱0.00</span>
                                </div>
                            </div>
                            <details class="gcash-balance-details">
                                <summary>Exchange instructions</summary>
                                <p id="gcash-preview-instructions" class="gcash-help"></p>
                            </details>
                            <details class="gcash-balance-details">
                                <summary>Balance details</summary>
                            <section id="gcash-projected-balances" aria-label="Expected balances after completion" aria-live="polite" hidden>
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label">Wallet after completion</span>
                                    <span class="gcash-preview-value" id="gcash-projected-wallet">—</span>
                                </div>
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label">Cash box after completion</span>
                                    <span class="gcash-preview-value" id="gcash-projected-cash">—</span>
                                </div>
                                <p class="gcash-help">Projected expected balances if this exchange is completed. Saving as Pending does not change balances.</p>
                            </section>
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label">Net service cash</span>
                                    <span class="gcash-preview-value" id="preview-net-cash">₱0.00</span>
                                </div>
                                <div class="gcash-preview-item">
                                    <span class="gcash-preview-label">Net wallet</span>
                                    <span class="gcash-preview-value" id="preview-net-wallet">₱0.00</span>
                                </div>
                            </details>
                        </div>
                    </section>
                    <div id="gcash-cash-in-confirm-group" class="gcash-cash-in-confirm-group" hidden>
                        <label class="gcash-confirm" id="gcash-confirm-wallet-label">
                            <input type="checkbox" id="gcash-confirm-wallet" name="confirmed_wallet">
                            <span id="gcash-confirm-wallet-text">I confirmed the wallet transfer of PHP 0.00 in the authorized GCash app</span>
                        </label>
                        <label class="receipt-auto-print"><input type="checkbox" id="gcash-print-receipt"> Print receipt automatically</label>
                        <label class="gcash-confirm" id="gcash-confirm-cash-label">
                            <input type="checkbox" id="gcash-confirm-cash" name="confirmed_cash">
                            <span id="gcash-confirm-cash-text">I confirmed receipt of PHP 0.00 physical cash at the counter</span>
                        </label>
                    </div>
                    </div>
                    <div class="gcash-actions gcash-submit-actions">
                        <button class="btn btn-primary" type="button" id="gcash-complete-cash-in" hidden>Complete Cash-In</button>
                        <button class="btn btn-primary" type="button" id="gcash-complete-cash-out" hidden>Complete Cash-Out</button>
                        <button class="btn btn-secondary" type="button" id="gcash-needs-checking-btn" hidden>Hold for checking</button>
                        <button class="btn btn-secondary" type="submit" id="gcash-save">Save as Pending</button>
                    </div>
            </section>
            </form>
        </div>

        <div role="tabpanel" id="panel-history" aria-labelledby="tab-history" tabindex="0" class="gcash-tabpanel" hidden>
            <section class="pos-panel gcash-history" aria-labelledby="gcash-history-title">
                <div class="pos-panel-header">
                    <div class="pos-panel-title">
                        <div>
                            <h2 id="gcash-history-title">Transaction history</h2>
                        </div>
                    </div>
                    <div class="gcash-history-header-actions">
                        <button type="button" class="btn btn-primary" id="gcash-history-action-cash-in"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>New Cash-In</button>
                        <button type="button" class="btn btn-secondary" id="gcash-history-action-cash-out"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>New Cash-Out</button>
                    </div>
                </div>
                <p id="gcash-history-status" role="status" aria-live="polite">Loading transactions…</p>
                <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="GCash transaction history">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Reference</th>
                                <th scope="col">Type</th>
                                <th scope="col">Principal</th>
                                <th scope="col">Fee</th>
                                <th scope="col">Status</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="gcash-history-body"></tbody>
                    </table>
                </div>
                <div class="gcash-actions gcash-pagination">
                    <button type="button" class="btn btn-secondary" id="gcash-prev" disabled>Previous</button>
                    <span id="gcash-page">Page 1</span>
                    <button type="button" class="btn btn-secondary" id="gcash-next" disabled>Next</button>
                </div>
            </section>
        </div>
        <dialog id="gcash-review-dialog" aria-labelledby="gcash-review-title">
            <form id="gcash-review-form">
                <h2 id="gcash-review-title">Review transaction</h2>
                <p id="gcash-review-summary"></p>
                <p>Check the actual transaction in the authorized receiving GCash account or transaction records. A customer's screenshot alone is insufficient.</p>
                <label class="gcash-confirm"><input type="checkbox" id="gcash-confirmed"> I checked the actual reference, amount, and transaction type in the authorized GCash records.</label>
                <label class="pos-field-label" for="gcash-review-note">Review note (5–500 characters; required for verification or rejection)</label>
                <textarea class="pos-input" id="gcash-review-note" minlength="5" maxlength="500" required rows="3" placeholder="Record how the transaction was checked, or why it was rejected."></textarea>
                <div class="gcash-actions"><button class="btn" type="submit" id="gcash-verify">Verify transaction</button><button class="btn btn-secondary" type="button" id="gcash-reject">Reject</button><button class="btn btn-secondary" type="button" id="gcash-review-cancel">Cancel</button></div>
            </form>
        </dialog>
        <dialog id="gcash-open-dialog" aria-labelledby="gcash-open-title">
            <form id="gcash-open-form">
                <h2 id="gcash-open-title">Open GCash Shift</h2>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-open-cash">Cash on Hand (PHP)</label>
                    <input class="pos-input" id="gcash-open-cash" name="opening_cash" type="number" step="0.01" min="0" required placeholder="0.00">
                    <small>Enter physical cash in drawer</small>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-open-wallet">GCash Wallet Balance (PHP)</label>
                    <input class="pos-input" id="gcash-open-wallet" name="opening_wallet" type="number" step="0.01" min="0" required placeholder="0.00">
                    <small>Enter current balance in GCash app</small>
                </div>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-open-submit">Start Session</button>
                    <button class="btn btn-secondary" type="button" id="gcash-open-cancel">Cancel</button>
                </div>
            </form>
        </dialog>
        <dialog id="gcash-close-dialog" aria-labelledby="gcash-close-title">
            <form id="gcash-close-form">
                <h2 id="gcash-close-title">Reconcile &amp; Close GCash responsibility</h2>
                <div class="gcash-preview-grid" style="margin-bottom: 1rem;">
                    <div class="gcash-preview-item">
                        <span class="gcash-preview-label">Expected service cash</span>
                        <span class="gcash-preview-value" id="gcash-close-expected-cash">₱0.00</span>
                    </div>
                    <div class="gcash-preview-item">
                        <span class="gcash-preview-label">Expected wallet balance</span>
                        <span class="gcash-preview-value" id="gcash-close-expected-wallet">₱0.00</span>
                    </div>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-close-cash">Actual counted service cash (PHP)</label>
                    <input class="pos-input" id="gcash-close-cash" name="closing_cash" type="number" step="0.01" min="0" required placeholder="0.00">
                    <small id="gcash-close-cash-variance" role="status" aria-live="polite"></small>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-close-wallet">Actual checked wallet balance (PHP)</label>
                    <input class="pos-input" id="gcash-close-wallet" name="closing_wallet" type="number" step="0.01" min="0" required placeholder="0.00">
                    <small id="gcash-close-wallet-variance" role="status" aria-live="polite"></small>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-close-carryover">Unresolved carryover count</label>
                    <input class="pos-input" id="gcash-close-carryover" name="carryover" type="number" min="0" step="1" value="0">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-close-notes">Closing notes (optional, or explain any variance)</label>
                    <textarea class="pos-input" id="gcash-close-notes" name="notes" rows="2" maxlength="500"></textarea>
                </div>
                <div class="gcash-actions">
                    <button class="btn" type="submit" id="gcash-close-submit">Confirm &amp; Close</button>
                    <button class="btn btn-secondary" type="button" id="gcash-close-cancel">Cancel</button>
                </div>
            </form>
        </dialog>
        <dialog id="gcash-handover-dialog" aria-labelledby="gcash-handover-title">
            <form id="gcash-handover-form">
                <h2 id="gcash-handover-title">Hand over GCash responsibility</h2>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-handover-incoming">Incoming Cashier</label>
                    <select class="pos-input" id="gcash-handover-incoming" name="incoming_cashier_id" required>
                        <option value="">Select Cashier…</option>
                        <?php
                        $cashiersStmt = $pdo->prepare("SELECT u.user_id, u.full_name, u.username FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name = 'cashier' AND u.status = 'active' AND u.user_id <> ? ORDER BY u.full_name");
                        $cashiersStmt->execute([(int)$_SESSION['user_id']]);
                        foreach ($cashiersStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                            echo '<option value="' . (int)$c['user_id'] . '">' . htmlspecialchars($c['full_name'] . ' (' . $c['username'] . ')') . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-handover-cash">Counted service cash handed over (PHP)</label>
                    <input class="pos-input" id="gcash-handover-cash" name="counted_cash" type="number" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-handover-wallet">Checked wallet balance handed over (PHP)</label>
                    <input class="pos-input" id="gcash-handover-wallet" name="checked_wallet" type="number" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-handover-notes">Handover notes (optional)</label>
                    <textarea class="pos-input" id="gcash-handover-notes" name="notes" rows="2" maxlength="500"></textarea>
                </div>
                <div class="gcash-actions">
                    <button class="btn" type="submit" id="gcash-handover-submit">Confirm Handover</button>
                    <button class="btn btn-secondary" type="button" id="gcash-handover-cancel">Cancel</button>
                </div>
            </form>
        </dialog>
        <dialog id="gcash-needs-checking-dialog" aria-labelledby="gcash-needs-checking-title" aria-describedby="gcash-nc-help">
            <form id="gcash-needs-checking-form">
                <h2 id="gcash-needs-checking-title">Hold Cash-In for checking</h2>
                <p id="gcash-nc-help" class="gcash-help">Save as Needs checking for review. Select only what actually happened.</p>
                <div class="gcash-preview-grid">
                    <div class="gcash-preview-item">
                        <span class="gcash-preview-label">Principal amount</span>
                        <span class="gcash-preview-value" id="gcash-nc-preview-principal">₱0.00</span>
                    </div>
                    <div class="gcash-preview-item">
                        <span class="gcash-preview-label">Service fee</span>
                        <span class="gcash-preview-value" id="gcash-nc-preview-fee">₱0.00</span>
                    </div>
                </div>
                <fieldset class="gcash-nc-movements">
                    <legend>What happened?</legend>
                <label class="gcash-confirm">
                    <input type="checkbox" id="gcash-nc-cash-collected" name="cash_collected">
                    <span id="gcash-nc-cash-desc">Cash collected from customer (<span id="gcash-nc-cash-text">₱0.00</span>)</span>
                </label>
                <label class="gcash-confirm">
                    <input type="checkbox" id="gcash-nc-wallet-sent" name="wallet_sent">
                    <span id="gcash-nc-wallet-desc">Wallet transfer sent to customer (<span id="gcash-nc-wallet-text">₱0.00</span>)</span>
                </label>
                </fieldset>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-nc-notes">Reason for checking (required)</label>
                    <textarea class="pos-input" id="gcash-nc-notes" name="notes" minlength="5" maxlength="500" required rows="2" aria-describedby="gcash-nc-notes-help" placeholder="e.g. Cash received, wallet transfer not confirmed."></textarea>
                    <small id="gcash-nc-notes-help">At least 5 characters.</small>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-nc-reference">Reference number (optional)</label>
                    <input class="pos-input" id="gcash-nc-reference" name="reference" type="text" autocomplete="off" spellcheck="false" maxlength="32" placeholder="Leave blank if unknown">
                </div>
                <div class="gcash-actions">
                    <button class="btn" type="submit" id="gcash-nc-submit">Hold for checking</button>
                    <button class="btn btn-secondary" type="button" id="gcash-nc-cancel">Cancel</button>
                </div>
            </form>
        </dialog>
        <dialog id="gcash-funding-dialog" aria-labelledby="gcash-funding-title">
            <form id="gcash-funding-form">
                <h2 id="gcash-funding-title">Record Funding Movement</h2>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-type">Movement Type</label>
                    <select class="pos-input" id="gcash-funding-type" name="movement_type" required>
                        <option value="replenishment">Replenishment</option>
                        <option value="withdrawal">Withdrawal</option>
                        <option value="product_drawer_transfer">Product Drawer Transfer</option>
                        <option value="owner_capital">Owner Capital</option>
                    </select>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-fund-type">Fund Type</label>
                    <select class="pos-input" id="gcash-funding-fund-type" name="fund_type" required>
                        <option value="cash">Service Cash</option>
                        <option value="wallet">Service Wallet</option>
                    </select>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-direction">Direction</label>
                    <select class="pos-input" id="gcash-funding-direction" name="direction" required>
                        <option value="in">In (Adding to service funds)</option>
                        <option value="out">Out (Deducting from service funds)</option>
                    </select>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-amount">Amount (PHP)</label>
                    <input class="pos-input" id="gcash-funding-amount" name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-evidence">Evidence Reference</label>
                    <input class="pos-input" id="gcash-funding-evidence" name="evidence_reference" type="text" maxlength="120" placeholder="Required for wallet, transfer, or owner capital">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-reason">Reason (min 5 characters)</label>
                    <input class="pos-input" id="gcash-funding-reason" name="reason" type="text" minlength="5" maxlength="255" required placeholder="Reason for fund movement">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-approver">Administrator username or email</label>
                    <input class="pos-input" id="gcash-funding-approver" name="approver_username" type="text" required autocomplete="off">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-password">Administrator password</label>
                    <input class="pos-input" id="gcash-funding-password" name="approver_password" type="password" required autocomplete="new-password">
                </div>
                <div class="gcash-actions">
                    <button class="btn" type="submit" id="gcash-funding-submit">Record Movement</button>
                    <button class="btn btn-secondary" type="button" id="gcash-funding-cancel">Cancel</button>
                </div>
            </form>
        </dialog>
        <dialog id="gcash-resolve-dialog" aria-labelledby="gcash-resolve-title">
            <form id="gcash-resolve-form">
                <h2 id="gcash-resolve-title">Resolve Needs Checking</h2>
                <p id="gcash-resolve-summary" style="font-weight: 600;"></p>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-resolve-action">Resolution Action</label>
                    <select class="pos-input" id="gcash-resolve-action" name="resolve_action" required>
                        <option value="complete">Complete transaction (confirm remaining movement)</option>
                        <option value="cancel">Cancel transaction (record offsetting reversal if money moved)</option>
                    </select>
                </div>
                <div class="gcash-field" id="gcash-resolve-ref-group">
                    <label class="pos-field-label" for="gcash-resolve-reference">Reference number</label>
                    <input class="pos-input" id="gcash-resolve-reference" name="reference" type="text" maxlength="32" placeholder="Required if wallet was unconfirmed">
                </div>
                <div class="gcash-field" id="gcash-resolve-notes-group">
                    <label class="pos-field-label" for="gcash-resolve-notes">Resolution notes / cancellation reason</label>
                    <input class="pos-input" id="gcash-resolve-notes" name="notes" type="text" minlength="5" maxlength="255" placeholder="Minimum 5 characters">
                </div>
                <div id="gcash-resolve-auth-group" hidden>
                    <p style="font-size: 0.85rem; color: var(--color-warning, #d97706); margin-bottom: 0.5rem;">
                        <strong>Administrator authorization required:</strong> Prior money movement will be offset by a linked adjustment.
                    </p>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-resolve-approver">Administrator username or email</label>
                        <input class="pos-input" id="gcash-resolve-approver" name="approver_username" type="text" autocomplete="off">
                    </div>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-resolve-password">Administrator password</label>
                        <input class="pos-input" id="gcash-resolve-password" name="approver_password" type="password" autocomplete="new-password">
                    </div>
                </div>
                <label class="gcash-confirmation" id="gcash-resolve-print-choice"><input type="checkbox" id="gcash-resolve-print-receipt"> Print receipt automatically</label>
                <div class="gcash-actions">
                    <button class="btn" type="submit" id="gcash-resolve-submit">Confirm Resolution</button>
                    <button class="btn btn-secondary" type="button" id="gcash-resolve-cancel">Cancel</button>
                </div>
            </form>
        </dialog>
        <dialog id="gcash-correction-dialog" aria-labelledby="gcash-correction-title" aria-describedby="gcash-correction-summary">
            <form id="gcash-correction-form">
                <h2 id="gcash-correction-title">Record correction</h2>
                <p id="gcash-correction-summary"></p>
                <div class="gcash-correction-amounts">
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-correction-cash-delta">Service cash change (+/− ₱)</label>
                    <input class="pos-input" id="gcash-correction-cash-delta" name="service_cash_delta" type="number" step="0.01" value="0.00">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-correction-wallet-delta">Wallet change (+/− ₱)</label>
                    <input class="pos-input" id="gcash-correction-wallet-delta" name="wallet_delta" type="number" step="0.01" value="0.00">
                </div>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-correction-reason">Reason (at least 5 characters)</label>
                    <input class="pos-input" id="gcash-correction-reason" name="reason" type="text" minlength="5" maxlength="255" required placeholder="e.g. Service fee overcharged">
                </div>
                <fieldset class="gcash-correction-approval">
                    <legend>Administrator approval</legend>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-correction-approver">Username or email</label>
                    <input class="pos-input" id="gcash-correction-approver" name="approver_username" type="text" required autocomplete="off">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-correction-password">Password</label>
                    <input class="pos-input" id="gcash-correction-password" name="approver_password" type="password" required autocomplete="new-password">
                </div>
                </fieldset>
                <div class="gcash-actions">
                    <button class="btn" type="submit" id="gcash-correction-submit">Record correction</button>
                    <button class="btn btn-secondary" type="button" id="gcash-correction-cancel">Cancel</button>
                </div>
            </form>
        </dialog>
        <dialog id="gcash-waiver-dialog" aria-labelledby="gcash-waiver-title" aria-describedby="gcash-waiver-summary">
            <form id="gcash-waiver-form">
                <h2 id="gcash-waiver-title">Waive closing variance</h2>
                <p id="gcash-waiver-summary" style="font-weight: 600;"></p>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-waiver-notes">Reason (at least 5 characters)</label>
                    <textarea class="pos-input" id="gcash-waiver-notes" name="waiver_notes" minlength="5" maxlength="500" required rows="3"></textarea>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-waiver-approver">Administrator username or email</label>
                    <input class="pos-input" id="gcash-waiver-approver" name="approver_username" type="text" required autocomplete="off">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-waiver-password">Administrator password</label>
                    <input class="pos-input" id="gcash-waiver-password" name="approver_password" type="password" required autocomplete="new-password">
                </div>
                <div class="gcash-actions">
                    <button class="btn" type="submit" id="gcash-waiver-submit">Waive variance</button>
                    <button class="btn btn-secondary" type="button" id="gcash-waiver-cancel">Cancel</button>
                </div>
            </form>
        </dialog>
        <?php require __DIR__ . '/../../../backend/app/Views/gcash_receipt_dialog.php'; ?>
    </main>
</div>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt-dialog.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt-dialog.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/gcash-reference.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/gcash-reference.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/gcash.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/gcash.js')) ?>"></script>
</body>
</html>
