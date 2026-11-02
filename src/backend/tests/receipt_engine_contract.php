<?php

require_once __DIR__ . '/../app/Receipts/ReceiptEngine.php';
require_once __DIR__ . '/../app/Receipts/ReceiptDetailsService.php';

use App\Receipts\ReceiptEngine;

class ReceiptCountingPDO extends PDO
{
    public array $queries = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }
}
$pdo = new ReceiptCountingPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, display_name TEXT, full_name TEXT, username TEXT, email TEXT, branch_id INTEGER)');
$pdo->exec('CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, paper_width_mm TEXT DEFAULT 80)');
$pdo->exec('CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, register_id INTEGER, cashier_id INTEGER, status TEXT, opened_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE products (product_id INTEGER PRIMARY KEY, branch_id INTEGER, sku TEXT, product_name TEXT)');
$pdo->exec('CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, subtotal REAL)');
$pdo->exec('CREATE TABLE sale_receipt_details (sale_id INTEGER PRIMARY KEY, details_json TEXT NOT NULL)');
$pdo->exec('CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER, total_amount REAL, payment_method TEXT, sale_date TEXT)');
$pdo->exec('CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: " . $message);
    }
};

$engine = new ReceiptEngine($pdo);

// 1. Dynamic paper width resolution (ADR-0006)
$pdo->exec("INSERT INTO users (user_id, display_name, username, email, branch_id) VALUES (1, 'Cashier One', 'c1', 'c1@test.test', 1)");
$pdo->exec("INSERT INTO registers (register_id, name, paper_width_mm) VALUES (10, 'Register 58mm', '58'), (20, 'Register 80mm', '80')");
$pdo->exec("INSERT INTO cashier_shifts (shift_id, register_id, cashier_id, status) VALUES (1, 10, 1, 'open')");

$assert($engine->resolvePaperWidth(1, 'cashier') === 58, 'Open shift on 58mm register resolves to 58mm');
$pdo->exec("UPDATE cashier_shifts SET register_id = 20 WHERE shift_id = 1");
$assert($engine->resolvePaperWidth(1, 'cashier') === 80, 'Open shift on 80mm register resolves to 80mm');

$assert($engine->resolvePaperWidth(1, 'admin') === 80, 'Non-cashier role defaults to 80mm');
$assert($engine->resolvePaperWidth(99, 'cashier') === 80, 'Cashier without open shift defaults to 80mm');
$pdo->exec("UPDATE cashier_shifts SET status = 'closed' WHERE shift_id = 1");
$assert($engine->resolvePaperWidth(1, 'cashier') === 80, 'Closed shift defaults to 80mm');

// 2. Snapshot immutability & preservation
$pdo->exec("INSERT INTO store_settings VALUES ('store_name', 'Original Grocer'), ('currency_symbol', '₱'), ('receipt_footer', 'Thank you for shopping!')");
$pdo->exec("INSERT INTO products VALUES (100, 1, 'SKU-APPLE', 'Fresh Apple')");
$pdo->exec("INSERT INTO cashier_shifts (shift_id, register_id, cashier_id, status) VALUES (2, 10, 1, 'open')");
$pdo->exec("INSERT INTO sales VALUES (501, 1, 2, 25.00, 'cash', '2026-10-15 14:30:00')");
$pdo->exec("INSERT INTO sale_items VALUES (1, 501, 100, 2, 12.50, 25.00)");

$pdo->beginTransaction();
$engine->preserveSale(501);
$pdo->commit();

// Ensure paper_width is NOT stored in snapshot
$savedJson = $pdo->query("SELECT details_json FROM sale_receipt_details WHERE sale_id = 501")->fetchColumn();
$savedData = json_decode((string)$savedJson, true);
$assert(!isset($savedData['paper_width_mm']), 'Paper width is not baked into the immutable snapshot');
$assert($savedData['store']['name'] === 'Original Grocer', 'Store name is captured at transaction time');
$assert($savedData['items'][0]['product_name'] === 'Fresh Apple', 'Product name is captured at transaction time');

// Now alter the live store settings, product, user, and register
$pdo->exec("UPDATE store_settings SET setting_value = 'Mutated Store Name' WHERE setting_key = 'store_name'");
$pdo->exec("UPDATE products SET product_name = 'Rotten Apple', sku = 'SKU-ROTTEN' WHERE product_id = 100");
$pdo->exec("UPDATE users SET display_name = 'Renamed Cashier' WHERE user_id = 1");
$pdo->exec("UPDATE registers SET name = 'Renamed Till' WHERE register_id = 10");

$reprintedSale = $engine->fetchSale(501);
$assert($reprintedSale['receipt_store']['name'] === 'Original Grocer', 'Reprint preserves original Store name despite store settings changes');
$assert($reprintedSale['cashier_name'] === 'Cashier One', 'Reprint preserves cashier name despite user edits');
$reprintedItems = $engine->fetchItems(501);
$assert($reprintedItems[0]['product_name'] === 'Fresh Apple', 'Reprint preserves original product name despite product edits');
$assert($reprintedItems[0]['sku'] === 'SKU-APPLE', 'Reprint preserves original SKU');
$assert(empty($reprintedSale['receipt_historical_notice']), 'Preserved sale has no historical notice');

