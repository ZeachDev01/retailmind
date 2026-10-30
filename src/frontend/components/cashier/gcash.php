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
            <div class="pos-panel-header gcash-session-header">
                <div class="pos-panel-title gcash-session-brand">
                    <img id="gcash-session-title" class="gcash-session-logo" src="<?= htmlspecialchars(app_url('assets/img/gcash-logo.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="GCash" width="88" height="21">
                    <span id="gcash-session-status" class="gcash-status">Checking…</span>
                </div>
                <div class="gcash-session-header-actions">
                    <button class="btn btn-primary" type="button" id="gcash-open-session-btn" hidden><i class="bi bi-unlock" aria-hidden="true"></i>Open GCash</button>
                    <button class="btn btn-secondary" type="button" id="gcash-funding-btn" hidden><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Move funds</button>
                    <button class="btn btn-secondary" type="button" id="gcash-handover-session-btn" hidden><i class="bi bi-person-check" aria-hidden="true"></i>Hand over</button>
                    <button class="btn btn-secondary" type="button" id="gcash-close-session-btn" hidden><i class="bi bi-check2-square" aria-hidden="true"></i>Reconcile &amp; Close</button>
                    <button class="btn btn-secondary" type="button" id="gcash-waive-btn" hidden><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Review difference</button>
                </div>
            </div>
            <div class="gcash-session-content">
                <div id="gcash-session-banner" class="gcash-session-banner" role="status" aria-live="polite">
                    <p id="gcash-session-message">Loading status…</p>
                </div>
                <section class="pos-panel gcash-follow-up-panel" aria-labelledby="gcash-follow-up-title">
                    <div class="gcash-follow-up-header">
                        <span class="gcash-follow-up-label" id="gcash-follow-up-title"><i class="bi bi-clock-history" aria-hidden="true"></i> Unfinished exchanges</span>
                    </div>
                    <div id="gcash-follow-up" class="gcash-follow-up-content" aria-live="polite">Loading follow-up work…</div>
                </section>
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
                        <span class="gcash-card-label">Physical Service Cash</span>
                        <strong class="gcash-card-value" id="gcash-expected-cash">—</strong>
                    </div>
                    <div class="gcash-card gcash-card-balance">
                        <span class="gcash-card-label">GCash App Wallet</span>
                        <strong class="gcash-card-value" id="gcash-expected-wallet">—</strong>
                    </div>
                </div>
                <details class="gcash-balance-details">
                    <summary>Last balance check</summary>
                    <p class="gcash-card-sub" id="gcash-cash-checked">Last check: —</p>
                </details>
                <p class="gcash-help" id="gcash-balances-message" role="status"></p>
            </section>

            <form id="gcash-form" class="gcash-form">
                <section class="pos-panel gcash-entry-panel" aria-labelledby="gcash-entry-title">
                    <div class="pos-panel-header gcash-panel-header">
                        <div class="pos-panel-title">
                            <h2 id="gcash-entry-title">Transaction details</h2>
                        </div>
                    </div>
                    <div class="gcash-entry-scroll">
                        <div class="gcash-entry-fields">
                            <div class="gcash-entry-row">
                                <div class="gcash-field">
                                    <label class="pos-field-label" for="gcash-type">Transaction type</label>
                                    <select class="pos-input" id="gcash-type" name="type" required>
                                        <option value="Cash-In">Cash-In</option>
                                        <option value="Cash-Out">Cash-Out</option>
                                    </select>
                                </div>
                                <div class="gcash-field" id="gcash-fee-method-group" hidden>
                                    <label class="pos-field-label" for="gcash-fee-method">Fee payment</label>
                                    <select class="pos-input" id="gcash-fee-method" name="fee_method">
                                        <option value="Fee via GCash">Fee via GCash</option>
                                        <option value="Fee in cash">Fee in cash</option>
                                    </select>
                                </div>
                            </div>

                            <div class="gcash-field">
                                <label class="pos-field-label" for="gcash-amount">Principal amount (PHP)</label>
                                <input class="pos-input gcash-amount-input" id="gcash-amount" name="amount" type="number" inputmode="numeric" min="1" max="50000" step="1" required placeholder="1–50,000 · whole pesos" aria-describedby="gcash-amount-help">
                                <small id="gcash-amount-help" class="sr-only">₱1–₱50,000 in whole pesos, excluding the service fee.</small>
                            </div>

                            <div class="gcash-field gcash-reference-field">
                                <label class="pos-field-label" for="gcash-reference">GCash reference number</label>
                                <input class="pos-input gcash-reference-input" id="gcash-reference" name="reference" type="text" autocomplete="off" spellcheck="false" maxlength="2048" inputmode="numeric" placeholder="Scan or enter digits" required aria-describedby="gcash-scan-status gcash-duplicate">
                                <span id="gcash-scan-status" role="status" aria-live="polite"></span>
                                <span id="gcash-duplicate" role="alert" hidden>Duplicate reference. Check transaction history.</span>
                                <div class="gcash-reference-tools">
                                    <button class="btn btn-secondary" type="button" id="gcash-camera-open"><i class="bi bi-camera" aria-hidden="true"></i> Take photo</button>
                                    <button class="btn btn-secondary" type="button" id="gcash-photo-open"><i class="bi bi-image" aria-hidden="true"></i> Choose photo</button>
                                    <input id="gcash-camera-file" type="file" accept="image/*" capture="environment" hidden aria-label="Take a photo of the GCash receipt or notification">
                                    <input id="gcash-photo-file" type="file" accept="image/*" hidden aria-label="Choose a GCash receipt or notification photo">
                                </div>
                                <section id="gcash-camera" class="gcash-camera" aria-label="Reference from photo" hidden>
                                    <button id="gcash-photo-preview-open" class="gcash-photo-preview-open" type="button">
                                        <img id="gcash-photo-preview" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E" alt="Selected GCash receipt or notification photo">
                                        <span>Enlarge photo</span>
                                    </button>
                                    <p id="gcash-ocr-status" role="status" aria-live="polite"></p>
                                    <div id="gcash-ocr-review" class="gcash-ocr-review" hidden>
                                        <label class="pos-field-label" for="gcash-ocr-candidate">Suggested reference</label>
                                        <input class="pos-input" id="gcash-ocr-candidate" type="text" inputmode="numeric" autocomplete="off" maxlength="64" aria-describedby="gcash-ocr-status">
                                        <button class="btn btn-primary" type="button" id="gcash-ocr-use">Use reference</button>
                                    </div>
                                    <button class="btn btn-secondary" type="button" id="gcash-camera-close">Close</button>
                                </section>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pos-panel gcash-review-panel" aria-labelledby="gcash-preview-title">
                    <div class="pos-panel-header gcash-panel-header">
                        <div class="pos-panel-title">
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
                                    <div class="gcash-preview-item gcash-preview-item-highlight">
                                        <span class="gcash-preview-label"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Receive GCash from customer</span></span>
                                        <span class="gcash-preview-value" id="preview-customer-transfer">₱0.00</span>
                                    </div>
                                    <div class="gcash-preview-item gcash-preview-item-highlight">
                                        <span class="gcash-preview-label"><i class="bi bi-cash-stack" aria-hidden="true"></i><span id="preview-counter-label">Collect cash from customer</span></span>
                                        <span class="gcash-preview-value" id="preview-counter-collect">₱0.00</span>
                                    </div>
                                    <div class="gcash-preview-item gcash-preview-item-highlight">
                                        <span class="gcash-preview-label"><i class="bi bi-cash-stack" aria-hidden="true"></i><span>Pay cash to customer</span></span>
                                        <span class="gcash-preview-value" id="preview-cash-payout">₱0.00</span>
                                    </div>
                                    <div class="gcash-preview-item gcash-preview-item-highlight">
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
                                <span id="gcash-confirm-wallet-text">Confirmed wallet transfer in GCash app</span>
                            </label>
                            <label class="gcash-confirm" id="gcash-confirm-cash-label">
                                <input type="checkbox" id="gcash-confirm-cash" name="confirmed_cash">
                                <span id="gcash-confirm-cash-text">Confirmed physical cash at counter</span>
                            </label>
                            <label class="receipt-auto-print">
                                <input type="checkbox" id="gcash-print-receipt">
                                <span>Print receipt automatically</span>
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
                <div class="pos-panel-header gcash-panel-header">
                    <div class="pos-panel-title">
                        <h2 id="gcash-history-title">Transaction history</h2>
                    </div>
                </div>
                <p id="gcash-history-status" class="gcash-history-status" role="status" aria-live="polite">Loading transactions…</p>
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

        <dialog id="gcash-photo-dialog" aria-labelledby="gcash-photo-dialog-title">
            <div class="gcash-photo-dialog-header">
                <h2 id="gcash-photo-dialog-title">Selected photo</h2>
                <button class="btn btn-secondary" type="button" id="gcash-photo-dialog-close">Close photo</button>
            </div>
            <img id="gcash-photo-dialog-image" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E" alt="Selected GCash receipt or notification photo enlarged">
        </dialog>

        <dialog id="gcash-review-dialog" aria-labelledby="gcash-review-title">
            <form id="gcash-review-form">
                <h2 id="gcash-review-title">Review transaction</h2>
                <p id="gcash-review-summary" class="gcash-dialog-summary"></p>
                <label class="gcash-confirm"><input type="checkbox" id="gcash-confirmed"> Verified against the authorized GCash account records (not a screenshot).</label>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-review-note">Review note</label>
                    <textarea class="pos-input" id="gcash-review-note" minlength="5" maxlength="500" required rows="3" placeholder="How it was verified or why it was rejected (5–500 characters)"></textarea>
                </div>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-verify">Verify transaction</button>
                    <button class="btn btn-secondary" type="button" id="gcash-reject">Reject</button>
                    <button class="btn btn-secondary" type="button" id="gcash-review-cancel">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="gcash-open-dialog" aria-labelledby="gcash-open-title">
            <form id="gcash-open-form">
                <h2 id="gcash-open-title">Open GCash</h2>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-open-cash">Counted service cash (PHP)</label>
                    <input class="pos-input" id="gcash-open-cash" name="opening_cash" type="number" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-open-wallet">Checked GCash app balance (PHP)</label>
                    <input class="pos-input" id="gcash-open-wallet" name="opening_wallet" type="number" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-open-submit">Open GCash</button>
                    <button class="btn btn-secondary" type="button" id="gcash-open-cancel">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="gcash-close-dialog" aria-labelledby="gcash-close-title">
            <form id="gcash-close-form">
                <h2 id="gcash-close-title">Reconcile &amp; Close</h2>
                <div class="gcash-preview-grid gcash-close-summary">
                    <div class="gcash-preview-item">
                        <span class="gcash-preview-label">Expected service cash</span>
                        <span class="gcash-preview-value" id="gcash-close-expected-cash">₱0.00</span>
                    </div>
                    <div class="gcash-preview-item">
                        <span class="gcash-preview-label">Expected wallet balance</span>
                        <span class="gcash-preview-value" id="gcash-close-expected-wallet">₱0.00</span>
                    </div>
                </div>
                <div class="gcash-correction-amounts">
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-close-cash">Counted service cash (PHP)</label>
                        <input class="pos-input" id="gcash-close-cash" name="closing_cash" type="number" step="0.01" min="0" required placeholder="0.00">
                        <small id="gcash-close-cash-variance" role="status" aria-live="polite"></small>
                    </div>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-close-wallet">Checked wallet balance (PHP)</label>
                        <input class="pos-input" id="gcash-close-wallet" name="closing_wallet" type="number" step="0.01" min="0" required placeholder="0.00">
                        <small id="gcash-close-wallet-variance" role="status" aria-live="polite"></small>
                    </div>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-close-carryover">Unresolved carryover count</label>
                    <input class="pos-input" id="gcash-close-carryover" name="carryover" type="number" min="0" step="1" value="0">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-close-notes">Closing notes</label>
                    <textarea class="pos-input" id="gcash-close-notes" name="notes" rows="2" maxlength="500" placeholder="Optional, or explain any variance"></textarea>
                </div>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-close-submit">Confirm &amp; Close</button>
                    <button class="btn btn-secondary" type="button" id="gcash-close-cancel">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="gcash-handover-dialog" aria-labelledby="gcash-handover-title">
            <form id="gcash-handover-form">
                <h2 id="gcash-handover-title">Hand over GCash</h2>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-handover-incoming">Incoming staff member</label>
                    <select class="pos-input" id="gcash-handover-incoming" name="incoming_cashier_id" required>
                        <option value="">Select staff member with an open shift…</option>
                        <?php
                        $handoverService = new \App\Services\GCashTransactionService($pdo, $gcashRules);
                        foreach ($handoverService->eligibleHandoverRecipients() as $c) {
                            if ((int)$c['user_id'] === (int)$_SESSION['user_id']) continue;
                            echo '<option value="' . (int)$c['user_id'] . '">' . htmlspecialchars($c['full_name'] . ' (' . $c['username'] . ')') . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="gcash-correction-amounts">
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-handover-cash">Counted service cash (PHP)</label>
                        <input class="pos-input" id="gcash-handover-cash" name="counted_cash" type="number" step="0.01" min="0" required placeholder="0.00">
                    </div>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-handover-wallet">Checked wallet balance (PHP)</label>
                        <input class="pos-input" id="gcash-handover-wallet" name="checked_wallet" type="number" step="0.01" min="0" required placeholder="0.00">
                    </div>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-handover-notes">Notes</label>
                    <textarea class="pos-input" id="gcash-handover-notes" name="notes" rows="2" maxlength="500" placeholder="Optional"></textarea>
                </div>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-handover-submit">Confirm Handover</button>
                    <button class="btn btn-secondary" type="button" id="gcash-handover-cancel">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="gcash-needs-checking-dialog" aria-labelledby="gcash-needs-checking-title">
            <form id="gcash-needs-checking-form">
                <h2 id="gcash-needs-checking-title">Hold Cash-In for checking</h2>
                <div class="gcash-preview-grid gcash-close-summary">
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
                    <legend>Completed movements</legend>
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
                    <label class="pos-field-label" for="gcash-nc-notes">Reason for holding</label>
                    <textarea class="pos-input" id="gcash-nc-notes" name="notes" minlength="5" maxlength="500" required rows="2" placeholder="e.g. Cash received, wallet transfer unconfirmed (min 5 chars)"></textarea>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-nc-reference">Reference number</label>
                    <input class="pos-input" id="gcash-nc-reference" name="reference" type="text" autocomplete="off" spellcheck="false" maxlength="32" placeholder="Optional if unknown">
                </div>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-nc-submit">Hold for checking</button>
                    <button class="btn btn-secondary" type="button" id="gcash-nc-cancel">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="gcash-funding-dialog" aria-labelledby="gcash-funding-title">
            <form id="gcash-funding-form">
                <h2 id="gcash-funding-title">Move service funds</h2>
                <div class="gcash-correction-amounts">
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-funding-type">Movement type</label>
                        <select class="pos-input" id="gcash-funding-type" name="movement_type" required>
                            <option value="replenishment">Replenishment</option>
                            <option value="withdrawal">Withdrawal</option>
                            <option value="product_drawer_transfer">Product Drawer Transfer</option>
                            <option value="owner_capital">Owner Capital</option>
                        </select>
                    </div>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-funding-fund-type">Fund</label>
                        <select class="pos-input" id="gcash-funding-fund-type" name="fund_type" required>
                            <option value="cash">Service Cash</option>
                            <option value="wallet">Service Wallet</option>
                        </select>
                    </div>
                </div>
                <div class="gcash-correction-amounts">
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-funding-direction">Direction</label>
                        <select class="pos-input" id="gcash-funding-direction" name="direction" required>
                            <option value="in">In (Add)</option>
                            <option value="out">Out (Deduct)</option>
                        </select>
                    </div>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-funding-amount">Amount (PHP)</label>
                        <input class="pos-input" id="gcash-funding-amount" name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00">
                    </div>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-evidence">Evidence reference</label>
                    <input class="pos-input" id="gcash-funding-evidence" name="evidence_reference" type="text" maxlength="120" placeholder="Required for wallet, transfer, or owner capital">
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-funding-reason">Reason</label>
                    <input class="pos-input" id="gcash-funding-reason" name="reason" type="text" minlength="5" maxlength="255" required placeholder="Minimum 5 characters">
                </div>
                <fieldset class="gcash-correction-approval">
                    <legend>Administrator approval</legend>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-funding-approver">Username or email</label>
                        <input class="pos-input" id="gcash-funding-approver" name="approver_username" type="text" required autocomplete="off">
                    </div>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-funding-password">Password</label>
                        <input class="pos-input" id="gcash-funding-password" name="approver_password" type="password" required autocomplete="new-password">
                    </div>
                </fieldset>
                <label class="gcash-confirm"><input type="checkbox" id="gcash-funding-receipt" name="receipt_confirmed" required> Funds were physically received or handed over.</label>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-funding-submit">Record Movement</button>
                    <button class="btn btn-secondary" type="button" id="gcash-funding-cancel">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="gcash-resolve-dialog" aria-labelledby="gcash-resolve-title">
            <form id="gcash-resolve-form">
                <h2 id="gcash-resolve-title">Resolve held exchange</h2>
                <p id="gcash-resolve-summary" class="gcash-dialog-summary"></p>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-resolve-action">Action</label>
                    <select class="pos-input" id="gcash-resolve-action" name="resolve_action" required>
                        <option value="complete">Complete transaction</option>
                        <option value="return">Record return / recovery</option>
                        <option value="cancel">Cancel transaction</option>
                    </select>
                </div>
                <div class="gcash-field" id="gcash-resolve-ref-group">
                    <label class="pos-field-label" for="gcash-resolve-reference">Reference number</label>
                    <input class="pos-input" id="gcash-resolve-reference" name="reference" type="text" maxlength="32" placeholder="Required if wallet was unconfirmed">
                </div>
                <div class="gcash-field" id="gcash-resolve-notes-group">
                    <label class="pos-field-label" for="gcash-resolve-notes">Notes / reason</label>
                    <input class="pos-input" id="gcash-resolve-notes" name="notes" type="text" minlength="5" maxlength="255" placeholder="Minimum 5 characters">
                </div>
                <div id="gcash-return-fields" class="gcash-return-fields" hidden>
                    <div class="gcash-correction-amounts">
                        <div class="gcash-field">
                            <label class="pos-field-label">Cash returned/recovered (PHP)
                                <input class="pos-input" type="number" name="returned_cash" min="0" step="0.01" value="0">
                            </label>
                        </div>
                        <div class="gcash-field">
                            <label class="pos-field-label">Wallet returned/recovered (PHP)
                                <input class="pos-input" type="number" name="returned_wallet" min="0" step="0.01" value="0">
                            </label>
                        </div>
                    </div>
                    <div class="gcash-correction-amounts">
                        <div class="gcash-field">
                            <label class="pos-field-label">Fee included (PHP)
                                <input class="pos-input" type="number" name="fee_returned" min="0" step="0.01" value="0">
                            </label>
                        </div>
                        <div class="gcash-field">
                            <label class="pos-field-label">Return evidence
                                <input class="pos-input" type="text" name="evidence" maxlength="255">
                            </label>
                        </div>
                    </div>
                </div>
                <div id="gcash-resolve-auth-group" class="gcash-resolve-auth-group" hidden>
                    <p class="gcash-admin-auth-notice"><strong>Administrator approval required</strong></p>
                    <div class="gcash-correction-amounts">
                        <div class="gcash-field">
                            <label class="pos-field-label" for="gcash-resolve-approver">Username or email</label>
                            <input class="pos-input" id="gcash-resolve-approver" name="approver_username" type="text" autocomplete="off">
                        </div>
                        <div class="gcash-field">
                            <label class="pos-field-label" for="gcash-resolve-password">Password</label>
                            <input class="pos-input" id="gcash-resolve-password" name="approver_password" type="password" autocomplete="new-password">
                        </div>
                    </div>
                </div>
                <label class="gcash-confirm" id="gcash-resolve-print-choice"><input type="checkbox" id="gcash-resolve-print-receipt"> Print receipt automatically</label>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-resolve-submit">Confirm</button>
                    <button class="btn btn-secondary" type="button" id="gcash-resolve-cancel">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="gcash-correction-dialog" aria-labelledby="gcash-correction-title" aria-describedby="gcash-correction-summary">
            <form id="gcash-correction-form">
                <h2 id="gcash-correction-title">Record correction</h2>
                <p id="gcash-correction-summary" class="gcash-dialog-summary"></p>
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
                    <label class="pos-field-label" for="gcash-correction-reason">Reason</label>
                    <input class="pos-input" id="gcash-correction-reason" name="reason" type="text" minlength="5" maxlength="255" required placeholder="e.g. Service fee overcharged (min 5 chars)">
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
                    <button class="btn btn-primary" type="submit" id="gcash-correction-submit">Record correction</button>
                    <button class="btn btn-secondary" type="button" id="gcash-correction-cancel">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog id="gcash-waiver-dialog" aria-labelledby="gcash-waiver-title" aria-describedby="gcash-waiver-summary">
            <form id="gcash-waiver-form">
                <h2 id="gcash-waiver-title">Review closing difference</h2>
                <p id="gcash-waiver-summary" class="gcash-dialog-summary"></p>
                <label class="gcash-confirm"><input id="gcash-review-cash-checked" name="cash_checked" type="checkbox" required> Checked physical service cash difference</label>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-review-cash-explanation">Service cash findings</label>
                    <textarea class="pos-input" id="gcash-review-cash-explanation" name="cash_explanation" required maxlength="500" rows="2"></textarea>
                </div>
                <label class="gcash-confirm"><input id="gcash-review-wallet-checked" name="wallet_checked" type="checkbox" required> Checked GCash wallet difference</label>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-review-wallet-explanation">Wallet findings</label>
                    <textarea class="pos-input" id="gcash-review-wallet-explanation" name="wallet_explanation" required maxlength="500" rows="2"></textarea>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-waiver-notes">Resolution notes</label>
                    <textarea class="pos-input" id="gcash-waiver-notes" name="waiver_notes" minlength="5" maxlength="500" required rows="2" placeholder="Minimum 5 characters"></textarea>
                </div>
                <fieldset class="gcash-correction-approval">
                    <legend>Administrator approval</legend>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-waiver-approver">Username or email</label>
                        <input class="pos-input" id="gcash-waiver-approver" name="approver_username" type="text" required autocomplete="off">
                    </div>
                    <div class="gcash-field">
                        <label class="pos-field-label" for="gcash-waiver-password">Password</label>
                        <input class="pos-input" id="gcash-waiver-password" name="approver_password" type="password" required autocomplete="new-password">
                    </div>
                </fieldset>
                <div class="gcash-actions">
                    <button class="btn btn-primary" type="submit" id="gcash-waiver-submit">Mark reviewed</button>
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
