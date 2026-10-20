<?php
// cashier/pos.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../../../backend/app/Services/CashierShiftService.php';

use App\Services\CashierShiftService;

// Issue #86: the active Cashier workspace is the authoritative POS context.
// An Administrator who also holds the Cashier role must switch workspaces;
// the admin workspace can never bypass Cashier controls.
require_role(['cashier']);

$salesWorkflowService = new SalesWorkflowService($pdo);
$shiftService = new CashierShiftService($pdo);
$cashierId = (int)$_SESSION['user_id'];
require_capability(\App\Authorization\RoleCapabilityPolicy::OPERATE_POINT_OF_SALE);
// Ticket #89: checkout is attributed to the workspace that is active now, so
// the service judges the same context this page did.
$actorRole = (string)current_role();
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['recover_checkout']))
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_checkout')) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            if (($_POST['no_outstanding_payment'] ?? '') !== '1') {
                throw new DomainException('Confirm that no payment is outstanding before cancelling checkout.');
            }
            $saved = $salesWorkflowService->cancelAttempt((string)($_POST['checkout_attempt'] ?? ''), $cashierId, $actorRole);
        } else {
            $saved = $salesWorkflowService->recoverAttempt((string)$_GET['recover_checkout'], $cashierId, $actorRole);
        }
        $cancelled = !empty($saved['cancelled']);
        if ($cancelled) $saved = null;
        if ($saved !== null) $_SESSION['completed_checkout_sale'] = (int)$saved['sale_id'];
        echo json_encode(['cancelled' => $cancelled, 'sale' => $saved, 'receipt_url' => $saved === null ? null : app_url('components/cashier/pos.php?sale_id=' . $saved['sale_id'] . '&checkout=complete')], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(409);
        echo json_encode(['message' => \App\Support\OperatorAlert::message($e, 'Checkout recovery is unavailable. Retry recovery before collecting another payment.')]);
        error_log('Checkout recovery failed: ' . (string)$e);
    }
    exit;
}

$checkout_error = '';
$checkout_notice = '';
$lock_error = '';

// Ticket #90: locking and unlocking are a break, never an ending. Neither one
// touches the shift's status, opening float, or closing figures — only
// cashier_shifts.locked_at — so a Cashier who walks away mid-shift comes back to
// the same shift on the same Register. Logging out does the same, because
// logout destroys the session and nothing else.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['lock_register', 'unlock_register'], true)) {
    csrf_verify();
    try {
        if (($_POST['action'] ?? '') === 'lock_register') {
            $shiftService->lockRegister($cashierId, $actorRole);
        } else {
            $shiftService->unlockRegister($cashierId, $actorRole, (string)($_POST['pos_unlock_password'] ?? ''));
        }
        // Post/Redirect/Get: reloading a lock or unlock must not repeat it.
        header('Location: ' . app_url('components/cashier/pos.php'));
        exit;
    } catch (Throwable $e) {
        $lock_error = \App\Support\OperatorAlert::message($e, 'The register could not be locked or unlocked. Try again and tell your Administrator if it keeps happening.');
    }
}

// The open shift and its lock are resolved after the POST above, so a lock or
// unlock in this request is what this page reflects. Issue #86: only the Cashier
// workspace reaches this page, so the open shift gate always applies — including
// to Administrators selling as Cashiers.
$openShift = $shiftService->getOpenShift($cashierId);
$posShiftOpen = $openShift !== null;
$posRegisterLocked = $shiftService->isRegisterLocked($cashierId);

[$catalogScope, $catalogParams] = store_product_scope('p');
$catalogProducts = [];
$catalogEtag = null;
if ($posShiftOpen && !$posRegisterLocked) {
    try {
        $etagStmt = $pdo->prepare(
            "SELECT COUNT(p.product_id) AS total_count,
                    COALESCE(MAX(p.created_at), '') AS max_product_created,
                    COALESCE(MAX(i.last_updated), '') AS max_inventory_updated,
                    COALESCE(SUM(i.quantity_on_hand), 0) AS total_stock,
                    COALESCE(SUM(p.unit_price), 0) AS total_price
             FROM products p
             LEFT JOIN inventory i ON i.product_id = p.product_id
             WHERE p.status = 'active'{$catalogScope}"
        );
        $etagStmt->execute($catalogParams);
        $meta = $etagStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $rawHash = implode(':', [
            $meta['total_count'] ?? 0,
            $meta['max_product_created'] ?? '',
            $meta['max_inventory_updated'] ?? '',
            $meta['total_stock'] ?? 0,
            $meta['total_price'] ?? 0,
        ]);
        $catalogEtag = '"' . md5($rawHash) . '"';
    } catch (Throwable) {
        $catalogEtag = null;
    }
}

$loadCatalogProducts = static function () use ($pdo, $catalogScope, $catalogParams): array {
    $catalogStmt = $pdo->prepare(
        "SELECT p.product_id, p.sku, p.barcode, p.case_barcode, p.product_name, p.unit_price,
                COALESCE(p.reorder_level, 0) AS reorder_level,
                COALESCE(p.safety_stock, 0) AS safety_stock,
                COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
         FROM products p
         LEFT JOIN inventory i ON i.product_id = p.product_id
         WHERE p.status = 'active'{$catalogScope}
         ORDER BY p.product_id ASC"
    );
    $catalogStmt->execute($catalogParams);
    return array_map(static function (array $row): array {
        return [
            'product_id' => (int)$row['product_id'],
            'sku' => (string)($row['sku'] ?? ''),
            'barcode' => (string)($row['barcode'] ?? ''),
            'case_barcode' => (string)($row['case_barcode'] ?? ''),
            'product_name' => (string)$row['product_name'],
            'unit_price' => (float)$row['unit_price'],
            'reorder_level' => (int)$row['reorder_level'],
            'safety_stock' => (int)$row['safety_stock'],
            'quantity_on_hand' => (int)$row['quantity_on_hand'],
        ];
    }, $catalogStmt->fetchAll(PDO::FETCH_ASSOC));
};

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array(($_GET['action'] ?? ''), ['catalog_preload', 'catalog_sync'], true)) {
    if (!$posShiftOpen || $posRegisterLocked) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Shift closed or locked']);
        exit;
    }

    $clientEtag = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? $_GET['if_none_match'] ?? $_GET['etag'] ?? '');
    $clientEtag = trim($clientEtag, '"');
    $serverEtag = trim((string)$catalogEtag, '"');

    if ($clientEtag !== '' && $serverEtag !== '' && $clientEtag === $serverEtag) {
        http_response_code(304);
        header('ETag: ' . $catalogEtag);
        header('Cache-Control: no-cache');
        exit;
    }

    $since = trim((string)($_GET['since'] ?? ''));
    $deltaProducts = null;
    if ($since !== '') {
        try {
            $deltaStmt = $pdo->prepare(
                "SELECT p.product_id, p.sku, p.barcode, p.case_barcode, p.product_name, p.unit_price,
                        COALESCE(p.reorder_level, 0) AS reorder_level,
                        COALESCE(p.safety_stock, 0) AS safety_stock,
                        COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
                 FROM products p
                 LEFT JOIN inventory i ON i.product_id = p.product_id
                 WHERE p.status = 'active'{$catalogScope}
                   AND (i.last_updated > ? OR p.created_at > ?)
                 ORDER BY p.product_id ASC"
            );
            $deltaParams = array_merge($catalogParams, [$since, $since]);
            $deltaStmt->execute($deltaParams);
            $deltaProducts = array_map(static function (array $row): array {
                return [
                    'product_id' => (int)$row['product_id'],
                    'sku' => (string)($row['sku'] ?? ''),
                    'barcode' => (string)($row['barcode'] ?? ''),
                    'case_barcode' => (string)($row['case_barcode'] ?? ''),
                    'product_name' => (string)$row['product_name'],
                    'unit_price' => (float)$row['unit_price'],
                    'reorder_level' => (int)$row['reorder_level'],
                    'safety_stock' => (int)$row['safety_stock'],
                    'quantity_on_hand' => (int)$row['quantity_on_hand'],
                ];
            }, $deltaStmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            $deltaProducts = null;
        }
    }

    $isDelta = $deltaProducts !== null;
    $responseProducts = $isDelta ? $deltaProducts : $loadCatalogProducts();

    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    if ($catalogEtag !== null) {
        header('ETag: ' . $catalogEtag);
    }
    echo json_encode([
        'success' => true,
        'etag' => $catalogEtag,
        'server_time' => date('Y-m-d H:i:s'),
        'delta' => $isDelta,
        'catalog' => $responseProducts
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan_lookup') {
    csrf_verify();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        if (!$posShiftOpen || $posRegisterLocked) {
            throw new InvalidArgumentException('Open and unlock your Cashier Shift before scanning.');
        }
        $code = trim(is_string($_POST['code'] ?? null) ? $_POST['code'] : '');
        if ($code === '' || strlen($code) > 255 || preg_match('/[\x00-\x1F\x7F]/', $code)) {
            throw new InvalidArgumentException('Enter or scan a valid barcode.');
        }
        require_once __DIR__ . '/../../../backend/app/Services/ProductCodeLookupService.php';
        require_once __DIR__ . '/../../../backend/app/Services/PhilippineTime.php';
        $match = (new ProductCodeLookupService($pdo))->lookup($code);
        if ($match['outcome'] !== ProductCodeLookupService::OUTCOME_MATCH) {
            throw new InvalidArgumentException($match['outcome'] === ProductCodeLookupService::OUTCOME_AMBIGUOUS
                ? 'This barcode matches multiple products. Use product search.' : 'Product not found. Try product search.');
        }
        if ($match['matched_code_kind'] === ProductCodeLookupService::KIND_CASE_BARCODE) {
            throw new InvalidArgumentException('Case barcodes are for receiving only. Scan the piece or pack barcode.');
        }

        $isPackScan = ($match['matched_code_kind'] === ProductCodeLookupService::KIND_PACK_BARCODE);
        $sellingForm = $isPackScan ? 'pack' : 'piece';

        $todayManila = App\Services\PhilippineTime::today();
        $statement = $pdo->prepare(
            "SELECT p.product_id, p.sku, p.barcode, p.case_barcode, p.pack_barcode, p.pack_size, p.pack_price, p.product_name, p.unit_price,
                    p.reorder_level, p.safety_stock,
                    LEAST(COALESCE(i.quantity_on_hand, 0), COALESCE((
                        SELECT SUM(CASE WHEN expiration_date IS NULL OR expiration_date >= ?
                                        THEN remaining_quantity ELSE 0 END)
                        FROM product_batches WHERE product_id = p.product_id AND remaining_quantity > 0
                    ), i.quantity_on_hand, 0)) AS quantity_on_hand
             FROM products p LEFT JOIN inventory i ON i.product_id = p.product_id
             WHERE p.product_id = ? AND p.status = 'active'"
        );
        $statement->execute([$todayManila, $match['product']['product_id']]);
        $product = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new InvalidArgumentException('Product not found. Try product search.');
        }

        $packSize = isset($product['pack_size']) && $product['pack_size'] !== null ? (int)$product['pack_size'] : null;
        $packPrice = isset($product['pack_price']) && $product['pack_price'] !== null ? (float)$product['pack_price'] : null;
        $hasPackConfig = $packSize !== null && $packSize > 1 && $packPrice !== null && $packPrice > 0;

        if ($isPackScan && !$hasPackConfig) {
            throw new InvalidArgumentException('This product does not have an active Sales Pack configured.');
        }

        if ($isPackScan) {
            $batchPacksStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(pb.sealed_quantity), 0)
                 FROM product_batches pb
                 WHERE pb.product_id = ?
                   AND pb.remaining_quantity > 0
                   AND (pb.expiration_date IS NULL OR pb.expiration_date >= ?)"
            );
            $batchPacksStmt->execute([$product['product_id'], $todayManila]);
            $availableStock = (int)$batchPacksStmt->fetchColumn();
            $formPrice = $packPrice;
        } else {
            $availableStock = (int)$product['quantity_on_hand'];
            $formPrice = (float)$product['unit_price'];
        }

        $quantities = json_decode((string)($_POST['quantities'] ?? '{}'), true);
        $cartKey = $product['product_id'] . ':' . $sellingForm;
        $quantity = 0;
        $existingPacks = 0;
        $existingPieces = 0;
        if (is_array($quantities)) {
            $existingPacks = (int)($quantities[$product['product_id'] . ':pack'] ?? 0);
            $existingPieces = (int)($quantities[$product['product_id'] . ':piece'] ?? ($quantities[$product['product_id']] ?? 0));
            if (isset($quantities[$cartKey])) {
                $quantity = (int)$quantities[$cartKey];
            } elseif (isset($quantities[$product['product_id']]) && !$isPackScan) {
                $quantity = (int)$quantities[$product['product_id']];
            }
        }
        if ($quantity < 0 || $existingPacks < 0 || $existingPieces < 0) {
            throw new InvalidArgumentException('Invalid cart quantity. Review the cart before scanning.');
        }

        $effectivePackSize = ($hasPackConfig && $packSize > 1) ? $packSize : 1;
        $totalDemandedBaseUnits = (($existingPacks + ($isPackScan ? 1 : 0)) * $effectivePackSize) + $existingPieces + ($isPackScan ? 0 : 1);
        $onHandBaseUnits = (int)$product['quantity_on_hand'];

        if ($isPackScan && ($availableStock < 1 || ($existingPacks + 1) > $availableStock)) {
            throw new InvalidArgumentException('Insufficient sealed pack stock. Only ' . $availableStock . ' available.');
        }
        if ($totalDemandedBaseUnits > $onHandBaseUnits) {
            throw new InvalidArgumentException($isPackScan
                ? 'Insufficient stock to add pack. Only ' . $onHandBaseUnits . ' base units available.'
                : 'Insufficient stock. Only ' . $onHandBaseUnits . ' available.');
        }

        $product['selling_form'] = $sellingForm;
        $product['price'] = $formPrice;
        $product['pack_size'] = $packSize;
        $product['available_stock'] = $availableStock;

        echo json_encode(['success' => true, 'product' => $product], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $e) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log((string)$e);
        echo json_encode(['success' => false, 'message' => 'Product lookup failed. Please try again.']);
    }
    exit;
}