// 3. Historical / legacy receipt notice
$pdo->exec("INSERT INTO sales VALUES (502, 1, 2, 50.00, 'cash', '2026-09-01 09:00:00')");
$pdo->exec("INSERT INTO sale_items VALUES (2, 502, 100, 4, 12.50, 50.00)");

$legacySale = $engine->fetchSale(502);
$assert(!empty($legacySale['receipt_historical_notice']), 'Unpreserved sale receives historical receipt notice');

// 4. Dispute verification code stability
$vCode = ReceiptEngine::verificationCode($reprintedSale);
$assert($vCode === $reprintedSale['verification_code'], 'Verification code is stable and matches preserved code');
$assert(preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $vCode) === 1, 'Verification code format matches XXXX-XXXX-XXXX');

// 5. ETag and conditional HTTP caching
$etag = ReceiptEngine::computeSaleETag($reprintedSale);
$assert(str_starts_with($etag, '"sale-501-'), 'ETag starts with "sale-501-');

// Test checkConditionalHit with matching If-None-Match
$_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
$pdo->queries = [];
$hit = $engine->checkConditionalHit(501, true, 1);
$assert($hit === true, 'Conditional hit succeeds with matching ETag');
$assert(count($pdo->queries) === 1 && !str_contains($pdo->queries[0], 'JOIN products'),
    'conditional hit uses one evidence lookup without item or duplicate snapshot queries');
$assert(ReceiptEngine::computeSaleETag($reprintedSale, 58) !== $etag,
    'changing current paper width changes the representation validator');
