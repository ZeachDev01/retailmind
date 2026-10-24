<?php
// Shared saved receipt context for POS completion and Receipt History.
use App\Receipts\ReceiptDetailsService;
use App\Authorization\RoleCapabilityPolicy;

$sale_id = (int)($_GET['sale_id'] ?? $_GET['id'] ?? $_GET['receipt_id'] ?? 0);
$checkoutCompleted = ($_GET['checkout'] ?? '') === 'complete' && $sale_id > 0;
$completedAttempt = null;
if ($checkoutCompleted) {
    $attemptQuery = $pdo->prepare('SELECT attempt_id FROM checkout_attempts WHERE cashier_id = ? AND sale_id = ?');
    $attemptQuery->execute([(int)$_SESSION['user_id'], $sale_id]);
    $completedAttempt = $attemptQuery->fetchColumn() ?: null;
}
$checkoutCompleted = $checkoutCompleted && $completedAttempt !== null;
$announcePayment = $checkoutCompleted && (int)($_SESSION['completed_checkout_sale'] ?? 0) === $sale_id;
if ($announcePayment) unset($_SESSION['completed_checkout_sale']);
$autoPrintSale = $checkoutCompleted && (int)($_SESSION['auto_print_sale'] ?? 0) === $sale_id;
if ($autoPrintSale) unset($_SESSION['auto_print_sale']);
$storeId = store_scope_id($pdo);
$can_manage_all = has_capability(RoleCapabilityPolicy::VIEW_STORE_REPORTS);
$canStartSale = has_capability(RoleCapabilityPolicy::OPERATE_POINT_OF_SALE);

function receipt_store_info(): array {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    return $cached = (new ReceiptDetailsService(App\Core\Database::connection()))->storeInfo();
}

function receipt_money($value, ?array $store = null): string {
    $store = $store ?? receipt_store_info();
    return htmlspecialchars((string)$store['currency_symbol']) . number_format((float)$value, 2);
}

function receipt_verification_code(array $sale): string {
    return $sale['verification_code'] ?? ReceiptDetailsService::verificationCode($sale);
}

function receipt_fetch_sale(PDO $pdo, int $sale_id, int $storeId): ?array {
    return (new ReceiptDetailsService($pdo))->fetchSale($sale_id, $storeId);
}

function receipt_fetch_items(PDO $pdo, int $sale_id): array {
    return (new ReceiptDetailsService($pdo))->fetchItems($sale_id);
}

if (!function_exists('receipt_send_cache_headers')) {
    function receipt_send_cache_headers(array $sale): bool {
        if (headers_sent()) {
            return false;
        }

        $etag = '"sale-' . (int)$sale['sale_id'] . '-' . substr(md5(($sale['sale_date'] ?? '') . '-' . ($sale['verification_code'] ?? ($sale['total_amount'] ?? ''))), 0, 16) . '"';
        header('Cache-Control: private, max-age=86400, immutable');
        header('ETag: ' . $etag);
        if (!empty($sale['sale_date'])) {
            $timestamp = strtotime($sale['sale_date']);
            if ($timestamp > 0) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $timestamp));
            }
        }

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch !== '') {
            $candidates = array_map('trim', explode(',', $ifNoneMatch));
            foreach ($candidates as $candidate) {
                $candidateClean = trim($candidate, 'W/');
                if ($candidate === '*' || $candidate === $etag || $candidateClean === $etag) {
                    http_response_code(304);
                    return true;
                }
            }
        }

        if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && !empty($sale['sale_date'])) {
            $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($since !== false && $since >= strtotime($sale['sale_date'])) {
                http_response_code(304);
                return true;
            }
        }

        return false;
    }
}

function receipt_render_details(array $sale, array $items, bool $canStartSale, bool $announcePayment = false, bool $historyDrawer = false): void {
    $paperWidthMm = (new \App\Receipts\ReceiptPaperService(\App\Core\Database::connection()))
        ->currentWidth((int)($_SESSION['user_id'] ?? 0), (string)current_role());
    $store = $sale['receipt_store'] ?? receipt_store_info();
    ?>
    <div class="receipt-container">
        <?php if ($announcePayment): ?>
        <div class="checkout-complete-banner no-print" role="status">
            <div class="checkout-complete-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
            <div class="checkout-complete-copy"><strong>Payment completed</strong><span>Receipt #<?= (int)$sale['sale_id'] ?> was saved successfully.</span></div>
            <?php if ($sale['change_due'] !== null): ?><div class="checkout-complete-change"><span>Change due</span><strong><?= receipt_money($sale['change_due'], $store) ?></strong></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="receipt-actions no-print">
            <?php if ($historyDrawer && current_role() === 'cashier'): ?>
                <a href="<?= htmlspecialchars(app_url('components/cashier/refunds.php?sale_id=' . (int)$sale['sale_id'])) ?>" class="btn">Refund</a>
            <?php elseif (!$historyDrawer && $canStartSale): ?>
                <a href="<?= htmlspecialchars(app_url('components/cashier/pos.php?new_sale=1')) ?>" class="btn"><i class="bi bi-plus-lg"></i> New Sale</a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" onclick="printReceiptSection(this)"><i class="bi bi-printer"></i> Print Receipt</button>
        </div>
        <?php \App\Receipts\SaleReceiptPresentation::render($sale, $items, $store, $paperWidthMm); ?>
    </div>
    <?php
}

$selected_sale = null;
$selected_items = [];
$selected_error = '';
if ($sale_id > 0) {
    $selected_sale = receipt_fetch_sale($pdo, $sale_id, $storeId);
    if (!$selected_sale) {
        $selected_error = 'Receipt not found.';
    } elseif (!$can_manage_all && (int)$selected_sale['cashier_id'] !== (int)$_SESSION['user_id']) {
        $selected_error = 'Access denied.';
        $selected_sale = null;
    } else {
        $selected_items = receipt_fetch_items($pdo, $sale_id);
    }
}