// Ticket #90: the lock screen replaces the point of sale, so nothing below may
// act on a request that arrives while the Register is locked. Checkout already
// refuses at the service seam; voiding a cart also writes a Protected Audit
// Record, so it must be refused here or a locked till could be made to author
// one on somebody else's behalf.
if ($posRegisterLocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // The one message a locked Register produces, in the page's own refusal
    // channel — the same channel the void and checkout refusals below use.
    $checkout_error = CashierShiftService::LOCKED_MESSAGE;
    if (($_POST['action'] ?? '') === 'review_quote') {
        csrf_verify();
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        http_response_code(409);
        echo json_encode(['message' => $checkout_error], JSON_THROW_ON_ERROR);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_cart') {
    csrf_verify();

    $reason = trim($_POST['void_reason'] ?? '');
    if ($reason === '') {
        $checkout_error = 'A reason is required to void the current sale.';
    } else {
        try {
            App\Store\StoreWriteGate::begin($pdo);
            $shiftService->lockOpenShift($cashierId, true);
            log_activity($pdo, $cashierId, 'Voided sale before final checkout: ' . $reason);
            $pdo->commit();
            $checkout_notice = 'Sale voided before checkout and audit log was recorded.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $checkout_error = \App\Support\OperatorAlert::message($e, 'The current sale could not be voided.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['cart'])
    && ($_POST['action'] ?? '') !== 'void_cart'
    && !$posRegisterLocked) {
    csrf_verify();

    $cart = json_decode($_POST['cart'], true) ?: [];
    $payment_method = in_array($_POST['payment_method'] ?? '', ['cash', 'card', 'ewallet'], true)
        ? $_POST['payment_method'] : 'cash';
    $attemptToken = (string)($_POST['checkout_attempt_id'] ?? $_POST['checkout_attempt'] ?? '');
    $paymentDetails = [
        'checkout_attempt' => $attemptToken,
        'checkout_attempt_id' => $attemptToken,
        'cash_received' => $_POST['cash_received'] ?? 0,
        'payment_reference' => $_POST['payment_reference'] ?? '',
        'payment_method' => $payment_method,
        'payment_verified' => ($_POST['payment_verified'] ?? '') === '1',
        'discount_type' => $_POST['discount_type'] ?? 'none',
        'discount_value' => $_POST['discount_value'] ?? 0,
        'discount_reason' => $_POST['discount_reason'] ?? '',
        'discount_approver_username' => $_POST['discount_approver_username'] ?? '',
        'discount_approver_password' => $_POST['discount_approver_password'] ?? '',
        'reviewed_quote' => $_SESSION['checkout_quotes'][(string)($_POST['quote_token'] ?? '')]['state_hash'] ?? '',
        // Ticket #91: the cart the Cashier resumed, if any. The service settles it
        // against this Cashier and against the shift it already resolved for the
        // sale, so a value tampered with here completes nothing.
        'held_sale_id' => (int)($_POST['held_sale_id'] ?? 0),
    ];

    try {
        if (($_POST['action'] ?? '') === 'review_quote') {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            $quote = $salesWorkflowService->reviewQuote($cart, $cashierId, $actorRole, $paymentDetails);
            $token = bin2hex(random_bytes(24));
            $_SESSION['checkout_quotes'] = array_slice($_SESSION['checkout_quotes'] ?? [], -19, null, true);
            $_SESSION['checkout_quotes'][$token] = ['state_hash' => $quote['state_hash']];
            unset($quote['state_hash'], $quote['discount']['approval_state']);
            echo json_encode(['quote' => $quote, 'token' => $token], JSON_THROW_ON_ERROR);
            exit;
        }
        // Ticket #89: the active workspace is passed explicitly so the service,
        // not the account's stored role, decides who may sell.
        $result = $salesWorkflowService->checkout($cart, $cashierId, $actorRole, $payment_method, $paymentDetails);
        $_SESSION['completed_checkout_sale'] = (int)$result['sale_id'];
        $receiptUrl = app_url('components/cashier/pos.php?sale_id=' . $result['sale_id'] . '&checkout=complete');

        $isJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($isJson) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode(['success' => true, 'sale_id' => $result['sale_id'], 'receipt_url' => $receiptUrl], JSON_THROW_ON_ERROR);
            exit;
        }

        header('Location: ' . $receiptUrl);
        exit;
    } catch (StockConflictException $e) {
        http_response_code(409);
        $conflicts = $e->getConflicts();
        $isJson = (($_POST['action'] ?? '') === 'review_quote')
            || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($isJson) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode([
                'success' => false,
                'error' => 'stock_conflict',
                'message' => $e->getMessage(),
                'conflicts' => $conflicts,
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        $checkout_error = $e->getMessage();
    } catch (Throwable $e) {
        $checkout_error = \App\Support\OperatorAlert::message($e, 'The sale could not finish. Please try again. Tell your Administrator if this keeps happening.');
        $isJson = (($_POST['action'] ?? '') === 'review_quote')
            || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($isJson) {
            http_response_code(409);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode(['success' => false, 'message' => $checkout_error], JSON_THROW_ON_ERROR);
            exit;
        }
    }
}

require __DIR__ . '/../../../backend/app/Views/sale_receipt_support.php';

// Ticket #91: the fixed set of reasons a held sale can be discarded for. The
// dialog offers exactly these, and the service accepts exactly these, so the
// page cannot drift into offering a reason the audit record would not
// understand. Read straight off the service the way this page already reads
// CashierShiftService::LOCKED_MESSAGE — no second instance is built to read it.
$heldSaleDiscardReasons = \App\Services\HeldSaleService::DISCARD_REASONS;

require_once __DIR__ . '/../../../backend/app/Services/PhilippineTime.php';
$todayManila = \App\Services\PhilippineTime::today();
[$quickProductScope, $quickProductParams] = store_product_scope('p');
$quickProductStmt = $pdo->prepare(
    "SELECT p.product_id, p.sku, p.barcode, p.pack_barcode, p.pack_size, p.pack_price, p.product_name, p.variant_label, p.unit_price,
            p.product_image, p.category_id, c.category_name,
            COALESCE(p.reorder_level, 0) AS reorder_level,
            COALESCE(p.safety_stock, 0) AS safety_stock,
            COALESCE(p.quantity_sold, 0) AS quantity_sold,
            COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
            COALESCE(batch_packs.sealed_packs, 0) AS sealed_packs
     FROM products p
     LEFT JOIN inventory i ON i.product_id = p.product_id
     LEFT JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN (
         SELECT product_id, SUM(sealed_quantity) AS sealed_packs
         FROM product_batches
         WHERE remaining_quantity > 0
           AND (expiration_date IS NULL OR expiration_date >= ?)
         GROUP BY product_id
     ) batch_packs ON batch_packs.product_id = p.product_id
     WHERE p.status = 'active'{$quickProductScope}
     ORDER BY COALESCE(p.quantity_sold, 0) DESC, p.product_name
     LIMIT 120"
);
$quickProductStmt->execute(array_merge([$todayManila], $quickProductParams));
$quickProducts = array_map(static function (array $product): array {
    $image = trim((string)($product['product_image'] ?? ''));
    if ($image !== '' && !preg_match('#^(?:https?://|data:image/|/)#i', $image)) {
        $image = strpos($image, 'storage/images/') === 0
            ? rtrim(dirname(app_base_url()), '/') . '/backend/' . $image
            : app_url($image);
    }

    return [
        'product_id' => (int)$product['product_id'],
        'name' => (string)$product['product_name'] . (!empty($product['variant_label']) ? ' - ' . $product['variant_label'] : ''),
        'sku' => (string)($product['sku'] ?? ''),
        'barcode' => (string)($product['barcode'] ?? ''),
        'pack_barcode' => (string)($product['pack_barcode'] ?? ''),
        'pack_size' => isset($product['pack_size']) && $product['pack_size'] !== null ? (int)$product['pack_size'] : null,
        'pack_price' => isset($product['pack_price']) && $product['pack_price'] !== null ? (float)$product['pack_price'] : null,
        'price' => (float)$product['unit_price'],
        'quantity_on_hand' => (int)$product['quantity_on_hand'],
        'sealed_packs' => (int)($product['sealed_packs'] ?? 0),
        'reorder_level' => (int)$product['reorder_level'],
        'safety_stock' => (int)$product['safety_stock'],
        'quantity_sold' => (int)$product['quantity_sold'],
        'category_id' => (int)($product['category_id'] ?? 0),
        'category_name' => (string)($product['category_name'] ?: 'Uncategorized'),
        'product_image' => $image,
    ];
}, $quickProductStmt->fetchAll(PDO::FETCH_ASSOC));

$quickCategories = [];
foreach ($quickProducts as $product) {
    $categoryId = $product['category_id'];
    if (!isset($quickCategories[$categoryId])) {
        $quickCategories[$categoryId] = ['name' => $product['category_name'], 'count' => 0];
    }
    $quickCategories[$categoryId]['count']++;
}
uasort($quickCategories, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
$quickCategoryIcon = static function (string $categoryName): string {
    $name = strtolower($categoryName);
    return match (true) {
        str_contains($name, 'beverage'), str_contains($name, 'drink') => 'bi-cup-straw',
        str_contains($name, 'snack'), str_contains($name, 'biscuit') => 'bi-cookie',
        str_contains($name, 'personal'), str_contains($name, 'health') => 'bi-heart-pulse',
        str_contains($name, 'school'), str_contains($name, 'office') => 'bi-pencil',
        str_contains($name, 'electronic') => 'bi-battery-charging',
        str_contains($name, 'baking'), str_contains($name, 'grocery') => 'bi-basket2',
        str_contains($name, 'canned'), str_contains($name, 'condiment') => 'bi-box2-heart',
        default => 'bi-bag',
    };
};
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/invoices.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/invoices.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier.css')) ?>">
    <?php require __DIR__ . '/../../../backend/app/Views/sale_receipt_checkout_cleanup.php'; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/sale-receipt.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt-dialog.js')) ?>" defer></script>
</head>
<body class="cashier-page cashier-pos-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <h1 class="sr-only">Point of Sale</h1>
        <?php foreach ([[$checkout_error, 'error'], [$lock_error, 'error'], [$checkout_notice, 'success']] as [$notice, $noticeType]): ?>
            <?php if ($notice !== ''): ?>
                <script>RetailMindUI.toast(<?= json_encode($notice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($noticeType) ?>, undefined, 4300);</script>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($posRegisterLocked): ?>
            <?php // Ticket #90: a locked Register shows a lock screen instead of the point of sale. The sidebar stays, so the Cashier can still log out — logging out leaves the shift open. ?>
            <section class="pos-lock" aria-labelledby="pos-lock-title">
                <div class="pos-lock-card">
                    <div class="pos-lock-icon"><i class="bi bi-lock-fill" aria-hidden="true"></i></div>
                    <h2 id="pos-lock-title">Register locked</h2>
                    <p class="pos-lock-lede">
                        <strong><?= htmlspecialchars((string)($openShift['register_name'] ?? 'Unassigned')) ?></strong>
                        &middot; Shift #<?= (int)$openShift['shift_id'] ?>
                        &middot; locked <?= htmlspecialchars(format_display_datetime((string)$openShift['locked_at'])) ?>
                    </p>
                    <form method="post" class="pos-lock-form" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unlock_register">
                        <label class="pos-field-label" for="pos-unlock-password">Your account password</label>
                        <input class="pos-input" type="password" id="pos-unlock-password" name="pos_unlock_password" autocomplete="current-password" required autofocus>
                        <p class="pos-lock-note">Your Cashier Shift is still open on this Register. No sale can be recorded until you unlock it.</p>
                        <button class="btn btn-block" type="submit"><i class="bi bi-unlock" aria-hidden="true"></i>Unlock and resume</button>
                    </form>
                    <p class="pos-lock-note">Logging out keeps the shift open too, so you can unlock and resume this same shift whenever you are back.</p>
                </div>
            </section>
        <?php else: ?>
        <?php if ($posShiftOpen): ?>
            <script>RetailMindUI.toast(<?= json_encode('Register: ' . ($openShift['register_name'] ?? 'Unassigned') . ' · Shift #' . (int)$openShift['shift_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'info', 'Register ready', 3000);</script>
        <?php endif; ?>
        <?php if (!$posShiftOpen): ?>
            <div class="pos-alert error" role="note" data-rm-alert-processed="1"><i class="bi bi-clock-history" aria-hidden="true"></i>Open a Cashier Shift before checkout. <a href="<?= htmlspecialchars(app_url('components/cashier/shifts.php')) ?>">Open shift</a></div>
        <?php endif; ?>

        <div class="pos-grid">
            <section class="pos-workspace" aria-label="Product entry">
                <div class="pos-panel">
                    <div class="pos-panel-header">
                        <div class="pos-panel-title">
                            <div class="pos-panel-icon"><i class="bi bi-upc-scan" aria-hidden="true"></i></div>
                            <div>
                                <h2>Find an item</h2>
                            </div>
                        </div>
                        <div class="pos-toolbar">
                            <button type="button" class="btn btn-small btn-secondary" id="start-scanner">
                                <i class="bi bi-camera" aria-hidden="true"></i>Use camera
                            </button>
                            <button type="button" class="btn btn-small btn-danger" id="stop-scanner" disabled>
                                <i class="bi bi-stop-circle" aria-hidden="true"></i>Stop
                            </button>
                            <?php if ($posShiftOpen): ?>
                                <form method="post" data-confirm="Lock this register for a break? Your Cashier Shift stays open and your Register stays yours. Nobody else can use it until you unlock it with your password." data-confirm-title="Lock register" data-confirm-button="Lock register">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="lock_register">
                                    <button class="btn btn-small btn-secondary" type="submit"><i class="bi bi-lock" aria-hidden="true"></i>Lock register</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="quick-grid-toggle" id="quick-grid-toggle" title="Toggle compact product grid" aria-label="Toggle compact product grid" aria-pressed="false">
                                <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-small btn-secondary" id="refresh-catalog" title="Refresh catalog">
                                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>Refresh
                            </button>
                        </div>
                    </div>

                    <label class="scan-label" for="sku-input">Scan or search products</label>
                    <div class="scan-row">
                        <input id="sku-input" class="pos-input" type="text" placeholder="Scan barcode or search by name or SKU" autocomplete="off" autofocus inputmode="search">
                        <button type="button" class="btn" id="add-code-btn" title="Add item (Enter)">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>Add item
                        </button>
                    </div>

                    <div class="scanner-area" id="scanner-area">
                        <div id="scanner-reader" class="scanner-reader"></div>
                        <div id="scanner-result" class="scanner-status" aria-live="polite">
                            <strong>Camera scanner</strong>
                            <span>Start the camera, then place a barcode inside the frame.</span>
                        </div>
                    </div>
                </div>

                <section class="quick-add-section" aria-label="Products">

                    <div class="quick-category-filters" id="quick-category-filters" aria-label="Product categories">
                        <button type="button" class="quick-category-filter active" aria-pressed="true" data-quick-category="all">
                            <span class="quick-category-icon"><i class="bi bi-grid" aria-hidden="true"></i></span>
                            <strong>All</strong>
                            <small><?= count($quickProducts) ?> items</small>
                        </button>
                        <?php foreach ($quickCategories as $categoryId => $category): ?>
                            <button type="button" class="quick-category-filter" aria-pressed="false" data-quick-category="<?= (int)$categoryId ?>">
                                <span class="quick-category-icon"><i class="bi <?= htmlspecialchars($quickCategoryIcon($category['name'])) ?>" aria-hidden="true"></i></span>
                                <strong><?= htmlspecialchars($category['name']) ?></strong>
                                <small><?= (int)$category['count'] ?> item<?= (int)$category['count'] === 1 ? '' : 's' ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div id="quick-product-grid" class="quick-product-grid" role="region" aria-label="Quick add products" tabindex="0" aria-live="polite"></div>
                </section>

            </section>

            <aside class="pos-panel checkout-panel" aria-label="Current sale">
                <div class="pos-panel-header">
                    <div>
                        <div class="cart-heading">
                            <h2>Current sale</h2>
                            <span class="count-badge" id="cart-line-count">0</span>
                        </div>
                        <p class="muted u-pos-hint">Review quantities before accepting payment.</p>
                    </div>
                    <button type="button" class="btn btn-small btn-secondary" id="clear-cart-btn" disabled>
                        <i class="bi bi-trash3" aria-hidden="true"></i>Clear
                    </button>
                </div>

                <div id="checkout-recovery" class="pos-alert" data-rm-alert-processed="1" hidden role="status">
                    <div>
                        <p>No saved receipt yet. Retry the same checkout without collecting payment again, or cancel it after settling any payment with the customer.</p>
                        <button type="button" class="btn btn-small btn-secondary" id="cancel-checkout-btn">Cancel unsuccessful checkout</button>
                    </div>
                </div>

                <div class="cart-table-wrap">
                    <table class="cart-table" aria-label="Cart items">
                        <thead>
                            <tr><th>Item</th><th>Quantity</th><th>Subtotal</th><th><span class="sr-only">Remove</span></th></tr>
                        </thead>
                        <tbody id="cart-body"></tbody>
                    </table>
                </div>

                <div class="checkout-total">
                    <div class="checkout-total-label">
                        <strong>Total due</strong>
                        <span><span id="cart-item-count">0</span> item(s)</span>
                    </div>
                    <div class="checkout-total-value">&#8369;<span id="cart-total">0.00</span></div>
                </div>

                <div class="payment-breakdown" aria-label="Payment summary">
                    <div><span>Subtotal</span><strong>&#8369;<span id="summary-subtotal">0.00</span></strong></div>
                    <div><span>Discount</span><strong>-&#8369;<span id="summary-discount">0.00</span></strong></div>
                    <div><span>Change</span><strong>&#8369;<span id="summary-change">0.00</span></strong></div>
                </div>

                <form method="POST" id="checkout-form" class="payment-section">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="checkout">
                    <input type="hidden" name="checkout_attempt" id="checkout-attempt-input">
                    <input type="hidden" name="checkout_attempt_id" id="checkout-attempt-id-input">
                    <input type="hidden" name="quote_token" id="quote-token-input">
                    <input type="hidden" name="payment_verified" value="0">
                    <input type="hidden" name="cart" id="cart-input">
                    <input type="hidden" name="held_sale_id" id="held-sale-id-input" value="">
                    <div class="payment-breakdown" aria-label="Drawer preview">
                        <div><span>Cash added</span><strong>&#8369;<span id="drawer-cash-added">0.00</span></strong></div>
                    </div>
                    <p class="pos-lock-note">Preview only. Cash received minus change is added when payment is confirmed.</p>

                    <div class="payment-field">
                        <label class="pos-field-label" for="payment-method">Payment method</label>
                        <select name="payment_method" id="payment-method" class="pos-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="ewallet">E-Wallet</option>
                        </select>
                    </div>

                    <div class="payment-grid">
                        <div class="payment-field" id="cash-field">
                            <label class="pos-field-label" for="cash-received">Cash received</label>
                            <input type="text" name="cash_received" id="cash-received" placeholder="Enter cash received" inputmode="decimal" autocomplete="off">
                            <div class="cash-quick" id="cash-quick" role="group" aria-label="Quick cash amounts">
                                <button type="button" data-tender="exact">Exact</button>
                                <button type="button" data-tender="20">₱20</button>
                                <button type="button" data-tender="50">₱50</button>
                                <button type="button" data-tender="100">₱100</button>
                            </div>
                        </div>
                        <div class="payment-field" id="change-field">
                            <label class="pos-field-label" for="change-due">Change due</label>
                            <input type="text" id="change-due" value="0.00" readonly aria-readonly="true">
                        </div>
                    </div>

                    <p class="muted">Review the final quote to confirm payment and change.</p>

                    <div class="payment-field hidden" id="reference-field">
                        <label class="pos-field-label" for="payment-reference">Payment reference</label>
                        <input type="text" name="payment_reference" id="payment-reference" placeholder="Enter after quote review" readonly>
                    </div>

                    <details class="payment-field" id="discount-panel">
                        <summary class="pos-field-label">Discount or promotion</summary><p class="muted u-mt-05">Review the final server quote before collecting payment. Only the best eligible discount applies.</p>
                        <div class="payment-grid u-mt-075">
                            <div><label class="pos-field-label" for="discount-type">Discount type</label><select name="discount_type" id="discount-type" class="pos-select"><option value="none">No discount</option><option value="percentage">Percentage</option><option value="fixed">Fixed amount</option></select></div>
                            <div><label class="pos-field-label" for="discount-value">Value</label><input type="number" min="0" step="0.01" name="discount_value" id="discount-value" value="0"></div>
                        </div>
                        <div class="payment-field"><label class="pos-field-label" for="discount-reason">Reason</label><input type="text" name="discount_reason" id="discount-reason" placeholder="Promotion, customer eligibility, or approved adjustment"></div>
                        <div class="payment-grid hidden" id="supervisor-fields">
                            <div><label class="pos-field-label" for="discount-approver-username">Administrator username</label><input type="text" name="discount_approver_username" id="discount-approver-username" autocomplete="off"></div>
                            <div><label class="pos-field-label" for="discount-approver-password">Administrator password</label><input type="password" name="discount_approver_password" id="discount-approver-password" autocomplete="new-password"></div>
                        </div>
                        <small id="discount-summary" class="muted">No discount applied.</small>
                    </details>

                    <button class="btn btn-block checkout-primary" id="checkout-button" type="button" title="Checkout (Ctrl+Enter)" disabled>
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>Review checkout
                    </button>
                </form>

                <div class="secondary-sale-actions">
                    <button class="btn btn-secondary" id="hold-sale-btn" type="button" title="Hold sale (F4)" disabled>
                        <i class="bi bi-pause-circle" aria-hidden="true"></i>Hold sale
                    </button>
                    <button class="btn btn-danger" id="void-sale-btn" type="button" onclick="voidCurrentSale()" disabled>
                        <i class="bi bi-x-circle" aria-hidden="true"></i>Void sale
                    </button>
                </div>

                <div class="held-sales">
                    <div class="held-sales-header">
                        <h3>Held sales</h3>
                        <span class="count-badge" id="held-count">0</span>
                    </div>
                    <div id="hold-list" class="hold-list"></div>
                </div>

                <form method="POST" id="void-form" hidden>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="void_cart">
                    <input type="hidden" name="void_reason" id="void-reason-input">
                </form>
            </aside>
        </div>
    </main>
</div>

<div id="checkout-modal" class="checkout-modal" role="dialog" aria-modal="true" aria-labelledby="checkout-title">
    <div class="checkout-dialog">
        <div class="checkout-dialog-header">
            <div>
                <h3 id="checkout-title">Review sale before payment</h3>
                <p>Verify the payment details before completing the sale.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeCheckoutConfirm()" aria-label="Close checkout confirmation"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="checkout-dialog-body">
            <div id="checkout-summary" class="checkout-summary-card"></div>
        </div>
        <div class="checkout-dialog-actions">
            <button type="button" class="btn btn-secondary" onclick="closeCheckoutConfirm()">Review cart</button>
            <button type="button" class="btn" id="confirm-checkout-button"><i class="bi bi-check2" aria-hidden="true"></i>Confirm payment</button>
        </div>
    </div>
</div>

<div id="stock-conflict-modal" class="checkout-modal" role="dialog" aria-modal="true" aria-labelledby="stock-conflict-title">
    <div class="checkout-dialog">
        <div class="checkout-dialog-header">
            <div>
                <h3 id="stock-conflict-title">Stock conflict detected</h3>
                <p>Live inventory changed. Adjust affected quantities or remove depleted items to continue.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeStockConflictModal()" aria-label="Close stock conflict dialog"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="checkout-dialog-body">
            <div id="stock-conflict-content"></div>
        </div>
        <div class="checkout-dialog-actions">
            <button type="button" class="btn btn-secondary" onclick="closeStockConflictModal()">Keep reviewing</button>
            <button type="button" class="btn" id="confirm-conflict-adjustments-btn" onclick="applyStockConflictAdjustments()"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Proceed with updated cart</button>
        </div>
    </div>
</div>

<div id="void-modal" class="checkout-modal" role="dialog" aria-modal="true" aria-labelledby="void-title">
    <div class="checkout-dialog">
        <div class="checkout-dialog-header">
            <div>
                <h3 id="void-title">Void current sale</h3>
                <p>The reason will be saved in the audit log.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeVoidModal()" aria-label="Close void dialog"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="checkout-dialog-body">
            <label class="pos-field-label" for="void-reason">Reason for voiding</label>
            <textarea class="void-reason" id="void-reason" placeholder="Example: Customer cancelled the purchase"></textarea>
        </div>
        <div class="checkout-dialog-actions">
            <button type="button" class="btn btn-secondary" onclick="closeVoidModal()">Keep sale</button>
            <button type="button" class="btn btn-danger" onclick="confirmVoidSale()"><i class="bi bi-trash3" aria-hidden="true"></i>Void sale</button>
        </div>
    </div>
</div>

<div id="discard-modal" class="checkout-modal" role="dialog" aria-modal="true" aria-labelledby="discard-title">
    <div class="checkout-dialog">
        <div class="checkout-dialog-header">
            <div>
                <h3 id="discard-title">Discard unfinished sale</h3>
                <p>The reason is recorded in the audit log. Discarding moves no cash.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeDiscardModal()" aria-label="Close discard dialog"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="checkout-dialog-body">
            <label class="pos-field-label" for="discard-reason">Reason for discarding</label>
            <select id="discard-reason" class="pos-select">
                <?php foreach ($heldSaleDiscardReasons as $discardReasonKey => $discardReasonLabel): ?>
                    <option value="<?= htmlspecialchars($discardReasonKey) ?>"><?= htmlspecialchars($discardReasonLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="pos-field-label" for="discard-note">Note (required when the reason is Other)</label>
            <textarea class="void-reason" id="discard-note" placeholder="Example: Customer collected their items another way"></textarea>
        </div>
        <div class="checkout-dialog-actions">
            <button type="button" class="btn btn-secondary" onclick="closeDiscardModal()">Keep working</button>
            <button type="button" class="btn btn-danger" id="confirm-discard-sale"><i class="bi bi-trash3" aria-hidden="true"></i>Discard sale</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../../backend/app/Views/sale_receipt_dialog.php'; ?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= app_url('assets/js/checkout-attempt.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/checkout-attempt.js') ?>"></script>
<script src="<?= app_url('assets/js/checkout-quote.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/checkout-quote.js') ?>"></script>
<script>
setTimeout(() => document.querySelector('.pos-register-bar')?.remove(), 8000);

let cart = {};
let heldSales = [];
// Ticket #91: the held sale this cart came from, if any. Checkout sends it so
// the sale settles that held sale on the shift it was held under; the service
// settles it against the Cashier and the shift, so this is a hint, not a claim.
let resumedHeldSaleId = 0;
let cartReviewUnresolved = false;
let scanCooldown = false;
let scannerActive = false;
let checkoutConfirmed = false;
let checkoutSubmitting = false;
let lastScannedCode = '';
let lastScannedAt = 0;
let posAudioContext = null;

const quickProducts = <?= json_encode($quickProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const quickProductById = new Map(quickProducts.map(product => [Number(product.product_id), product]));
const posCatalogPreload = <?= json_encode(($posShiftOpen && !$posRegisterLocked) ? $loadCatalogProducts() : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const posCatalogEtag = <?= json_encode($catalogEtag) ?>;
const posCatalogServerTime = <?= json_encode(date('Y-m-d H:i:s')) ?>;
const skuInput = document.getElementById('sku-input');
const scannerResult = document.getElementById('scanner-result');
const scannerArea = document.getElementById('scanner-area');
const recentScan = document.getElementById('recent-scan');
const startBtn = document.getElementById('start-scanner');
const stopBtn = document.getElementById('stop-scanner');
const addCodeBtn = document.getElementById('add-code-btn');
const checkoutForm = document.getElementById('checkout-form');
const cartInput = document.getElementById('cart-input');
const resumedHeldSaleInput = document.getElementById('held-sale-id-input');
const paymentMethod = document.getElementById('payment-method');
const cashReceived = document.getElementById('cash-received');
const changeDue = document.getElementById('change-due');
const referenceField = document.getElementById('reference-field');
const paymentReference = document.getElementById('payment-reference');
const holdList = document.getElementById('hold-list');
const checkoutModal = document.getElementById('checkout-modal');
const checkoutSummary = document.getElementById('checkout-summary');
const voidModal = document.getElementById('void-modal');
const voidReason = document.getElementById('void-reason');
const discardModal = document.getElementById('discard-modal');
const discardReason = document.getElementById('discard-reason');
const discardNote = document.getElementById('discard-note');
const stockConflictModal = document.getElementById('stock-conflict-modal');
let discardingHeldSaleId = 0;
const checkoutButton = document.getElementById('checkout-button');
const confirmCheckoutButton = document.getElementById('confirm-checkout-button');
const holdSaleButton = document.getElementById('hold-sale-btn');
const voidSaleButton = document.getElementById('void-sale-btn');
const clearCartButton = document.getElementById('clear-cart-btn');
const cashQuick = document.getElementById('cash-quick');
const discountType = document.getElementById('discount-type');
const discountValue = document.getElementById('discount-value');
const discountReason = document.getElementById('discount-reason');
const discountSummary = document.getElementById('discount-summary');
const supervisorFields = document.getElementById('supervisor-fields');
const fullscreenToggle = document.getElementById('pos-fullscreen-toggle');
const cashierClock = document.getElementById('cashier-clock');
const quickCategoryFilters = document.getElementById('quick-category-filters');
const quickProductGrid = document.getElementById('quick-product-grid');
const quickGridToggle = document.getElementById('quick-grid-toggle');
const findProductUrl = <?= json_encode(app_url('components/cashier/findProduct.php')) ?>;
const barcodeApiUrl = <?= json_encode(app_url('components/cashier/pos.php')) ?>;
const heldSalesApiUrl = <?= json_encode(app_url('components/barcodeScanner/apiScanner/held_sales.php')) ?>;
const csrfToken = <?= json_encode(generate_csrf_token()) ?>;
const posShiftOpen = <?= $posShiftOpen ? 'true' : 'false' ?>;
const lowStockFallback = 5;
function getSupportedBarcodeFormats() {
    const formats = window.Html5QrcodeSupportedFormats;
    if (!formats) return undefined;
    return [
        formats.QR_CODE,
        formats.CODE_128,
        formats.CODE_39,
        formats.CODE_93,
        formats.EAN_13,
        formats.EAN_8,
        formats.UPC_A,
        formats.UPC_E,
        formats.ITF,
        formats.CODABAR,
    ].filter(format => typeof format === 'number');
}

const scannerConfig = {
    fps: 10,
    qrbox: { width: 280, height: 180 },
    aspectRatio: 1.4,
    disableFlip: true,
    formatsToSupport: getSupportedBarcodeFormats(),
    useBarCodeDetectorIfSupported: true,
    experimentalFeatures: { useBarCodeDetectorIfSupported: true },
};
const html5QrCode = window.Html5Qrcode ? new Html5Qrcode('scanner-reader', {
    formatsToSupport: getSupportedBarcodeFormats(),
    useBarCodeDetectorIfSupported: true,
}) : null;

function money(value) {
    return Number(value || 0).toFixed(2);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function setTextIfPresent(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function quickProductMedia(product) {
    if (product.product_image) {
        return `<img src="${escapeHtml(product.product_image)}" alt="" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="bi ${quickProductIcon(product)}" aria-hidden="true" hidden></i>`;
    }
    return `<i class="bi ${quickProductIcon(product)}" aria-hidden="true"></i>`;
}

function quickProductIcon(product) {
    const category = String(product.category_name || '').toLowerCase();
    if (category.includes('beverage') || category.includes('drink')) return 'bi-cup-straw';
    if (category.includes('snack') || category.includes('biscuit')) return 'bi-cookie';
    if (category.includes('personal') || category.includes('health')) return 'bi-heart-pulse';
    if (category.includes('school') || category.includes('office')) return 'bi-pencil';
    if (category.includes('electronic')) return 'bi-battery-charging';
    if (category.includes('baking') || category.includes('grocery')) return 'bi-basket2';
    return 'bi-bag';
}

function quickProductStockState(product) {
    const stock = Number(product.quantity_on_hand || 0);
    const threshold = Math.max(Number(product.reorder_level || 0), Number(product.safety_stock || 0), lowStockFallback);
    return stock <= 0 ? 'out' : (stock <= threshold ? 'low' : 'available');
}

function addQuickProduct(productId, sellingForm = 'piece') {
    const product = quickProductById.get(Number(productId));
    if (!product || Number(product.quantity_on_hand) <= 0) {
        return;
    }
    const isPack = sellingForm === 'pack';
    if (isPack && Number(product.sealed_packs || 0) <= 0) {
        showCartMessage('Insufficient sealed pack stock.', 'warning');
        return;
    }
    const price = isPack && product.pack_price ? product.pack_price : product.price;
    const packSize = isPack ? product.pack_size : null;
    const availableStock = isPack ? Number(product.sealed_packs || 0) : Number(product.quantity_on_hand);
    addToCart(product.product_id, product.name, price, availableStock, product.reorder_level, product.safety_stock, product.sku, product.barcode, sellingForm, packSize);
}

function updateQuickProductAvailability() {
    document.querySelectorAll('.quick-product-card[data-product-id]').forEach(card => {
        const product = quickProductById.get(Number(card.dataset.productId));
        if (!product) return;
        const stock = Number(product.quantity_on_hand);
        const hasPack = Number(product.pack_size) > 1 && Number(product.pack_price) > 0;
        const action = card.querySelector('.quick-product-action');

        if (stock <= 0) {
            card.classList.remove('selected');
            action.innerHTML = '<button type="button" class="quick-product-add" disabled>Out of stock</button>';
            return;
        }

        if (hasPack) {
            const pieceQty = Number(cart[`${product.product_id}:piece`]?.qty || cart[product.product_id]?.qty || 0);
            const packQty = Number(cart[`${product.product_id}:pack`]?.qty || 0);
            const sealedStock = Number(product.sealed_packs || 0);
            const remainingPacks = sealedStock - packQty;
            const totalBaseInCart = (packQty * Number(product.pack_size)) + pieceQty;
            const remainingBase = stock - totalBaseInCart;
            card.classList.toggle('selected', pieceQty > 0 || packQty > 0);

            let pieceHtml = '';
            if (pieceQty > 0) {
                pieceHtml = `<div class="quick-product-stepper" aria-label="${escapeHtml(product.name)} piece quantity">
                    <button type="button" data-quick-decrease="${Number(product.product_id)}:piece" aria-label="Decrease piece"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                    <strong>${pieceQty} pc</strong>
                    <button type="button" data-quick-add="${Number(product.product_id)}" data-selling-form="piece" aria-label="Add piece" ${remainingBase <= 0 ? 'disabled' : ''}><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                </div>`;
            } else {
                pieceHtml = `<button type="button" class="quick-product-add" data-quick-add="${Number(product.product_id)}" data-selling-form="piece" ${remainingBase <= 0 ? 'disabled' : ''} style="flex:1;min-height:36px;padding:0.25rem 0.5rem;"><i class="bi bi-plus-lg" aria-hidden="true"></i>Piece</button>`;
            }

            let packHtml = '';
            if (packQty > 0) {
                packHtml = `<div class="quick-product-stepper" aria-label="${escapeHtml(product.name)} pack quantity">
                    <button type="button" data-quick-decrease="${Number(product.product_id)}:pack" aria-label="Decrease pack"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                    <strong>${packQty} pk</strong>
                    <button type="button" data-quick-add="${Number(product.product_id)}" data-selling-form="pack" aria-label="Add pack" ${(remainingPacks <= 0 || remainingBase < Number(product.pack_size)) ? 'disabled' : ''}><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                </div>`;
            } else {
                const packDisabled = (sealedStock <= 0 || remainingBase < Number(product.pack_size));
                packHtml = `<button type="button" class="quick-product-add" data-quick-add="${Number(product.product_id)}" data-selling-form="pack" ${packDisabled ? 'disabled' : ''} style="flex:1;min-height:36px;padding:0.25rem 0.5rem;"><i class="bi bi-box-seam" aria-hidden="true"></i>Pack of ${product.pack_size}</button>`;
            }

            action.innerHTML = `<div style="display:flex;gap:0.35rem;width:100%;align-items:center;">${pieceHtml}${packHtml}</div>`;
        } else {
            const quantity = Number(cart[`${product.product_id}:piece`]?.qty || cart[product.product_id]?.qty || 0);
            const remaining = stock - quantity;
            card.classList.toggle('selected', quantity > 0);
            if (quantity > 0) {
                action.innerHTML = `<div class="quick-product-stepper" aria-label="${escapeHtml(product.name)} quantity">
                    <button type="button" data-quick-decrease="${Number(product.product_id)}:piece" aria-label="Decrease ${escapeHtml(product.name)}"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                    <strong>${quantity}</strong>
                    <button type="button" data-quick-add="${Number(product.product_id)}" data-selling-form="piece" aria-label="Add another ${escapeHtml(product.name)}" ${remaining <= 0 ? 'disabled' : ''}><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                </div>`;
            } else {
                action.innerHTML = `<button type="button" class="quick-product-add" data-quick-add="${Number(product.product_id)}" data-selling-form="piece" ${remaining <= 0 ? 'disabled' : ''}><i class="bi bi-plus-lg" aria-hidden="true"></i>Add to Cart</button>`;
            }
        }
    });
}

function renderQuickProducts() {
    const term = skuInput.value.trim().toLowerCase();
    const activeCategory = quickCategoryFilters.querySelector('.active')?.dataset.quickCategory || 'all';
    const products = quickProducts.filter(product => {
        const categoryMatches = activeCategory === 'all' || Number(product.category_id) === Number(activeCategory);
        const searchText = `${product.name} ${product.sku} ${product.barcode}`.toLowerCase();
        return categoryMatches && (term === '' || searchText.includes(term));
    });

    if (!products.length) {
        quickProductGrid.innerHTML = '<div class="quick-products-empty"><i class="bi bi-search" aria-hidden="true"></i><span>No matching products found.</span></div>';
        return;
    }

    quickProductGrid.innerHTML = products.map(product => {
        const stock = Number(product.quantity_on_hand || 0);
        const stockState = quickProductStockState(product);
        const stockLabel = stockState === 'out' ? 'Out of stock' : `${stock} in stock`;
        const warning = stockState === 'low' ? '<span class="quick-stock-warning"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>Low stock</span>' : '';
        const hasPack = Number(product.pack_size) > 1 && Number(product.pack_price) > 0;
        const priceLabel = hasPack
            ? `<span><strong>&#8369;${money(product.price)}</strong> / pc &middot; <strong>&#8369;${money(product.pack_price)}</strong> / pk</span>`
            : `<strong>&#8369;${money(product.price)}</strong>`;
        return `<article class="quick-product-card ${stockState === 'out' ? 'out-of-stock' : ''}" data-product-id="${Number(product.product_id)}">
            <div class="quick-product-image">${quickProductMedia(product)}${warning}</div>
            <div class="quick-product-details">
                <strong class="quick-product-name" title="${escapeHtml(product.name)}">${escapeHtml(product.name)}</strong>
                <span class="quick-product-sku">${escapeHtml(product.category_name)} &middot; ${escapeHtml(product.sku || product.barcode || 'No SKU')}</span>
                <div class="quick-product-meta">
                    ${priceLabel}
                    <span class="quick-product-stock ${stockState}"><i class="bi bi-circle-fill" aria-hidden="true"></i>${stockLabel}</span>
                </div>
                <div class="quick-product-action"></div>
            </div>
        </article>`;
    }).join('');
    updateQuickProductAvailability();
}

function getCartTotal() {
    return Object.values(cart).reduce((total, item) => total + (Number(item.price) * Number(item.qty)), 0);
}

function getDiscountAmount() {
    const gross = getCartTotal();
    const value = Math.max(0, Number(discountValue?.value || 0));
    if (discountType?.value === 'percentage') {
        return Math.min(gross, gross * Math.min(value, 100) / 100);
    }
    if (discountType?.value === 'fixed') {
        return Math.min(gross, value);
    }
    return 0;
}

function getNetTotal() {
    return Math.max(0, getCartTotal() - getDiscountAmount());
}

function getCartItemCount() {
    return Object.values(cart).reduce((total, item) => total + Number(item.qty || 0), 0);
}

function getLowStockThreshold(item) {
    return Math.max(Number(item.reorder_level || 0), Number(item.safety_stock || 0), lowStockFallback);
}

function showCartMessage(message, type = 'info') {
    const kind = ['error', 'warning', 'success', 'info'].includes(type) ? type : 'info';
    return RetailMindUI.toast(message, kind, undefined, 4300);
}

function playScanTone(success = true) {
    try {
        posAudioContext = posAudioContext || new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = posAudioContext.createOscillator();
        const gain = posAudioContext.createGain();
        oscillator.type = success ? 'sine' : 'square';
        oscillator.frequency.value = success ? 880 : 220;
        gain.gain.setValueAtTime(0.0001, posAudioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.08, posAudioContext.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, posAudioContext.currentTime + (success ? 0.12 : 0.28));
        oscillator.connect(gain); gain.connect(posAudioContext.destination);
        oscillator.start(); oscillator.stop(posAudioContext.currentTime + (success ? 0.13 : 0.3));
    } catch (error) {}
}

function pulseRecentScan() {
    if (!recentScan) {
        return;
    }
    recentScan.classList.remove('scan-pulse');
    void recentScan.offsetWidth;
    recentScan.classList.add('scan-pulse');
}

function showRecentScan(product, code, status = 'success') {
    playScanTone(status === 'success');
    if (!recentScan) {
        return;
    }
    recentScan.classList.toggle('warning', status !== 'success');
    pulseRecentScan();
    const isPack = product && product.selling_form === 'pack';
    const formNote = isPack ? ` [Pack of ${product.pack_size || 1}]` : '';
    recentScan.innerHTML = product
        ? `<div class="recent-scan-icon"><i class="bi bi-check2" aria-hidden="true"></i></div>
           <div class="recent-scan-copy"><strong>${escapeHtml(product.name)}${formNote}</strong><span>${escapeHtml(code)} &middot; Stock ${Number(product.quantity_on_hand)}</span></div>
           <div class="recent-scan-price">&#8369;${money(product.price)}</div>`
        : `<div class="recent-scan-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
           <div class="recent-scan-copy"><strong>Scan not added</strong><span>${escapeHtml(code)}</span></div>
            <div class="recent-scan-price">—</div>`;
}

const POS_CATALOG_DB_NAME = 'retailmind_pos';
const POS_CATALOG_DB_VERSION = 1;
const POS_CATALOG_STORE = 'products';
let posDb = null;
const catalogProductMap = new Map();

function initPosCatalogDb(items = []) {
    return new Promise((resolve) => {
        if (typeof indexedDB === 'undefined') return resolve(null);
        const req = indexedDB.open(POS_CATALOG_DB_NAME, POS_CATALOG_DB_VERSION);
        req.onupgradeneeded = event => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(POS_CATALOG_STORE)) {
                const store = db.createObjectStore(POS_CATALOG_STORE, { keyPath: 'product_id' });
                store.createIndex('sku', 'sku', { unique: false });
                store.createIndex('barcode', 'barcode', { unique: false });
                store.createIndex('case_barcode', 'case_barcode', { unique: false });
            }
        };
        req.onsuccess = event => {
            posDb = event.target.result;
            window.posDb = posDb;
            if (Array.isArray(items) && items.length > 0) {
                try {
                    const tx = posDb.transaction(POS_CATALOG_STORE, 'readwrite');
                    const store = tx.objectStore(POS_CATALOG_STORE);
                    store.clear();
                    for (const item of items) {
                        store.put(item);
                        catalogProductMap.set(Number(item.product_id), {
                            ...item,
                            initial_stock: Number(item.quantity_on_hand)
                        });
                    }
                    tx.oncomplete = () => {
                        syncAllCatalogStock();
                        resolve(posDb);
                    };
                    tx.onerror = () => resolve(posDb);
                } catch (e) {
                    resolve(posDb);
                }
            } else {
                try {
                    const tx = posDb.transaction(POS_CATALOG_STORE, 'readonly');
                    const store = tx.objectStore(POS_CATALOG_STORE);
                    const getAllReq = store.getAll();
                    getAllReq.onsuccess = () => {
                        if (Array.isArray(getAllReq.result)) {
                            for (const item of getAllReq.result) {
                                catalogProductMap.set(Number(item.product_id), {
                                    ...item,
                                    initial_stock: Number(item.quantity_on_hand)
                                });
                            }
                        }
                        syncAllCatalogStock();
                        resolve(posDb);
                    };
                    getAllReq.onerror = () => resolve(posDb);
                } catch (e) {
                    resolve(posDb);
                }
            }
        };
        req.onerror = () => resolve(null);
    });
}

function syncCatalogStock(productId) {
    const id = Number(productId);
    const item = (typeof catalogProductMap !== 'undefined' ? catalogProductMap : window.catalogProductMap)?.get(id);
    if (!item) return;
    if (item.initial_stock === undefined) {
        item.initial_stock = Number(item.quantity_on_hand);
    }
    let totalPiecesInCart = 0;
    for (const [key, cartItem] of Object.entries(cart)) {
        const itemPid = cartItem.product_id ? Number(cartItem.product_id) : parseInt(key, 10);
        if (itemPid === id) {
            const form = cartItem.selling_form || (String(key).endsWith(':pack') ? 'pack' : 'piece');
            const mult = (form === 'pack' && cartItem.pack_size) ? Number(cartItem.pack_size) : 1;
            totalPiecesInCart += Number(cartItem.qty || 0) * mult;
        }
    }
    item.quantity_on_hand = Math.max(0, item.initial_stock - totalPiecesInCart);
}

function syncAllCatalogStock() {
    const map = (typeof catalogProductMap !== 'undefined' ? catalogProductMap : window.catalogProductMap);
    if (!map) return;
    for (const id of map.keys()) syncCatalogStock(id);
}

function lookupProductInIndexedDB(code) {
    const db = (typeof posDb !== 'undefined' ? posDb : (window.posDb || null));
    if (!db) return Promise.resolve(null);
    return new Promise((resolve) => {
        try {
            const tx = db.transaction(POS_CATALOG_STORE, 'readonly');
            const store = tx.objectStore(POS_CATALOG_STORE);
            let resolved = false;
            const upperCode = code.toUpperCase();
            const requests = [
                store.index('barcode').get(code),
                store.index('sku').get(code)
            ];
            if (code !== upperCode) {
                requests.push(store.index('sku').get(upperCode));
            }
            let pending = requests.length;

            const checkDone = () => {
                pending--;
                if (pending === 0 && !resolved) resolve(null);
            };

            requests.forEach(req => {
                req.onsuccess = () => {
                    if (req.result && !resolved) {
                        resolved = true;
                        resolve(req.result);
                    } else {
                        checkDone();
                    }
                };
                req.onerror = checkDone;
            });
        } catch (e) {
            resolve(null);
        }
    });
}

function getLocalStock(productId) {
    const id = Number(productId);
    const inMem = (typeof catalogProductMap !== 'undefined' ? catalogProductMap : window.catalogProductMap)?.get(id);
    if (inMem) return inMem.quantity_on_hand;
    return cart[id]?.stock !== undefined ? (cart[id].stock - (cart[id].qty || 0)) : null;
}

const CATALOG_SYNC_INTERVAL_MS = 300000;
let catalogSyncTimer = null;
let currentCatalogEtag = typeof posCatalogEtag !== 'undefined' ? (posCatalogEtag || '') : '';
let lastCatalogSyncTime = typeof posCatalogServerTime !== 'undefined' ? (posCatalogServerTime || '') : '';

function applyCatalogDelta(items = []) {
    return new Promise((resolve) => {
        const db = (typeof posDb !== 'undefined' ? posDb : (window.posDb || null));
        if (!db || !Array.isArray(items) || items.length === 0) {
            return resolve(false);
        }
        try {
            const tx = db.transaction(POS_CATALOG_STORE, 'readwrite');
            const store = tx.objectStore(POS_CATALOG_STORE);
            for (const item of items) {
                store.put(item);
                const id = Number(item.product_id);
                const map = (typeof catalogProductMap !== 'undefined' ? catalogProductMap : window.catalogProductMap);
                if (map) {
                    const existing = map.get(id);
                    map.set(id, {
                        ...(existing || {}),
                        ...item,
                        initial_stock: Number(item.quantity_on_hand)
                    });
                    if (typeof syncCatalogStock === 'function') syncCatalogStock(id);
                }
                if (typeof quickProductById !== 'undefined' && quickProductById && quickProductById.has(id)) {
                    const qp = quickProductById.get(id);
                    if (item.unit_price !== undefined) qp.price = Number(item.unit_price);
                    if (item.quantity_on_hand !== undefined) qp.quantity_on_hand = Number(item.quantity_on_hand);
                }
            }
            tx.oncomplete = () => {
                if (typeof renderQuickProducts === 'function') renderQuickProducts();
                resolve(true);
            };
            tx.onerror = () => resolve(false);
        } catch (e) {
            resolve(false);
        }
    });
}

async function syncCatalog(isManual = false) {
    if (!posShiftOpen || (typeof cartWorkspace !== 'undefined' && cartWorkspace.context?.locked)) {
        return { success: false, reason: 'Shift closed or locked' };
    }
    const origin = (typeof window !== 'undefined' && window.location?.origin) ? window.location.origin : 'http://127.0.0.1';
    const url = new URL(barcodeApiUrl, origin);
    url.searchParams.set('action', 'catalog_sync');
    if (lastCatalogSyncTime) {
        url.searchParams.set('since', lastCatalogSyncTime);
    }
    if (currentCatalogEtag) {
        url.searchParams.set('if_none_match', currentCatalogEtag);
    }

    const headers = {};
    if (currentCatalogEtag) {
        headers['If-None-Match'] = currentCatalogEtag;
    }

    try {
        const res = await fetch(url.toString(), { headers });
        if (res.status === 304) {
            if (isManual) {
                showCartMessage('Catalog is already up to date.', 'info');
            }
            return { success: true, notModified: true, updated: 0 };
        }

        if (res.status === 200) {
            const data = await res.json();
            if (data.success && Array.isArray(data.catalog)) {
                const etag = res.headers?.get ? res.headers.get('ETag') : data.etag;
                if (etag) currentCatalogEtag = etag;
                if (data.server_time) lastCatalogSyncTime = data.server_time;

                if (data.catalog.length > 0) {
                    await applyCatalogDelta(data.catalog);
                }
                if (isManual) {
                    const count = data.catalog.length;
                    showCartMessage(count > 0 ? `Catalog refreshed with ${count} products.` : 'Catalog is already up to date.', 'success');
                }
                return { success: true, notModified: false, updated: data.catalog.length };
            }
        }

        if (isManual) {
            showCartMessage('Failed to refresh catalog.', 'warning');
        }
        return { success: false };
    } catch (err) {
        if (isManual) {
            showCartMessage('Could not reach server to refresh catalog.', 'warning');
        }
        return { success: false, error: err };
    }
}

function startCatalogSyncLoop() {
    if (catalogSyncTimer) clearInterval(catalogSyncTimer);
    if (!posShiftOpen || (typeof cartWorkspace !== 'undefined' && cartWorkspace.context?.locked)) {
        return;
    }
    catalogSyncTimer = setInterval(async () => {
        if (!posShiftOpen || (typeof cartWorkspace !== 'undefined' && cartWorkspace.context?.locked)) {
            return;
        }
        try {
            await syncCatalog(false);
        } catch (err) {
            console.warn('Background catalog sync failed:', err);
        }
    }, CATALOG_SYNC_INTERVAL_MS);
}

window.initPosCatalogDb = initPosCatalogDb;
window.lookupProductInIndexedDB = lookupProductInIndexedDB;
window.catalogProductMap = catalogProductMap;
window.getLocalStock = getLocalStock;
window.applyCatalogDelta = applyCatalogDelta;
window.syncCatalog = syncCatalog;
window.startCatalogSyncLoop = startCatalogSyncLoop;
window.CATALOG_SYNC_INTERVAL_MS = CATALOG_SYNC_INTERVAL_MS;

async function fetchProductByCode(code) {
    const normalized = String(code || '').trim();
    if (normalized === '') {
        showCartMessage('Enter a SKU or barcode to add.', 'error');
        return null;
    }

    const db = (typeof posDb !== 'undefined' ? posDb : (window.posDb || null));
    const lookupFn = (typeof lookupProductInIndexedDB === 'function' ? lookupProductInIndexedDB : window.lookupProductInIndexedDB);
    if (db && lookupFn) {
        try {
            const localProduct = await lookupFn(normalized);
            if (localProduct) {
                const productId = Number(localProduct.product_id);
                const catalogMap = (typeof catalogProductMap !== 'undefined' ? catalogProductMap : window.catalogProductMap);
                const inMem = catalogMap?.get(productId);
                const initialStock = inMem?.initial_stock !== undefined ? inMem.initial_stock : Number(localProduct.quantity_on_hand);
                const packQty = Number(cart[`${productId}:pack`]?.qty || 0);
                const pieceQty = Number(cart[`${productId}:piece`]?.qty || cart[productId]?.qty || 0);
                const packSize = Number(localProduct.pack_size || 1);
                const currentQty = (packQty * packSize) + pieceQty;

                if (currentQty >= initialStock) {
                    showRecentScan(null, normalized, 'warning');
                    showCartMessage(`Insufficient stock. Only ${initialStock} available.`, 'warning');
                    return null;
                }

                return {
                    product_id: productId,
                    sku: localProduct.sku,
                    barcode: localProduct.barcode,
                    case_barcode: localProduct.case_barcode,
                    name: localProduct.product_name,
                    price: Number(localProduct.unit_price),
                    quantity_on_hand: initialStock,
                    reorder_level: Number(localProduct.reorder_level || 0),
                    safety_stock: Number(localProduct.safety_stock || 0)
                };
            }
        } catch (dbErr) {
            console.warn('Local catalog lookup error:', dbErr);
        }
    }

    try {
        const response = await fetch(barcodeApiUrl, {
            method: 'POST',
            body: new URLSearchParams({ action: 'scan_lookup', csrf_token: csrfToken, code: normalized,
                quantities: JSON.stringify(Object.fromEntries(Object.entries(cart).map(([id, item]) => [id, item.qty]))) })
        });
        const data = await response.json();

        if (data.success && data.product) {
            const entry = {
                product_id: Number(data.product.product_id),
                sku: data.product.sku,
                barcode: data.product.barcode,
                name: data.product.product_name,
                price: Number(data.product.price !== undefined ? data.product.price : data.product.unit_price),
                quantity_on_hand: Number(data.product.available_stock !== undefined ? data.product.available_stock : data.product.quantity_on_hand),
                reorder_level: Number(data.product.reorder_level || 0),
                safety_stock: Number(data.product.safety_stock || 0),
                selling_form: data.product.selling_form || 'piece',
                pack_size: data.product.pack_size ? Number(data.product.pack_size) : null
            };

            return entry;
        }

        showRecentScan(null, normalized, 'warning');
        const message = data.message || 'Product not found.';
        showCartMessage(message, message.startsWith('Insufficient stock') ? 'warning' : 'error');
        return null;
    } catch (error) {
        showRecentScan(null, normalized, 'warning');
        showCartMessage('Product lookup failed. Check the network or login session.', 'error');
        return null;
    }
}

let scanQueue = Promise.resolve();
function addCodeFromInput() {
    const code = skuInput.value.trim();
    skuInput.value = '';
    skuInput.dispatchEvent(new Event('input'));
    skuInput.focus();
    queueBarcode(code);
}

function queueBarcode(code) {
    scanQueue = scanQueue.then(() => addScannedCode(code)).catch(() => {
        showCartMessage('Product lookup failed. Please try again.', 'error');
    });
    return scanQueue;
}

async function addScannedCode(code) {
    if (!cartChangesAllowed()) return;
    const product = await fetchProductByCode(code);
    if (!product) return;

    if (!addToCart(product.product_id, product.name, product.price, product.quantity_on_hand, product.reorder_level, product.safety_stock, product.sku, product.barcode, product.selling_form, product.pack_size)) return;
    showRecentScan(product, code);
    scannerResult.innerHTML = `<strong>Item added</strong><span>${escapeHtml(product.name)}</span>`;
}

function cartChangesAllowed() {
    if (!posShiftOpen || cartWorkspace.context.locked || cartWorkspace.busy || checkoutSubmitting || (typeof checkoutAttempt !== 'undefined' && checkoutAttempt.pending)) {
        showCartMessage('Retry or cancel the unsuccessful checkout before changing the cart. Unlock your Register if it is locked.', 'error'); return false;
    }
    return true;
}

function cartLineKey(productId, form = 'piece') {
    return `${Number(productId)}:${form === 'pack' ? 'pack' : 'piece'}`;
}

function resolveCartKey(id, form = null) {
    if (cart[id]) return id;
    const strId = String(id);
    if (strId.includes(':')) {
        const [rawPid, keyForm] = strId.split(':');
        const targetForm = form || keyForm;
        const targetKey = cartLineKey(rawPid, targetForm);
        if (cart[targetKey]) return targetKey;
        if (targetForm === 'piece' && cart[rawPid]) return rawPid;
        return targetKey;
    }
    const pid = Number(strId);
    if (form) {
        const direct = cartLineKey(pid, form);
        if (cart[direct]) return direct;
        if (form === 'piece' && cart[pid]) return pid;
        return direct;
    }
    const pieceKey = `${pid}:piece`;
    if (cart[pieceKey]) return pieceKey;
    const packKey = `${pid}:pack`;
    if (cart[packKey]) return packKey;
    return id;
}

function cartItemForm(key, item = null) {
    if (item?.selling_form) return item.selling_form;
    return String(key).endsWith(':pack') ? 'pack' : 'piece';
}

function cartItemProductId(key, item = null) {
    if (item?.product_id) return Number(item.product_id);
    return Number(String(key).split(':')[0]) || 0;
}

function addToCart(id, name, price, stock = Infinity, reorderLevel = 0, safetyStock = 0, sku = '', barcode = '', sellingForm = 'piece', packSize = null) {
    if (!cartChangesAllowed()) return false;
    const productId = Number(id);
    const form = sellingForm === 'pack' ? 'pack' : 'piece';
    const lineKey = cartLineKey(productId, form);
    const currentKey = resolveCartKey(lineKey, form);
    const currentQty = cart[currentKey]?.qty || 0;
    const availableStock = Number(stock);
    if (currentQty + 1 > availableStock) {
        showCartMessage(form === 'pack' ? 'Insufficient sealed pack stock.' : 'Insufficient stock.', 'warning');
        return false;
    }

    cart[lineKey] = {
        product_id: productId,
        name,
        price: Number(price),
        qty: currentQty + 1,
        selling_form: form,
        pack_size: packSize !== null && packSize !== undefined ? Number(packSize) : null,
        stock: Number.isFinite(availableStock) ? availableStock : undefined,
        reorder_level: Number(reorderLevel || 0),
        safety_stock: Number(safetyStock || 0),
        sku,
        barcode
    };
    if (currentKey !== lineKey && cart[currentKey]) {
        delete cart[currentKey];
    }
    if (typeof syncCatalogStock === 'function') syncCatalogStock(productId);
    renderCart();

    const remaining = availableStock - cart[lineKey].qty;
    if (Number.isFinite(availableStock) && remaining <= getLowStockThreshold(cart[lineKey])) {
        showCartMessage(`Product added to cart. Low stock: ${remaining} left.`, 'warning');
    } else {
        RetailMindUI.toast('Product added to cart.', 'success', undefined, 1000);
    }
    return true;
}

function updateCartQty(id, newQty) {
    if (!cartChangesAllowed()) return;
    const key = (typeof resolveCartKey === 'function') ? resolveCartKey(id) : (cart[id] ? id : (cart[`${id}:piece`] ? `${id}:piece` : (cart[`${id}:pack`] ? `${id}:pack` : id)));
    const item = cart[key];
    if (!item) {
        return;
    }

    const qty = Number(newQty);
    if (!Number.isInteger(qty) || qty <= 0) {
        renderCart();
        showCartMessage('Quantity must be at least 1. Use delete to remove the item.', 'warning');
        return;
    }

    if (item.stock !== undefined && qty > item.stock) {
        renderCart();
        showCartMessage('Insufficient stock.', 'warning'); return;
    } else {
        cart[key].qty = qty;
    }
    const pid = (typeof cartItemProductId === 'function') ? cartItemProductId(key, item) : (item.product_id || parseInt(key, 10));
    if (typeof syncCatalogStock === 'function') syncCatalogStock(pid);
    renderCart();
    showCartMessage('Cart updated successfully.', 'info');
}

function changeCartQty(id, delta) {
    const key = (typeof resolveCartKey === 'function') ? resolveCartKey(id) : (cart[id] ? id : (cart[`${id}:piece`] ? `${id}:piece` : (cart[`${id}:pack`] ? `${id}:pack` : id)));
    const item = cart[key];
    if (item) {
        updateCartQty(key, item.qty + delta);
    }
}

function removeFromCart(id) {
    if (!cartChangesAllowed()) return;
    const key = (typeof resolveCartKey === 'function') ? resolveCartKey(id) : (cart[id] ? id : (cart[`${id}:piece`] ? `${id}:piece` : (cart[`${id}:pack`] ? `${id}:pack` : id)));
    if (!cart[key]) {
        return;
    }
    const itemName = cart[key].name;
    const pid = (typeof cartItemProductId === 'function') ? cartItemProductId(key, cart[key]) : (cart[key].product_id || parseInt(key, 10));
    delete cart[key];
    if (typeof syncCatalogStock === 'function') syncCatalogStock(pid);
    renderCart();
    showCartMessage(`${itemName} removed from the cart.`, 'info');
}

async function clearCart() {
    if (!cartChangesAllowed() || (!Object.keys(cart).length && !resumedHeldSaleId)) return;
    openDiscardModal(resumedHeldSaleId);
}

function resetPaymentState() {
    paymentMethod.value = 'cash';
    cashReceived.value = '';
    paymentReference.value = '';
    discountType.value = 'none';
    discountValue.value = '0';
    discountReason.value = '';
    // Ticket #91: a cart that has been emptied or replaced is no longer a
    // resumed held sale, so the next checkout must not claim to complete one.
    // resumeHeldSale() sets this again once the cart is in place.
    resumedHeldSaleId = 0;
    resumedHeldSaleInput.value = '';
    const approverUsername = document.getElementById('discount-approver-username');
    const approverPassword = document.getElementById('discount-approver-password');
    if (approverUsername) approverUsername.value = '';
    if (approverPassword) approverPassword.value = '';
    updatePaymentFields();
}

function renderCart() {
    const cartBody = document.getElementById('cart-body');
    let rows = '';

    if (Object.keys(cart).length === 0) {
        rows = '<tr><td colspan="4" class="cart-empty-cell"><i class="bi bi-cart3" aria-hidden="true"></i>Scan or search for a product to begin.</td></tr>';
    } else {
        for (const id in cart) {
            const item = cart[id];
            const subtotal = item.price * item.qty;
            const remaining = item.stock !== undefined ? item.stock - item.qty : null;
            const threshold = getLowStockThreshold(item);
            const stockNote = item.stock !== undefined ? `Stock ${item.stock}` : 'Stock unavailable';
            const warning = remaining !== null && remaining <= threshold
                ? `<span class="stock-warning">Only ${remaining} left after sale</span>`
                : '';
            const isPack = item.selling_form === 'pack';
            const formBadge = isPack
                ? `<span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem;padding:2px 6px;border-radius:4px;margin-left:6px;font-weight:600;">Pack of ${item.pack_size || 1}</span>`
                : `<span class="badge" style="background:#f3f4f6;color:#4b5563;font-size:0.75rem;padding:2px 6px;border-radius:4px;margin-left:6px;font-weight:500;">Piece</span>`;
            const escapedId = escapeHtml(String(id));

            rows += `<tr>
                <td><div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;"><strong class="cart-item-name" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</strong>${formBadge}</div><span class="cart-item-meta">${escapeHtml(item.sku || item.barcode || '')} &middot; ${stockNote}</span>${warning}</td>
                <td><div class="cart-actions"><button type="button" class="qty-btn" onclick="changeCartQty('${escapedId}', -1)" aria-label="Decrease ${escapeHtml(item.name)} quantity">−</button><input class="qty-field" type="number" min="1" value="${Number(item.qty)}" onchange="updateCartQty('${escapedId}', Number(this.value))" aria-label="${escapeHtml(item.name)} quantity"><button type="button" class="qty-btn" onclick="changeCartQty('${escapedId}', 1)" aria-label="Increase ${escapeHtml(item.name)} quantity">+</button></div></td>
                <td><strong>&#8369;${money(subtotal)}</strong></td>
                <td><button type="button" class="remove-item-btn" onclick="removeFromCart('${escapedId}')" aria-label="Remove ${escapeHtml(item.name)}"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
            </tr>`;
        }
    }

    const lineCount = Object.keys(cart).length;
    const itemCount = getCartItemCount();
    const gross = getCartTotal();
    const discount = getDiscountAmount();
    const net = getNetTotal();
    const hasItems = lineCount > 0;
    cartBody.innerHTML = rows;
    document.getElementById('cart-total').textContent = money(net);
    document.getElementById('cart-item-count').textContent = itemCount;
    document.getElementById('cart-line-count').textContent = lineCount;
    setTextIfPresent('snapshot-lines', lineCount);
    setTextIfPresent('snapshot-items', itemCount);
    setTextIfPresent('snapshot-discount', money(discount));
    setTextIfPresent('snapshot-total', money(net));
    document.getElementById('summary-subtotal').textContent = money(gross);
    document.getElementById('summary-discount').textContent = money(discount);
    checkoutButton.disabled = !hasItems || !posShiftOpen;
    holdSaleButton.disabled = !hasItems || !posShiftOpen;
    voidSaleButton.disabled = !hasItems;
    clearCartButton.disabled = !hasItems;
    updatePaymentFields();
    persistCart();
    updateQuickProductAvailability();
}

function updatePaymentFields() {
    const total = getNetTotal();
    const gross = getCartTotal();
    const discount = getDiscountAmount();
    const isCash = paymentMethod.value === 'cash';

    document.getElementById('cash-field').classList.toggle('hidden', !isCash);
    document.getElementById('change-field').classList.toggle('hidden', !isCash);
    referenceField.classList.toggle('hidden', isCash);
    cashQuick.classList.toggle('hidden', !isCash);
    paymentReference.required = !isCash;
    cashReceived.required = isCash;

    const receivedValue = Number(cashReceived.value);
    const received = Number.isFinite(receivedValue) && receivedValue >= 0 ? receivedValue : 0;
    changeDue.value = money(Math.max(0, received - total));
    const cashAdded = isCash && getCartItemCount() > 0 ? Math.min(received, total) : 0;
    document.getElementById('drawer-cash-added').textContent = money(cashAdded);
    document.getElementById('summary-subtotal').textContent = money(gross);
    document.getElementById('summary-discount').textContent = money(discount);
    document.getElementById('summary-change').textContent = money(Math.max(0, received - total));
    setTextIfPresent('snapshot-discount', money(discount));
    setTextIfPresent('snapshot-total', money(total));
    if (discountSummary) {
        discountSummary.textContent = discount > 0 ? `Gross ₱${money(gross)} · Discount ₱${money(discount)} · Net ₱${money(total)}` : 'No discount applied.';
        supervisorFields.classList.toggle('hidden', !(discount > gross * 0.10));
    }
}


function setQuickTender(value) {
    const total = getNetTotal();
    if (total <= 0) {
        showCartMessage('Add items before entering payment.', 'error');
        return;
    }
    cashReceived.value = money(value);
    updatePaymentFields();
}

function validateCheckout(requirePayment = true) {
    const payload = Object.entries(cart).map(([key, item]) => {
        const pid = item.product_id ? Number(item.product_id) : parseInt(key, 10);
        const form = item.selling_form || (String(key).endsWith(':pack') ? 'pack' : 'piece');
        return {
            product_id: pid,
            selling_form: form,
            qty: Number(item.qty)
        };
    });
    if (payload.length === 0) {
        showCartMessage('Cart is empty. Add items before checkout.', 'error');
        skuInput.focus();
        return false;
    }

    const total = checkoutQuote.isCurrent() ? checkoutQuote.reviewed.total : getNetTotal();
    if (requirePayment && paymentMethod.value === 'cash') {
        const received = Number(cashReceived.value);
        if (!Number.isFinite(received) || received < 0) {
            showCartMessage('Enter a valid cash amount.', 'error');
            cashReceived.focus();
            return false;
        }
        if (received < total) {
            showCartMessage('Cash received is less than the total due.', 'error');
            cashReceived.focus();
            return false;
        }
    }
    if (requirePayment && paymentMethod.value !== 'cash' && paymentReference.value.trim() === '') {
        showCartMessage('Enter the card or e-wallet payment reference.', 'error');
        paymentReference.focus();
        return false;
    }

    if (!posShiftOpen) { showCartMessage('Open a Cashier Shift before checkout.', 'error'); return false; }
    if (getDiscountAmount() > 0 && discountReason.value.trim() === '') { showCartMessage('Enter a discount reason.', 'error'); discountReason.focus(); return false; }
    cartInput.value = JSON.stringify(payload);
    return true;
}

async function checkoutNow() {
    if (checkoutSubmitting || !checkoutAttempt.ready || window.cartWorkspace?.busy) return;
    if (typeof navigator !== 'undefined' && 'onLine' in navigator && !navigator.onLine) {
        showCartMessage('Checkout requires an active internet connection. Please check your network and try again.', 'error');
        return;
    }
    if (!validateCheckout(false)) {
        return;
    }

    let quote;
    checkoutButton.disabled = true;
    if (window.cartWorkspace) cartWorkspace.busy = true;
    try {
        if (window.cartWorkspace && resumedHeldSaleId && !checkoutAttempt.pending && !await reviewCurrentCart()) return;
        if (!validateCheckout(false)) return;
        quote = await checkoutQuote.review();
    } catch (error) {
        if (error.conflicts && error.conflicts.length) {
            showStockConflictModal(error.conflicts);
            return;
        }
        showCartMessage(error.message, 'error');
        return;
    } finally {
        if (window.cartWorkspace) cartWorkspace.busy = false;
        checkoutButton.disabled = false;
    }
    const methodLabel = paymentMethod.options[paymentMethod.selectedIndex].text;
    const paymentLine = paymentMethod.value === 'cash'
        ? `<label>Cash received <input id="review-cash" type="number" min="0" step="0.01" value="${escapeHtml(cashReceived.value)}" oninput="updateReviewedPayment()"></label>
            <div class="cash-quick" role="group" aria-label="Quick cash amounts">
                <button type="button" data-cash-amount="exact">Exact</button>
                <button type="button" data-cash-amount="20">₱20</button>
                <button type="button" data-cash-amount="50">₱50</button>
                <button type="button" data-cash-amount="100">₱100</button>
            </div><div class="review-change"><span>Change due</span><output aria-label="Change due">₱<span id="review-change">0.00</span></output></div>`
        : `<p>Complete and verify the ${escapeHtml(methodLabel)} payment externally for ₱${money(quote.total)}.</p><label>Payment Reference <input id="review-reference" value="${escapeHtml(paymentReference.value)}" oninput="updateReviewedPayment()"></label><label><input type="checkbox" id="review-verified"> I verified this payment externally.</label>`;

    checkoutSummary.innerHTML = `
        <table class="review-items" aria-label="Items in this sale">
            <thead><tr><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Amount</th></tr></thead>
            <tbody>${quote.sale.items.map(item => {
                const isPack = item.selling_form === 'pack';
                const formBadge = isPack ? ` <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem;padding:2px 6px;border-radius:4px;">Pack of ${item.pack_size || 1}</span>` : '';
                return `<tr><td><strong>${escapeHtml(item.product_name)}${formBadge}</strong><small>₱${money(item.unit_price)} each</small></td><td>${item.quantity}</td><td>₱${money(item.subtotal)}</td></tr>`;
            }).join('')}</tbody>
        </table>
        <div class="review-details">
            <p><strong>Eligible promotions:</strong> ${quote.eligible_promotions.map(p => escapeHtml(p.promotion_name)).join(', ') || 'None'}</p>
            <p><strong>Selected discount:</strong> ${escapeHtml(quote.discount.promotion_name || quote.discount.discount_reason || 'None')} · ₱${money(quote.discount.discount_amount)}</p>
            <p><strong>Gross:</strong> ₱${money(quote.sale.total)} · <strong>Payment method:</strong> ${escapeHtml(methodLabel)}</p>
        </div>
        <div class="review-total" role="group" aria-label="Total due"><span>Total due: </span><strong>₱${money(quote.total)}</strong></div>
        ${paymentLine}
    `;
    RetailMindUI.openOverlay(checkoutModal);
    updateReviewedPayment();
    checkoutModal.querySelector('.btn:last-child').focus();
}

function updateReviewedPayment() {
    const cash = document.getElementById('review-cash');
    const reference = document.getElementById('review-reference');
    if (cash) {
        cashReceived.value = cash.value;
        document.getElementById('review-change').textContent = money(Math.max(0, Number(cash.value) - checkoutQuote.reviewed.total));
    }
    if (reference) paymentReference.value = reference.value;
    updatePaymentFields();
}

function closeCheckoutConfirm() {
    if (checkoutSubmitting) return;
    RetailMindUI.closeOverlay(checkoutModal);
    checkoutConfirmed = false;
    checkoutButton.focus();
}

async function submitConfirmedCheckout() {
    if (checkoutSubmitting) return;
    if (typeof navigator !== 'undefined' && 'onLine' in navigator && !navigator.onLine) {
        showCartMessage('Checkout requires an active internet connection. Please check your network and try again.', 'error');
        return;
    }
    if (!checkoutAttempt.ready) {
        showCartMessage('Recover the previous checkout before collecting another payment.', 'error');
        return;
    }
    if (!validateCheckout()) {
        closeCheckoutConfirm();
        return;
    }
    if (!checkoutQuote.isCurrent()) {
        showCartMessage('The cart or discount changed. Review the final quote again.', 'error');
        closeCheckoutConfirm();
        return;
    }
    if (paymentMethod.value !== 'cash' && !document.getElementById('review-verified')?.checked) {
        showCartMessage('Verify the external payment before recording it.', 'error');
        return;
    }
    checkoutSubmitting = true;
    checkoutForm.elements.payment_verified.value = paymentMethod.value === 'cash' || document.getElementById('review-verified')?.checked ? '1' : '0';
    checkoutConfirmed = true;
    confirmCheckoutButton.disabled = true;
    confirmCheckoutButton.innerHTML = '<i class="bi bi-hourglass-split" aria-hidden="true"></i>Processing...';
    try {
        await checkoutQuote.review();
        await checkoutAttempt.save(cart);
        checkoutForm.submit();
    } catch (error) {
        checkoutSubmitting = false;
        checkoutConfirmed = false;
        confirmCheckoutButton.disabled = false;
        confirmCheckoutButton.textContent = 'Confirm payment';
        closeCheckoutConfirm();
        if (error.conflicts && error.conflicts.length) {
            showStockConflictModal(error.conflicts);
            return;
        }
        const message = !navigator.locks
            ? 'Checkout recovery requires a browser supporting Web Locks. Open Point of Sale using HTTPS or http://localhost/retailmind before collecting payment.'
            : (error.message || 'Checkout could not be saved for recovery. Reload before collecting another payment. Tell your Administrator if this keeps happening.');
        showCartMessage(message, 'error');
    }
}

let activeStockConflicts = [];

function showStockConflictModal(conflicts) {
    activeStockConflicts = conflicts || [];
    renderStockConflictItems();
    RetailMindUI.openOverlay(stockConflictModal);
    const firstAction = stockConflictModal.querySelector('button, input');
    if (firstAction) firstAction.focus();
}

function closeStockConflictModal() {
    RetailMindUI.closeOverlay(stockConflictModal);
    activeStockConflicts = [];
    checkoutButton.focus();
}

function renderStockConflictItems() {
    const container = document.getElementById('stock-conflict-content');
    if (!container) return;
    if (!activeStockConflicts.length) {
        container.innerHTML = '<p>No stock conflicts detected.</p>';
        return;
    }
    let html = `<p class="conflict-modal-desc">Available inventory is less than requested. Adjust quantities or remove items to proceed.</p>
    <table class="conflict-items-table" style="width:100%; border-collapse:collapse; margin:1rem 0;">
        <thead>
            <tr style="text-align:left; border-bottom:1px solid var(--border-color, #ccc);">
                <th style="padding:0.5rem;">Item</th>
                <th style="padding:0.5rem; text-align:center;">Requested</th>
                <th style="padding:0.5rem; text-align:center;">Available</th>
                <th style="padding:0.5rem; text-align:right;">Action</th>
            </tr>
        </thead>
        <tbody>`;

    for (const item of activeStockConflicts) {
        const id = Number(item.product_id);
        const form = item.selling_form === 'pack' ? 'pack' : 'piece';
        const lineKey = cartLineKey(id, form);
        const name = escapeHtml(item.product_name) + `<span class="badge" style="margin-left:0.35rem;font-size:0.75rem;background:#6c757d;color:#fff;padding:0.15rem 0.35rem;border-radius:3px;">${form === 'pack' ? 'Pack' : 'Piece'}</span>`;
        const requested = Number(item.requested_qty);
        const available = Number(item.available_stock);
        const resolvedKey = resolveCartKey(lineKey, form);
        const currentInCart = cart[resolvedKey] ? Number(cart[resolvedKey].qty) : (cart[id] && form === 'piece' ? Number(cart[id].qty) : 0);
        const escapedKey = escapeHtml(lineKey);

        html += `<tr data-conflict-key="${escapedKey}" style="border-bottom:1px solid var(--border-color, #eee);">
            <td style="padding:0.5rem;"><strong class="conflict-product-name">${name}</strong></td>
            <td style="padding:0.5rem; text-align:center;" class="conflict-requested-qty">${requested}</td>
            <td style="padding:0.5rem; text-align:center;"><strong class="conflict-available-stock ${available > 0 ? 'text-warning' : 'text-danger'}">${available}</strong></td>
            <td style="padding:0.5rem; text-align:right;">`;

        if (available > 0) {
            html += `<div style="display:inline-flex; align-items:center; gap:0.25rem;">
                <input type="number" min="1" max="${available}" value="${Math.min(currentInCart || available, available)}"
                       class="conflict-qty-input" data-conflict-key="${escapedKey}" style="width:60px; padding:0.25rem;"
                       onchange="updateConflictItemQty('${escapedKey}', Number(this.value), ${available})">
                <button type="button" class="btn btn-sm btn-secondary adjust-conflict-qty-btn" onclick="adjustConflictItemQty('${escapedKey}', ${available})">Set to ${available}</button>
                <button type="button" class="btn btn-sm btn-danger remove-conflict-btn" onclick="removeConflictItem('${escapedKey}')">Remove</button>
            </div>`;
        } else {
            html += `<span class="badge" style="background:#dc3545; color:#fff; padding:0.25rem 0.5rem; border-radius:4px; margin-right:0.5rem;">Depleted</span>
                     <button type="button" class="btn btn-sm btn-danger remove-conflict-btn" onclick="removeConflictItem('${escapedKey}')">Remove</button>`;
        }

        html += `</td></tr>`;
    }

    html += `</tbody></table>`;
    container.innerHTML = html;
}

function updateConflictItemQty(lineKey, qty, maxAvailable) {
    const validQty = Math.max(1, Math.min(Number(qty) || 1, maxAvailable));
    const key = resolveCartKey(lineKey);
    if (cart[key]) {
        cart[key].qty = validQty;
        cart[key].stock = maxAvailable;
        renderCart();
        persistCart();
    }
}

function adjustConflictItemQty(lineKey, targetQty) {
    const key = resolveCartKey(lineKey);
    if (cart[key]) {
        cart[key].qty = targetQty;
        cart[key].stock = targetQty;
        renderCart();
        persistCart();
        renderStockConflictItems();
    }
}

function removeConflictItem(lineKey) {
    const key = resolveCartKey(lineKey);
    const pid = cartItemProductId(key, cart[key]);
    delete cart[key];
    if (typeof syncCatalogStock === 'function') syncCatalogStock(pid);
    renderCart();
    persistCart();
    activeStockConflicts = activeStockConflicts.filter(c => cartLineKey(c.product_id, c.selling_form) !== String(lineKey));
    renderStockConflictItems();
}

function applyStockConflictAdjustments() {
    for (const conflict of activeStockConflicts) {
        const form = conflict.selling_form === 'pack' ? 'pack' : 'piece';
        const lineKey = cartLineKey(conflict.product_id, form);
        const avail = Number(conflict.available_stock);
        const key = resolveCartKey(lineKey, form);
        if (avail <= 0) {
            delete cart[key];
        } else if (cart[key] && cart[key].qty > avail) {
            cart[key].qty = avail;
            cart[key].stock = avail;
        }
    }
    renderCart();
    persistCart();
    closeStockConflictModal();
    if (Object.keys(cart).length === 0) {
        showCartMessage('All conflicted items were removed. Cart is empty.', 'info');
        return;
    }
    checkoutNow();
}

if (typeof window !== 'undefined') {
    window.cartLineKey = cartLineKey;
    window.resolveCartKey = resolveCartKey;
    window.cartItemForm = cartItemForm;
    window.cartItemProductId = cartItemProductId;
    window.showStockConflictModal = showStockConflictModal;
    window.closeStockConflictModal = closeStockConflictModal;
    window.updateConflictItemQty = updateConflictItemQty;
    window.adjustConflictItemQty = adjustConflictItemQty;
    window.removeConflictItem = removeConflictItem;
    window.applyStockConflictAdjustments = applyStockConflictAdjustments;
    window.checkoutNow = checkoutNow;
}

async function apiHeldSale(action, payload = {}) {
    return cartWorkspace.request(action, payload);
}

async function loadHeldSales() {
    try {
        const response = await fetch(heldSalesApiUrl, {headers:{'Accept':'application/json'}});
        const data = await response.json();
        heldSales = data.success ? (data.held_sales || []) : [];
    } catch (error) { heldSales = []; }
    renderHeldSales();
}

async function holdCurrentSale() {
    if (!cartChangesAllowed()) return;
    if (Object.keys(cart).length === 0) { showCartMessage('Cart is empty. Add items before holding a sale.', 'error'); return; }
    if (!posShiftOpen) { showCartMessage('Open a Cashier Shift before holding a sale.', 'error'); return; }
    if (resumedHeldSaleId) { showCartMessage('This sale is already held. Complete checkout or discard it from Held sales before holding another sale.', 'error'); return; }
    holdSaleButton.disabled = true;
    try {
        const data = await cartWorkspace.exclusive(() => cartWorkspace.holdRequested(cart));
        if (!data) { cartReviewUnresolved = true; persistCart(); showCartMessage('Requested lines preserved. Availability changes remain unresolved.', 'error'); return; }
        heldSales = data.held_sales || [];
        cart = {};
        resetPaymentState();
        renderCart(); renderHeldSales(); showCartMessage(`Sale ${data.reference_no} held on the server.`, 'success'); skuInput.focus();
    } catch (error) { showCartMessage(error.message, 'error'); }
    finally {
        holdSaleButton.disabled = Object.keys(cart).length === 0 || !posShiftOpen;
    }
}

async function resumeHeldSale(id) {
    if (!cartChangesAllowed()) return;
    if (Object.keys(cart).length > 0 || resumedHeldSaleId) {
        if (!await cartWorkspace.resolveWork()) return;
    }
    try {
        cartWorkspace.busy = true;
        const data = await apiHeldSale('resume', {id});
        cart = data.cart || {}; heldSales = data.held_sales || [];
        resetPaymentState();
        // Ticket #91: the cart is back on the till, so the next checkout is the
        // settlement of this held sale rather than an ordinary new sale.
        resumedHeldSaleId = Number(data.id || id) || 0;
        resumedHeldSaleInput.value = String(resumedHeldSaleId);
        renderCart(); renderHeldSales();
        const reviewed = await cartWorkspace.acceptReview(data.review);
        if (reviewed === null) { cartReviewUnresolved = true; persistCart(); showCartMessage('Held sale remains unresolved. Requested lines are preserved; review again before checkout.', 'error'); return; }
        cart = reviewed; cartReviewUnresolved = false; renderCart();
        showCartMessage('Held sale resumed. Complete checkout or discard it with a reason to finish it.', 'success'); skuInput.focus();
    } catch (error) { showCartMessage(error.message, 'error'); } finally { cartWorkspace.busy = false; }
}

// Ticket #91: a held sale cannot be dropped from the list. It has to be either
// paid for — which completes it — or discarded with a reason, which is recorded.
// The note is only demanded for the one reason that genuinely needs words, and
// the service refuses an empty one there, so this is a courtesy rather than the
// rule: posting around it would simply be rejected.
function openDiscardModal(id) {
    if (!cartChangesAllowed()) return;
    discardingHeldSaleId = Number(id) || 0;
    discardReason.value = 'customer_cancelled';
    discardNote.value = '';
    RetailMindUI.openOverlay(discardModal);
    setTimeout(() => discardReason.focus(), 50);
}

function closeDiscardModal() {
    RetailMindUI.closeOverlay(discardModal);
    discardingHeldSaleId = 0;
    holdSaleButton.focus();
}

async function confirmDiscardHeldSale() {
    if (!cartChangesAllowed()) return;
    const reason = discardReason.value;
    const note = discardNote.value.trim();
    if (reason === 'other' && note === '') {
        showCartMessage('Add a note explaining why this sale is being cleared.', 'error');
        discardNote.focus();
        return;
    }

    try {
        cartWorkspace.busy = true;
        const data = await apiHeldSale(discardingHeldSaleId ? 'discard' : 'discard_cart', {id: discardingHeldSaleId, cart, discard_reason: reason, discard_note: note});
        heldSales = data.held_sales || [];
        // Discarding the cart currently on the till leaves nothing to complete.
        if (resumedHeldSaleId === discardingHeldSaleId) {
            cart = {}; cartReviewUnresolved = false;
            resumedHeldSaleId = 0;
            resumedHeldSaleInput.value = '';
            persistCart();
        }
        closeDiscardModal();
        renderCart(); renderHeldSales();
        showCartMessage('Sale cleared.', 'success');
    } catch (error) {
        showCartMessage(error.message, 'error');
    } finally { cartWorkspace.busy = false; }
}

function renderHeldSales() {
    document.getElementById('held-count').textContent = heldSales.length;
    if (heldSales.length === 0) { holdList.innerHTML = '<div class="search-empty u-empty-min-72">No held sales.</div>'; return; }
    holdList.innerHTML = heldSales.map(held => {
        const id = Number(held.id);
        // A resumed cart is already on the till but is not finished: it still has
        // to be paid for, and it still holds this shift open. Saying so is the
        // difference between "held" and "done".
        const state = held.status === 'resumed' ? ' &middot; on the till' : '';
        return `<div class="hold-item"><div><strong>${escapeHtml(held.reference_no || held.created_at)}</strong><br><small>${Number(held.item_count || 0)} item(s) &middot; &#8369;${money(held.total_amount)}${state}</small></div><div class="pos-toolbar"><button type="button" class="btn btn-small" data-resume-held-sale="${id}">Resume</button><button type="button" class="btn btn-small btn-secondary" onclick="openDiscardModal(${id})">Discard</button></div></div>`;
    }).join('');
}

function voidCurrentSale() {
    openDiscardModal(resumedHeldSaleId);
}

function closeVoidModal() {
    RetailMindUI.closeOverlay(voidModal);
    voidSaleButton.focus();
}

function confirmVoidSale() {
    const reason = voidReason.value.trim();
    if (reason === '') {
        showCartMessage('Enter a reason before voiding the sale.', 'error');
        voidReason.focus();
        return;
    }

    cart = {};
    resetPaymentState();
    persistCart();
    document.getElementById('void-reason-input').value = reason;
    document.getElementById('void-form').submit();
}

function persistCart() {
    cartWorkspace.save(cart, resumedHeldSaleId, cartReviewUnresolved);
}

function restoreState() {
    const saved = cartWorkspace.state;
    if (saved) {
        cart = saved.cart || {};
        resumedHeldSaleId = Number(saved.heldSaleId) || 0;
        cartReviewUnresolved = !!saved.unresolved;
    }
    resumedHeldSaleInput.value = resumedHeldSaleId ? String(resumedHeldSaleId) : '';
    if (typeof syncAllCatalogStock === 'function') syncAllCatalogStock();
    renderCart();
    loadHeldSales();
    if (Object.keys(cart).length && performance.getEntriesByType('navigation')[0]?.type === 'reload') {
        showCartMessage('Sale restored. Check stock and prices before checkout.', 'info');
    }
}

async function reviewCurrentCart() {
    const reviewed = await cartWorkspace.review(cart);
    if (reviewed === null) {
        cartReviewUnresolved = true;
        persistCart();
        showCartMessage('Cart changes remain unresolved. Requested lines are preserved; review again before checkout.', 'error');
        return false;
    }
    cart = reviewed;
    cartReviewUnresolved = false;
    renderCart();
    return Object.keys(cart).length > 0;
}

function onScanSuccess(decodedText) {
    if (scanCooldown) {
        return;
    }
    scanCooldown = true;
    setTimeout(() => { scanCooldown = false; }, 700);

    const code = String(decodedText).trim();
    const now = Date.now();
    if (code === lastScannedCode && now - lastScannedAt < 1200) {
        return;
    }
    lastScannedCode = code;
    lastScannedAt = now;
    scannerResult.innerHTML = `<strong>Barcode detected</strong><span>${escapeHtml(code)}</span>`;
    if (navigator.vibrate) {
        navigator.vibrate(100);
    }
    queueBarcode(code);
}

function showCameraStartFailure(hint, detail) {
    startBtn.disabled = false;
    stopBtn.disabled = true;
    scannerResult.innerHTML = '<strong>Camera could not start</strong><span>' + escapeHtml(hint) + '</span>';
    if (detail !== undefined) {
        console.error('Camera start failed:', detail);
    }
    if (window.RetailMindUI && typeof window.RetailMindUI.toast === 'function') {
        const easy = 'The camera could not start. Check your camera permission, or enter the code manually to continue. Tell your Administrator if this keeps happening.';
        const tech = window.RetailMindUI.isDebug() && detail !== undefined ? '\n' + String(detail) : '';
        showCartMessage(easy + tech, 'error');
    }
}

function startScanner() {
    scannerArea.classList.add('open');
    if (!html5QrCode) {
        showCameraStartFailure('The scanner library could not be loaded. Enter the code manually instead.', 'Html5Qrcode library is unavailable');
        return;
    }
    const localHost = ['localhost', '127.0.0.1', '::1'].includes(location.hostname);
    if (!window.isSecureContext && !localHost) {
        showCameraStartFailure('Open this page over HTTPS, or enter the code manually.', 'Camera requires a secure context (HTTPS or localhost)');
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showCameraStartFailure('This browser does not allow camera scanning. Enter the code manually.', 'navigator.mediaDevices.getUserMedia is unavailable');
        return;
    }
    if (scannerActive) {
        return;
    }

    startBtn.disabled = true;
    scannerResult.innerHTML = '<strong>Starting camera</strong><span>Allow camera access when prompted.</span>';
    html5QrCode.start({ facingMode: 'environment' }, scannerConfig, onScanSuccess, () => {})
        .then(() => {
            scannerActive = true;
            stopBtn.disabled = false;
            scannerResult.innerHTML = '<strong>Camera active</strong><span>Place a barcode inside the frame.</span>';
        })
        .catch(error => {
            showCameraStartFailure('Check camera permission, or type the code manually.', error);
        });
}

function stopScanner() {
    if (!html5QrCode || !scannerActive) {
        scannerArea.classList.remove('open');
        return;
    }
    html5QrCode.stop()
        .then(() => {
            scannerActive = false;
            startBtn.disabled = false;
            stopBtn.disabled = true;
            scannerArea.classList.remove('open');
            scannerResult.innerHTML = '<strong>Camera stopped</strong><span>Use the camera button to scan again.</span>';
            skuInput.focus();
        })
        .catch(() => {
            scannerResult.innerHTML = '<strong>Unable to stop camera</strong><span>Refresh the page if the camera remains active.</span>';
        });
}

function updateClock() {
    if (!cashierClock) {
        return;
    }
    const now = new Date();
    cashierClock.innerHTML = `<i class="bi bi-clock" aria-hidden="true"></i>${now.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' })} Philippine time`;
}

startBtn.addEventListener('click', startScanner);
stopBtn.addEventListener('click', stopScanner);
addCodeBtn.addEventListener('click', addCodeFromInput);
clearCartButton.addEventListener('click', clearCart);
checkoutButton.addEventListener('click', checkoutNow);
confirmCheckoutButton.addEventListener('click', submitConfirmedCheckout);
holdSaleButton.addEventListener('click', holdCurrentSale);
document.getElementById('confirm-discard-sale').addEventListener('click', confirmDiscardHeldSale);
holdList.addEventListener('click', event => {
    const button = event.target.closest('[data-resume-held-sale]');
    if (button) resumeHeldSale(Number(button.dataset.resumeHeldSale));
});
paymentMethod.addEventListener('change', updatePaymentFields);
cashReceived.addEventListener('input', updatePaymentFields);
checkoutSummary.addEventListener('click', event => {
    const button = event.target.closest('[data-cash-amount]');
    if (!button) return;
    document.getElementById('review-cash').value = money(button.dataset.cashAmount === 'exact' ? checkoutQuote.reviewed.total : button.dataset.cashAmount);
    updateReviewedPayment();
});
discountType.addEventListener('change', updatePaymentFields);
discountValue.addEventListener('input', updatePaymentFields);
cashQuick.addEventListener('click', event => {
    const button = event.target.closest('[data-tender]');
    if (button) {
        setQuickTender(button.dataset.tender === 'exact' ? getNetTotal() : button.dataset.tender);
    }
});
const refreshCatalogBtn = document.getElementById('refresh-catalog');
if (refreshCatalogBtn) {
    refreshCatalogBtn.addEventListener('click', async () => {
        try {
            refreshCatalogBtn.disabled = true;
            await syncCatalog(true);
        } catch (e) {
            showCartMessage('Could not reach server to refresh catalog.', 'warning');
        } finally {
            refreshCatalogBtn.disabled = false;
        }
    });
}
let quickSearchTimer = null;
skuInput.addEventListener('input', () => {
    clearTimeout(quickSearchTimer);
    quickSearchTimer = setTimeout(renderQuickProducts, 80);
});
quickCategoryFilters.addEventListener('click', event => {
    const button = event.target.closest('[data-quick-category]');
    if (!button) return;
    quickCategoryFilters.querySelectorAll('[data-quick-category]').forEach(item => {
        item.classList.toggle('active', item === button);
        item.setAttribute('aria-pressed', String(item === button));
    });
    renderQuickProducts();
});
quickProductGrid.addEventListener('click', event => {
    const addButton = event.target.closest('[data-quick-add]');
    if (addButton && !addButton.disabled) {
        addQuickProduct(addButton.dataset.quickAdd, addButton.dataset.sellingForm || 'piece');
        return;
    }
    const decreaseButton = event.target.closest('[data-quick-decrease]');
    if (decreaseButton) {
        changeCartQty(decreaseButton.dataset.quickDecrease, -1);
    }
});
quickGridToggle.addEventListener('click', () => {
    const compact = quickProductGrid.classList.toggle('compact');
    quickGridToggle.setAttribute('aria-pressed', compact ? 'true' : 'false');
});
document.querySelectorAll('[data-pos-focus]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('[data-pos-focus]').forEach(item => item.classList.toggle('active', item === button));
        const target = button.dataset.posFocus;
        if (target === 'scan') {
            skuInput.focus();
            skuInput.select();
        } else if (target === 'find') {
            RetailMindUI.navigate(findProductUrl);
        } else if (target === 'cash') {
            paymentMethod.value = 'cash';
            updatePaymentFields();
            cashReceived.focus();
        } else if (target === 'discount') {
            document.getElementById('discount-panel').open = true;
            discountType.focus();
        } else if (target === 'hold') {
            holdCurrentSale();
        }
    });
});
function setFullscreenMode(enabled) {
    document.body.classList.toggle('pos-fullscreen', enabled);
    localStorage.setItem('retailmind_pos_fullscreen', enabled ? '1' : '0');
    if (fullscreenToggle) {
        fullscreenToggle.querySelector('i').className = `bi ${enabled ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen'}`;
        fullscreenToggle.querySelector('span').textContent = enabled ? 'Exit full screen' : 'Full screen';
    }
    setTimeout(() => skuInput.focus(), 50);
}
if (fullscreenToggle) {
    fullscreenToggle.addEventListener('click', () => setFullscreenMode(!document.body.classList.contains('pos-fullscreen')));
    setFullscreenMode(localStorage.getItem('retailmind_pos_fullscreen') === '1');
}

skuInput.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.isComposing) {
        event.preventDefault();
        if (!event.repeat) addCodeFromInput();
    }
});

