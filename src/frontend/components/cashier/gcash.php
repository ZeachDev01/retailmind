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
</head>
<body class="cashier-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="gcash-workspace" data-api="<?= htmlspecialchars(app_url('components/cashier/gcash_api.php'), ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" data-min-length="<?= $gcashRules['min_length'] ?>" data-max-length="<?= $gcashRules['max_length'] ?>">
        <header class="topbar cashier-topbar">
            <div class="cashier-heading">
                <h1>GCash</h1>
                <p>Capture a reference. Check the actual transaction. Confirm only after verification.</p>
            </div>
            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to POS
            </a>
        </header>
        <p class="gcash-notice"><strong>A reference number is not proof of payment.</strong> Check the reference, amount, and transaction type in the authorized receiving GCash account or its transaction records. These records do not post sales or change cash drawer balances.</p>
        <section class="pos-panel" aria-labelledby="gcash-entry-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-entry-title">New transaction</h2>
                        <p>Save first as Pending, then verify from transaction history.</p>
                    </div>
                </div>
            </div>
            <form id="gcash-form" class="gcash-form">
                <div class="gcash-field gcash-reference-field">
                    <label class="pos-field-label" for="gcash-reference">GCash reference number</label>
                    <input class="pos-input" id="gcash-reference" name="reference" type="text" autocomplete="off" spellcheck="false" maxlength="2048" required aria-describedby="gcash-reference-help gcash-scan-status gcash-duplicate">
                    <small id="gcash-reference-help">Numeric reference: <?= $gcashRules['min_length'] === $gcashRules['max_length'] ? $gcashRules['min_length'] : $gcashRules['min_length'] . '–' . $gcashRules['max_length'] ?> digits. Focus here before scanning; Enter checks the scan and never saves.</small>
                    <span id="gcash-scan-status" role="status" aria-live="polite">Ready for manual entry or USB scanner.</span>
                    <span id="gcash-duplicate" role="alert" hidden>This reference has already been recorded. Check transaction history before proceeding.</span>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-amount">Amount (PHP)</label>
                    <input class="pos-input" id="gcash-amount" name="amount" type="number" inputmode="decimal" min="0.01" max="99999999.99" step="0.01" required>
                </div>
                <div class="gcash-field">
                    <label class="pos-field-label" for="gcash-type">Transaction type</label>
                    <select class="pos-input" id="gcash-type" name="type" required>
                        <option value="Payment">Payment</option>
                        <option value="Cash-In">Cash-In</option>
                        <option value="Cash-Out">Cash-Out</option>
                    </select>
                </div>
                <div class="gcash-actions">
                    <button class="btn" type="submit" id="gcash-save">Save as Pending</button>
                    <button class="btn btn-secondary" type="button" id="gcash-manual">Manual entry / USB scan</button>
                    <button class="btn btn-secondary" type="button" id="gcash-camera-open">Camera OCR (Tesseract.js)</button>
                </div>
            </form>
            <p class="gcash-help">USB HID scanners type into the focused field. A 2D scanner can read supported QR codes containing a reference; ordinary scanners cannot read plain text. Merchant payment QR codes may contain no transaction reference. Use manual entry or camera OCR for plain text.</p>
            <section id="gcash-camera" class="gcash-camera" aria-labelledby="gcash-camera-title" hidden>
                <h3 id="gcash-camera-title">Read receipt text</h3>
                <p><strong>Tesseract.js — free OCR library.</strong> Show the reference label clearly. Avoid glare and fill the frame. Receipt images are processed in your browser. Internet access is needed to load the library and language data; no API key is required. Review and correct the suggested reference before using it.</p>
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
        </section>
        <section class="pos-panel gcash-history" aria-labelledby="gcash-history-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title"><div><h2 id="gcash-history-title">Your transaction history</h2><p>Philippine time · Pending records require a separate review.</p></div></div>
                <button type="button" class="btn btn-secondary" id="gcash-refresh">Refresh</button>
            </div>
            <p id="gcash-history-status" role="status" aria-live="polite"></p>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="GCash transaction history">
                <table><thead><tr><th>ID / Date</th><th>Reference</th><th>Type</th><th>Amount</th><th>Status / Note</th><th>Review</th></tr></thead><tbody id="gcash-history-body"></tbody></table>
            </div>
            <div class="gcash-actions gcash-pagination"><button type="button" class="btn btn-secondary" id="gcash-prev" disabled>Previous</button><span id="gcash-page">Page 1</span><button type="button" class="btn btn-secondary" id="gcash-next" disabled>Next</button></div>
        </section>
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
    </main>
</div>
<script src="<?= htmlspecialchars(app_url('assets/js/gcash-reference.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/gcash-reference.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/gcash.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/gcash.js')) ?>"></script>
</body>
</html>