$_SESSION = ['user_id' => 1, 'role' => 'cashier'];
$assert(!$engine->checkConditionalHit(501, false, 1), 'old 80mm validator cannot validate current 58mm paper');
$_SESSION = [];
$changed = json_decode($savedJson, true);
$changed['store']['footer'] = 'Replacement preserved representation';
$pdo->prepare('UPDATE sale_receipt_details SET details_json = ? WHERE sale_id = 501')
    ->execute([json_encode($changed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
$assert(!$engine->checkConditionalHit(501, false, 1), 'changed preserved document cannot return 304 for old validator');
$pdo->prepare('UPDATE sale_receipt_details SET details_json = ? WHERE sale_id = 501')->execute([$savedJson]);
$_SERVER['HTTP_IF_NONE_MATCH'] = 'W/' . $etag;
$assert($engine->checkConditionalHit(501, false, 1), 'weak If-None-Match validates the same representation');
$_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
$assert(!$engine->checkConditionalHit(501, true, 1, 2), 'conditional hit preserves Store scope');

// Test checkConditionalHit with mismatched ETag
$_SERVER['HTTP_IF_NONE_MATCH'] = '"mismatched-etag"';
$_SERVER['HTTP_IF_MODIFIED_SINCE'] = '2099-01-01 00:00:00 GMT';
$miss = $engine->checkConditionalHit(501, true, 1);
$assert($miss === false, 'Conditional hit returns false with mismatched ETag');

// Test checkConditionalHit with If-Modified-Since
unset($_SERVER['HTTP_IF_NONE_MATCH']);
$_SERVER['HTTP_IF_MODIFIED_SINCE'] = '2026-10-15 15:00:00 GMT';
$hitDate = $engine->checkConditionalHit(501, true, 1);
$assert($hitDate === false, 'Transaction date alone cannot validate a changed print layout');

// Test permission check on conditional hit: unowned sale for non-manager
$denied = $engine->checkConditionalHit(501, false, 999);
$assert($denied === false, 'Unowned sale does not produce 304 for unauthorized cashier');

// 6. Structured view data preparation
$viewData = ReceiptEngine::prepareSaleViewData(
    $reprintedSale,
    $reprintedItems,
    $reprintedSale['receipt_store'],
    58
);

$assert($viewData['paper_width_mm'] === 58, 'Structured view data receives resolved paper width');
$assert($viewData['store']['name'] === 'Original Grocer', 'Structured view data contains escaped store name');
$assert(count($viewData['items']) === 1, 'Structured view data contains prepared items');
$assert($viewData['items'][0]['name'] === 'Fresh Apple', 'Prepared item contains formatted name');
$assert($viewData['items'][0]['line_quantity_description'] === '2 × ₱12.50', 'Prepared item contains line quantity description');
$assert($viewData['totals']['total_quantity'] === 2, 'Totals include total quantity');
$assert($viewData['totals']['total_amount_formatted'] === '₱25.00', 'Totals include formatted total amount');

// Verify template rendering without throwing errors or needing closures
ob_start();
$view = $viewData;
require __DIR__ . '/../app/Views/sale_receipt.php';
$html = ob_get_clean();

$assert(str_contains($html, 'data-paper-width-mm="58"'), 'Template renders 58mm paper width attribute');
$assert(str_contains($html, 'Original Grocer'), 'Template renders store name from structured view data');
$assert(str_contains($html, 'Fresh Apple'), 'Template renders item name from structured view data');
$assert(str_contains($html, '₱25.00'), 'Template renders formatted totals');

// Refund and GCash public retrieval/preservation retain authorization and frozen facts.
$pdo->exec("CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, cashier_id INTEGER, sale_id INTEGER,
    shift_id INTEGER, refund_amount REAL, payment_method TEXT, payment_reference TEXT, reason TEXT, created_at TEXT);
    CREATE TABLE cash_refund_items (refund_item_id INTEGER PRIMARY KEY, refund_id INTEGER,
    sale_item_id INTEGER, product_id INTEGER, quantity INTEGER, subtotal REAL);
    CREATE TABLE refund_receipt_details (refund_id INTEGER PRIMARY KEY, details_json TEXT);
    INSERT INTO cash_refunds VALUES (201,1,501,2,12.50,'cash',NULL,'other','2026-10-15 15:00:00');
    INSERT INTO cash_refund_items VALUES (1,201,1,100,1,12.50);
    CREATE TABLE gcash_transactions (transaction_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER,
    verification_status TEXT, completed_at TEXT, reference_number TEXT, receipt_details_json TEXT,
    principal_amount REAL, gross_fee REAL, transaction_type TEXT, fee_method TEXT);
    INSERT INTO gcash_transactions VALUES
    (901,1,2,'Completed','2026-10-15 15:00:00','0000000000901',NULL,100,5,'Cash-In','cash'),
    (902,1,2,'Completed','2026-10-15 15:00:00','0000000000902',NULL,100,5,'Cash-Out','cash'),
    (903,1,2,'Completed','2026-10-15 15:00:00','0000000000903',NULL,100,5,'Cash-Out','gcash'),
    (904,1,2,'Pending',NULL,NULL,NULL,100,5,'Cash-Out','cash')");
$pdo->beginTransaction();
$engine->preserveRefund(201);
foreach ([901,902,903] as $transactionId) $engine->preserveGCash($transactionId);
$pdo->commit();
$refund = $engine->fetchRefundForCashier(201,1,1);
$cashIn = $engine->fetchGCash(901,1,'cashier');
$cashOut = $engine->fetchGCash(902,1,'cashier');
$walletFee = $engine->fetchGCash(903,1,'cashier');
$assert($refund['refund']['refund_amount'] == 12.5 && $refund['items'][0]['quantity'] === 1,
    'refund receipt preserves completed amount and item quantities');
$assert($engine->fetchRefundForCashier(201,999,1) === null && $engine->fetchRefundForCashier(201,1,2) === null,
    'refund retrieval enforces issuer ownership and Store scope');
$assert($cashIn['cash_collected'] === '105.00' && $cashIn['wallet_sent'] === '100.00',
    'Cash-In receipt preserves customer principal plus fee');
$assert($cashOut['cash_collected'] === '5.00' && $cashOut['cash_paid_out'] === '100.00'
    && $cashOut['wallet_received'] === '100.00', 'Cash-Out fee in cash remains a separate customer collection');
$assert($walletFee['cash_collected'] === '0.00' && $walletFee['wallet_received'] === '105.00',
    'Cash-Out fee through wallet preserves the exact transfer');
$assert($engine->fetchGCash(901,999,'cashier') === null && $engine->fetchGCash(901,1,'inventory_manager') === null
    && $engine->fetchGCash(901,0,'admin') === null && $engine->fetchGCash(904,1,'cashier') === null,
    'GCash receipt lookup requires authorized actor and completed saved evidence');
$assert($engine->fetchGCash(901,999,'admin') !== null, 'Administrator can inspect saved customer receipt');
$pdo->exec("UPDATE store_settings SET setting_value='Later Store' WHERE setting_key='store_name';
    UPDATE users SET display_name='Later Cashier' WHERE user_id=1;
    UPDATE registers SET name='Later Register',paper_width_mm='80' WHERE register_id=10;
    UPDATE products SET product_name='Later Product' WHERE product_id=100");
$assert($engine->fetchRefundForCashier(201,1,1) === $refund && $engine->fetchGCash(901,1,'cashier') === $cashIn,
    'later Store, catalog, Cashier and Register edits never rewrite refund or GCash facts');
$pdo->beginTransaction();
$engine->preserveGCash(901);
$pdo->commit();
$assert($engine->fetchGCash(901,1,'cashier') === $cashIn, 'repeated completion cannot rewrite a saved GCash receipt');
foreach ([58,80] as $width) {
    ob_start(); ReceiptEngine::renderRefund($refund,$width); $refundHtml = ob_get_clean();
    ob_start(); ReceiptEngine::renderGCash($cashIn,$width); $gcashHtml = ob_get_clean();
    $assert(str_contains($refundHtml,'data-paper-width-mm="'.$width.'"') && str_contains($refundHtml,'₱12.50'),
        'refund rendering uses shared thermal width and currency');
    $assert(str_contains($gcashHtml,'data-paper-width-mm="'.$width.'"') && str_contains($gcashHtml,'0000000000901')
        && str_contains($gcashHtml,'₱105.00'), 'GCash rendering preserves reference and customer amounts at either width');
}
echo "Receipt engine contract: passed\n";