// Keyboard-wedge scanners end a rapid burst with Enter; editable fields keep their normal input.
let keyboardBarcode = '';
let keyboardBarcodeAt = 0;
function resetKeyboardBarcode() {
    keyboardBarcode = '';
    keyboardBarcodeAt = 0;
}
document.addEventListener('keydown', event => {
    const editing = event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]');
    const dialogOpen = document.querySelector('dialog[open], .checkout-modal.open, [aria-modal="true"].open, .swal2-container');
    if (event.defaultPrevented || event.isComposing || event.repeat || event.ctrlKey || event.metaKey || event.altKey
        || document.hidden || editing || dialogOpen) {
        resetKeyboardBarcode();
        return;
    }
    const now = performance.now();
    // shortcut: scanners must send characters within 80ms; tune against hardware if slower scanners are introduced.
    if (now - keyboardBarcodeAt > 80) resetKeyboardBarcode();
    if (event.key === 'Enter') {
        const code = keyboardBarcode;
        resetKeyboardBarcode();
        if (code.length >= 3) {
            event.preventDefault();
            event.stopImmediatePropagation();
            queueBarcode(code);
        }
    } else if (event.key.length === 1 && /^[\x21-\x7E]$/.test(event.key)) {
        keyboardBarcode += event.key;
        keyboardBarcodeAt = now;
        if (keyboardBarcode.length > 255) resetKeyboardBarcode();
    } else if (event.key !== 'Shift') {
        resetKeyboardBarcode();
    }
}, true);
window.addEventListener('blur', resetKeyboardBarcode);
document.addEventListener('visibilitychange', resetKeyboardBarcode);

checkoutForm.addEventListener('submit', event => {
    if (!checkoutConfirmed) {
        event.preventDefault();
        checkoutNow();
    }
});

[checkoutModal, voidModal, discardModal, stockConflictModal].filter(Boolean).forEach(modal => {
    modal.addEventListener('mousedown', event => {
        if (event.target === modal) {
            if (modal === checkoutModal) { closeCheckoutConfirm(); }
            else if (modal === voidModal) { closeVoidModal(); }
            else if (modal === discardModal) { closeDiscardModal(); }
            else if (modal === stockConflictModal) { closeStockConflictModal(); }
        }
    });
});

document.addEventListener('keydown', event => {
    if (event.defaultPrevented) return;
    if (event.key === 'F2') {
        event.preventDefault();
        skuInput.focus();
        skuInput.select();
        return;
    }
    if (event.key === 'F3') {
        event.preventDefault();
        RetailMindUI.navigate(findProductUrl);
        return;
    }
    if (event.key === 'F4') {
        event.preventDefault();
        holdCurrentSale();
        return;
    }
    if (event.key === 'Escape') {
        if (checkoutModal.classList.contains('open')) {
            closeCheckoutConfirm();
        } else if (voidModal.classList.contains('open')) {
            closeVoidModal();
        } else if (discardModal.classList.contains('open')) {
            closeDiscardModal();
        } else if (stockConflictModal && stockConflictModal.classList.contains('open')) {
            closeStockConflictModal();
        }
        return;
    }
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        checkoutNow();
    }
});

renderQuickProducts();
restoreState();
initPosCatalogDb(typeof posCatalogPreload !== 'undefined' ? posCatalogPreload : []).then(() => {
    startCatalogSyncLoop();
});
if (cashierClock) {
    updateClock();
    setInterval(updateClock, 30000);
}
const checkoutAttempt = new CheckoutAttempt(checkoutForm, <?= json_encode((string)$cashierId) ?>, {
    recoverUrl: <?= json_encode(app_url('components/cashier/pos.php')) ?>,
    restore(savedCart) { cart = savedCart; resumedHeldSaleId = Number(resumedHeldSaleInput.value) || null; renderCart(); },
    message(text, type) {
        document.getElementById('checkout-recovery').hidden = !checkoutAttempt.pending;
        showCartMessage(text, type);
    },
    cancelled() {
        document.getElementById('checkout-recovery').hidden = true;
        checkoutQuote.reviewed = null;
        updatePaymentFields();
        showCartMessage('Unsuccessful checkout cancelled. You can edit or clear the cart.', 'info');
    }
});
const checkoutQuote = new CheckoutQuote(checkoutForm);
document.getElementById('cancel-checkout-btn').addEventListener('click', async event => {
    if (cartWorkspace.busy || checkoutSubmitting) return;
    const button = event.currentTarget;
    button.disabled = true;
    cartWorkspace.busy = true;
    try {
        if (!await RetailMindUI.confirm({title: 'Cancel unsuccessful checkout?',
            message: 'Only cancel if no payment was collected, or you have returned the cash or reversed the external payment. Cancelling does not return money. A saved sale will open its receipt instead.',
            confirmText: 'No payment outstanding'})) return;
        if (await checkoutAttempt.cancel()) document.getElementById('clear-cart-btn').focus();
    } catch (error) {
        showCartMessage(error.message || 'Cancellation could not be confirmed. Reload to recover the checkout.', 'error');
    } finally {
        button.disabled = false;
        cartWorkspace.busy = false;
    }
});
checkoutAttempt.recover().then(async () => {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('new_sale')) return;
    url.searchParams.delete('new_sale');
    history.replaceState(null, '', url);
    if (!checkoutAttempt.ready || checkoutAttempt.pending) return;
    try {
        if (!await cartWorkspace.resolveWork()) return;
        cart = {};
        cartWorkspace.clear();
        cartReviewUnresolved = false;
        resetPaymentState();
        renderCart();
        await loadHeldSales();
    } catch (error) { showCartMessage('Unfinished work is preserved. ' + error.message, 'error'); }
});
setTimeout(() => { if (!document.getElementById('receiptModal')?.open) skuInput.focus(); }, 100);
</script>
        <?php // Ticket #90: the point of sale and its scripts are withheld entirely while the Register is locked, so a locked till has no cart, no scanner, and no checkout to drive. ?>
        <?php endif; ?>
</body>
</html>
