<?php
// cashier/stock_receiving.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/product_images.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/includes/receiving_product_handoff.php';
require_once __DIR__ . '/../../../backend/app/Services/ReceivingService.php';
require_once __DIR__ . '/../../../backend/app/Services/SupplierPulloutService.php';
header('Cache-Control: no-store');
require_role(['admin', 'super_admin', 'inventory_manager']);

$receivingService = new ReceivingService($pdo);

$message = '';
$error = '';
$canReceive = has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
$formValues = [];
$prefillProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$prefillPulloutId = isset($_GET['pullout_id']) ? (int)$_GET['pullout_id'] : 0;
$handoffId = $_GET['handoff'] ?? null;
if (isset($_POST['product_handoff_action'])) {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
    csrf_verify();
    header('Content-Type: application/json');
    $id = $_POST['handoff_id'] ?? null;
    $tab = $_POST['handoff_tab'] ?? null;
    $handoff = receiving_product_handoff($id);
    $action = $_POST['product_handoff_action'];
    if ($action === 'start' && !$handoff && is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id)
        && is_string($tab) && preg_match('/^[a-f0-9]{64}$/D', $tab)) {
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        $mode = $_POST['packaging'] ?? '';
        if (in_array($mode, ['units', 'packages', 'packs'], true) && $code !== '' && strlen($code) <= ($mode === 'packages' ? 80 : 50)) {
            $_SESSION['receiving_product_handoffs'] = array_filter($_SESSION['receiving_product_handoffs'] ?? [],
                static fn(array $entry): bool => $entry['expires'] >= time());
            $_SESSION['receiving_product_handoffs'][$id] = [
                'user' => (int)$_SESSION['user_id'], 'tab' => hash('sha256', $tab), 'expires' => time() + 86400,
                'code' => $code, 'mode' => $mode, 'pullout_id' => $prefillPulloutId, 'state' => 'editing', 'product' => null,
            ];
            echo json_encode(['url' => app_url('components/inventory_management/products.php') . '?handoff=' . $id]);
            exit;
        }
    } elseif ($handoff && receiving_product_handoff_owns_tab($handoff, $tab)) {
        if ($action === 'return') {
            if ($handoff['state'] === 'editing') {
                $_SESSION['receiving_product_handoffs'][$id]['state'] = 'returned';
                $handoff['state'] = 'returned';
            }
            echo json_encode(['state' => $handoff['state'], 'product' => $handoff['product'], 'url' => receiving_product_return_url($id, $handoff)]);
            exit;
        }
        if ($action === 'edit' && $handoff['state'] === 'editing') {
            echo json_encode(['code' => $handoff['code'], 'mode' => $handoff['mode'], 'retry' => $handoff['retry'] ?? null]);
            exit;
        }
        if ($action === 'consume' && $handoff['state'] !== 'editing') {
            $_SESSION['receiving_product_handoffs'][$id]['state'] = 'consumed';
            echo json_encode(['state' => 'consumed']);
            exit;
        }
    }
    http_response_code(409);
    echo json_encode(['error' => 'This Add Product link is unavailable. Open Stock Receiving in the original tab and resume the saved delivery.']);
    exit;
}
$returnHandoff = $canReceive && $handoffId !== null ? receiving_product_handoff($handoffId) : null;
if ($returnHandoff) $prefillPulloutId = $returnHandoff['pullout_id'];
$retainedDraftId = $_POST['inventory_draft_id'] ?? null;
$retainedDraftId = is_string($retainedDraftId) && preg_match('/^receiving-[a-f0-9]{32}$/D', $retainedDraftId) ? $retainedDraftId : null;
$linkedPullout = null;
if ($prefillPulloutId > 0) {
    try {
        $linkedPullout = (new SupplierPulloutService($pdo))->getPullout($prefillPulloutId);
        if ($linkedPullout && $prefillProductId <= 0) {
            $prefillProductId = (int)$linkedPullout['product_id'];
        }
    } catch (Throwable) {
        $linkedPullout = null;
    }
}
if ($handoffId === null && $prefillProductId > 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $initialLine = ['product_id' => (string)$prefillProductId];
    if ($prefillPulloutId > 0) {
        $initialLine['pullout_id'] = (string)$prefillPulloutId;
    }
    $formValues = [
        'lines' => [$initialLine],
        'supplier' => $linkedPullout['supplier'] ?? '',
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $previewCosts = ($_POST['preview_costs'] ?? '') === '1';

    try {
        $nonce = $_POST['receiving_nonce'] ?? '';
        if (empty($_POST['operation_token']) && (!is_string($nonce) || !isset($_SESSION['receiving_nonces'][$nonce]))) {
            throw new RuntimeException('This receiving form has already been saved or expired. Review a new entry before saving.');
        }
        $entry = [
            'operation_token' => $_POST['operation_token'] ?? '',
            'supplier' => $_POST['supplier'] ?? '',
            'invoice_number' => $_POST['invoice_number'] ?? '',
        ];
        if (isset($_POST['entry_mode']) || array_key_exists('lines', $_POST)) {
            if (($_POST['receiving_complete'] ?? '') !== '1') {
                throw new RuntimeException('The receiving entry is incomplete. Nothing was saved. Split it into smaller entries and try again.');
            }
            $lines = $_POST['lines'] ?? [];
            if (!is_array($lines)) throw new RuntimeException('Product lines must be a list of receiving details.');
        } else {
            $lines = [[
            'product_id' => $_POST['product_id'] ?? 0,
            'quantity_mode' => $_POST['quantity_mode'] ?? 'units',
            'received_qty' => $_POST['received_qty'] ?? 0,
            'received_packages' => $_POST['received_packages'] ?? 0,
            'damaged_qty' => $_POST['damaged_qty'] ?? 0,
            'received_packs' => $_POST['received_packs'] ?? null,
            'accepted_packs' => $_POST['accepted_packs'] ?? null,
            'damaged_packs' => $_POST['damaged_packs'] ?? null,
            'rejected_packs' => $_POST['rejected_packs'] ?? null,
            'received_loose' => $_POST['received_loose'] ?? null,
            'accepted_loose' => $_POST['accepted_loose'] ?? null,
            'damaged_loose' => $_POST['damaged_loose'] ?? null,
            'rejected_loose' => $_POST['rejected_loose'] ?? null,
            'cost_price' => $_POST['cost_price'] ?? '',
            'cost_basis' => $_POST['cost_basis'] ?? 'units',
            'supplier' => $_POST['supplier'] ?? '',
            'invoice_number' => $_POST['invoice_number'] ?? '',
            'batch_number' => $_POST['batch_number'] ?? '',
            'expiration_date' => $_POST['expiration_date'] ?? '',
            'discrepancy_type' => $_POST['discrepancy_type'] ?? 'none',
            'discrepancy_qty' => $_POST['discrepancy_qty'] ?? 0,
            'discrepancy_notes' => $_POST['discrepancy_notes'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'pullout_id' => $_POST['pullout_id'] ?? ($_POST['lines'][0]['pullout_id'] ?? null),
            ]];
        }
        if ($previewCosts) {
            header('Content-Type: application/json');
            echo json_encode(['costs' => $receivingService->reviewShoppingTrip($lines, (int)$_SESSION['user_id'])], JSON_THROW_ON_ERROR);
            exit;
        }
        $results = $receivingService->receiveShoppingTrip($lines, $entry, (int)$_SESSION['user_id']);


        unset($_SESSION['receiving_nonces'][$nonce]);
        $summaries = [];
        foreach ($results as $result) {
            $summary = "{$result['product_name']}: {$result['accepted_qty']} accepted units";
            if ($result['damaged_qty'] > 0) $summary .= ", {$result['damaged_qty']} damaged units excluded";
            $summaries[] = $summary;
        }
        $message = 'Receiving entry saved. ' . implode('; ', $summaries)
            . ". Recorded by {$results[0]['recorded_by']} at {$results[0]['received_at']}.";
        try {
            log_activity($pdo, $_SESSION['user_id'], 'Received shopping entry: ' . implode('; ', $summaries));
        } catch (Throwable $e) {
            error_log('Receiving activity log failed: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        if ($previewCosts) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['error' => \App\Support\OperatorAlert::message($e, 'Purchase costs could not be checked. Try Review again.')]);
            exit;
        }
        $formValues = $_POST;
        if (isset($formValues['lines']) && is_array($formValues['lines'])) {
            $formValues['lines'] = array_values($formValues['lines']);
        }
        $error = \App\Support\OperatorAlert::message($e, 'The receiving record could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.');
    }
}

$receivingNonce = bin2hex(random_bytes(24));
$_SESSION['receiving_nonces'][$receivingNonce] = true;
$_SESSION['receiving_nonces'] = array_slice($_SESSION['receiving_nonces'], -20, null, true);

$products = $receivingService->getActiveProducts();
foreach ($products as &$product) {
    $product['product_image'] = product_image_url($product['product_image'] ?? null);
}
unset($product);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['receiving_catalog'] ?? '') === '1') {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
    header('Content-Type: application/json');
    echo json_encode(['products' => $products]);
    exit;
}

$categories = [];
foreach ($products as $p) {
    $catId = (int)($p['category_id'] ?? 0);
    $catName = trim((string)($p['category_name'] ?? ''));
    if ($catId <= 0 || $catName === '') {
        continue;
    }
    if (!isset($categories[$catId])) {
        $categories[$catId] = [
            'id' => $catId,
            'name' => $catName,
            'count' => 0,
        ];
    }
    $categories[$catId]['count']++;
}
uasort($categories, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

$categoryIcon = static function (string $categoryName): string {
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
    <title>Stock Receiving</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/stock-receiving.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/stock-receiving.css')) ?>">
<?php require __DIR__ . '/../../../backend/includes/inventory_drafts.php'; ?>
</head>
<body class="stock-receiving-page">
<a class="receiving-skip-link" href="#receiving_main">Skip to Stock Receiving</a>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="receiving_main" tabindex="-1">
        <h1 class="u-sr-only">Stock Receiving</h1>
        <?php if ($message): ?>
            <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><span role="alert"><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <?php if ($linkedPullout): ?>
            <div class="message info pullout-link-notice" role="note">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                <?php
                $linkedBreakdown = SupplierPulloutService::formatFormBreakdown((int)($linkedPullout['pulled_packs'] ?? 0), (int)($linkedPullout['pulled_loose'] ?? 0), (int)($linkedPullout['pack_size_used'] ?? ($linkedPullout['pack_size'] ?? 1)));
                $linkedFormSummary = $linkedBreakdown !== '' ? " [{$linkedBreakdown}]" : '';
                ?>
                <strong>Replacement Receiving:</strong> Linked to Supplier Pullout #<?= (int)$linkedPullout['pullout_id'] ?>
                (<?= htmlspecialchars($linkedPullout['product_name']) ?>, <?= (int)$linkedPullout['quantity'] ?> units<?= htmlspecialchars($linkedFormSummary) ?> pulled on <?= htmlspecialchars(substr((string)$linkedPullout['pulled_at'], 0, 10)) ?><?= !empty($linkedPullout['supplier']) ? ' from ' . htmlspecialchars($linkedPullout['supplier']) : '' ?>).
                Fresh goods will be saved in a new batch.
            </div>
        <?php endif; ?>

        <?php if ($canReceive && $handoffId !== null): ?>
            <div id="handoff_recovery" class="message info" role="note">
                <p id="handoff_status" role="alert" tabindex="-1">Restoring your delivery…</p>
                <button type="button" class="btn btn-secondary" id="handoff_retry" hidden>Retry restoration</button>
                <a data-no-navigation href="<?= htmlspecialchars(app_url('components/report/stock_receiving.php')) ?>">Open Stock Receiving</a>
            </div>
        <?php endif; ?>
        <div class="receiving-form" data-step="1" <?= !$canReceive ? 'data-readonly="true"' : '' ?> <?= $canReceive && $handoffId !== null ? 'inert' : '' ?>>
            <h2 id="receiving_step_title" class="receiving-step-title" tabindex="-1">Receive stock</h2>
            <?php if (!$canReceive): ?><p>Switch to an authorized Inventory Manager workspace to record stock.</p><?php endif; ?>
            <noscript><p role="alert">Enable JavaScript to select products, review quantities, and save this receiving entry.</p></noscript>
            <fieldset class="receiving-fieldset-reset" <?= !$canReceive ? 'disabled' : '' ?>>
            <div class="form-section" id="receiving_selection">
                <div class="receiving-panel-heading"><div><h2>Product catalog</h2></div><div class="receiving-catalog-actions"><button type="button" class="btn btn-secondary" id="receiving_help_open" aria-haspopup="dialog">How to receive stock</button><span class="receiving-count"><?= count($products) ?> products</span></div></div>
                <div class="receiving-search-bar form-group">
                    <label for="product_search_scan">Scan barcode or search by name or SKU</label>
                    <div class="receiving-search-group">
                        <input type="search" id="product_search_scan" autocomplete="off" aria-describedby="scan_status product_search_status" placeholder="Scan barcode or search by name, SKU or barcode">
                        <button type="button" class="btn btn-secondary" id="add_scanned_item">Add to delivery</button>
                    </div>
                    <small id="scan_status" role="status" aria-live="polite"></small>
                    <section id="receiving_code_recovery" class="receiving-code-recovery" aria-label="Resolve scanned code" hidden>
                        <p id="scan_recovery_message" tabindex="-1"></p>
                        <fieldset id="scan_classification" class="receiving-fieldset-reset">
                            <legend>Which packaging did you scan?</legend>
                            <label><input type="radio" name="scan_packaging" value="units"> Individual item</label>
                            <label><input type="radio" name="scan_packaging" value="packages"> Receiving case</label>
                            <label><input type="radio" name="scan_packaging" value="packs"> Sales Pack</label>
                            <p id="scan_packaging_hint"></p>
                        </fieldset>
                        <div id="scan_package_confirmation" hidden>
                            <p id="scan_saved_size"></p>
                            <button type="button" class="btn btn-secondary" id="scan_confirm_size">Confirm saved size</button>
                            <button type="button" class="btn btn-secondary" id="scan_use_units">Enter actual base units</button>
                            <a id="scan_correct_config" class="btn btn-secondary" target="_blank" rel="noopener" href="../inventory_management/products.php">Correct product configuration</a>
                        </div>
                        <div class="receiving-code-actions">
                            <button type="button" class="btn btn-secondary" id="scan_retry">Retry lookup</button>
                            <button type="button" class="btn btn-secondary" id="scan_find_existing">Find existing product</button>
                            <button type="button" class="btn" id="scan_create_product" hidden>Create product</button>
                            <button type="button" class="btn btn-secondary" id="scan_back">Back</button>
                            <button type="button" class="btn btn-secondary" id="scan_cancel">Cancel</button>
                            <a id="scan_manage_product" href="../inventory_management/products.php" hidden>Review inactive products</a>
                        </div>
                    </section>
                    <small id="product_search_status" role="status"></small>
                </div>
                <div class="receiving-category-filters" id="receiving_category_filters" role="group" aria-label="Product categories">
                    <button type="button" class="receiving-category-tile active" aria-pressed="true" data-category="all">
                        <span class="receiving-category-icon"><i class="bi bi-grid" aria-hidden="true"></i></span>
                        <strong>All</strong>
                        <small><?= count($products) ?> products</small>
                    </button>
                    <?php foreach ($categories as $catId => $cat): ?>
                        <button type="button" class="receiving-category-tile" aria-pressed="false" data-category="<?= (int)$catId ?>">
                            <span class="receiving-category-icon"><i class="bi <?= htmlspecialchars($categoryIcon($cat['name'])) ?>" aria-hidden="true"></i></span>
                            <strong><?= htmlspecialchars($cat['name']) ?></strong>
                            <small><?= (int)$cat['count'] ?> product<?= (int)$cat['count'] === 1 ? '' : 's' ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div id="receiving_catalog" class="receiving-catalog" aria-label="Available products"></div>
            </div>
            </fieldset>
            <form method="POST" id="receiving_form" novalidate
                <?= $error && $retainedDraftId ? 'data-inventory-draft-id="' . $retainedDraftId . '"' : '' ?>
                <?= $handoffId !== null ? 'action="' . htmlspecialchars(app_url('components/report/stock_receiving.php') . ($prefillPulloutId > 0 ? '?pullout_id=' . $prefillPulloutId : '')) . '"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="receiving_nonce" value="<?= htmlspecialchars($receivingNonce) ?>">
                <input type="hidden" name="entry_mode" value="shopping_trip">
                <div class="delivery-editor">
                <div class="receiving-panel-heading"><div><h2 id="delivery_title">Delivery lines</h2></div><div class="receiving-delivery-actions" id="receiving_delivery_actions"><span id="delivery_count" class="receiving-count">0 products</span></div></div>
                <div id="delivery_empty_state" class="delivery-empty-state">
                    <i class="bi bi-box-seam" aria-hidden="true"></i>
                    <p>No products added yet. Scan a barcode or add a product from the catalog.</p>
                </div>
                <fieldset class="receiving-fieldset-reset" <?= !$canReceive ? 'disabled' : '' ?>>
                <div id="receiving_lines"></div>
                </fieldset>
                <template id="receiving_line_template">
                <section class="receiving-line" aria-label="Product line 1" draggable="true">
                    <div class="receiving-line-heading playlist-row-header">
                        <div class="playlist-row-main">
                            <span class="playlist-drag-handle" title="Drag to reorder" aria-hidden="true">
                                <i class="bi bi-grip-vertical"></i>
                            </span>
                            <div class="playlist-row-identity">
                                <div class="playlist-row-title">
                                    <span class="line-number">1</span>
                                    <strong class="receiving-product-name playlist-product-name"></strong>
                                    <span class="receiving-product-sku playlist-product-sku"></span>
                                    <span class="receiving-pullout-badge badge" data-field="pullout_badge" style="display:none; font-size:0.75rem; margin-left:0.5rem; background:#0284c7; color:#fff; border-radius:0.25rem; padding:0.15rem 0.4rem; vertical-align:middle;">
                                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Linked Pullout #<span data-field="pullout_badge_id"></span>
                                    </span>
                                </div>
                                <div class="receiving-product-pack playlist-pack-info"></div>
                            </div>
                        </div>
                        <div class="playlist-row-actions">
                            <button type="button" class="btn btn-quiet move-line-up" aria-label="Move line 1 up">
                                <i class="bi bi-arrow-up" aria-hidden="true"></i> Move up
                            </button>
                            <button type="button" class="btn btn-quiet move-line-down" aria-label="Move line 1 down">
                                <i class="bi bi-arrow-down" aria-hidden="true"></i> Move down
                            </button>
                            <button type="button" class="btn btn-quiet remove-receiving-line" aria-label="Remove product">
                                <i class="bi bi-trash" aria-hidden="true"></i> Remove
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="lines[0][product_id]" id="product_id" data-field="product_id" value="">
                    <input type="hidden" name="lines[0][pullout_id]" id="pullout_id" data-field="pullout_id" value="">
                    <input type="hidden" name="review_conversion" data-field="review_conversion" value="">
                    <input type="hidden" name="quantity_counts" data-field="quantity_counts" value="">

                    <div class="form-section receiving-quantity">
                        <div class="form-grid">
                            <div class="form-group receiving-quantity">
                                <label for="quantity_mode">Quantity type</label>
                                <select name="quantity_mode" id="quantity_mode" data-field="quantity_mode">
                                    <option value="units">Base units</option>
                                    <option value="packages">Packages / cases</option>
                                    <option value="packs">Sealed packs & loose pieces</option>
                                </select>
                            </div>
                            <div class="form-group receiving-quantity" id="units_qty_group" data-field="units_qty_group">
                                <label for="received_qty">Units received</label>
                                <input type="text" inputmode="numeric" pattern="[0-9]+" name="received_qty" id="received_qty" data-field="received_qty" value="">
                            </div>
                            <div class="form-group receiving-quantity" id="packages_qty_group" data-field="packages_qty_group" style="display:none">
                                <label for="received_packages">Packages received</label>
                                <input type="text" inputmode="numeric" pattern="[0-9]+" name="received_packages" id="received_packages" data-field="received_packages" value="">
                            </div>
                            <div class="form-group receiving-quantity" id="packs_qty_group" data-field="packs_qty_group" style="display:none">
                                <label for="received_packs">Sealed packs received</label>
                                <input type="text" inputmode="numeric" pattern="[0-9]+" name="received_packs" id="received_packs" data-field="received_packs" value="">
                            </div>
                            <div class="form-group receiving-quantity" id="loose_qty_group" data-field="loose_qty_group" style="display:none">
                                <label for="received_loose">Loose pieces received</label>
                                <input type="text" inputmode="numeric" pattern="[0-9]+" name="received_loose" id="received_loose" data-field="received_loose" value="0">
                            </div>
                        </div>
                        <small class="receiving-quantity" id="package_conversion" data-field="package_conversion" role="status">Select a product to view conversion.</small>
                    </div>

                    <div class="form-grid receiving-batch-details">
                        <div class="form-group">
                            <label for="batch_number">Batch / lot (optional)</label>
                            <input type="text" name="batch_number" id="batch_number" data-field="batch_number" maxlength="100" placeholder="Batch #">
                        </div>
                        <div class="form-group">
                            <label for="expiration_date">Expiry date (optional)</label>
                            <input type="date" name="expiration_date" id="expiration_date" data-field="expiration_date">
                        </div>
                    </div>
                    <details class="receiving-quantity receiving-extras">
                        <summary>Purchase cost</summary>
                        <div class="form-section">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="cost_basis">Cost basis</label>
                                    <select name="cost_basis" id="cost_basis" data-field="cost_basis"></select>
                                </div>
                                <div class="form-group">
                                    <label for="cost_price">Purchase cost</label>
                                    <input type="hidden" name="cost_fallback" data-field="cost_fallback">
                                    <input type="hidden" name="review_cost_units" data-field="review_cost_units">
                                    <input type="text" inputmode="decimal" name="cost_price" id="cost_price" data-field="cost_price" aria-describedby="cost_hint" placeholder="Uses saved product cost">
                                    <small id="cost_hint" data-field="cost_hint">Per base unit. Blank uses saved cost.</small>
                                    <small data-field="cost_preview" role="status"></small>
                                    <small data-field="cost_change" role="status"></small>
                                </div>
                            </div>
                        </div>
                    </details>
                </section>
                </template>
                <p id="entry_status" role="alert" tabindex="-1"></p>
                <section id="receiving_review" hidden aria-label="Review receiving entry"></section>
                </div>
                <section class="receiving-summary" aria-label="Delivery summary">
                <div class="receiving-panel-heading"><h2>Delivery summary</h2><span id="summary_status" class="receiving-count">Draft</span></div>
                <div class="receiving-summary-body">
                    <div class="receiving-accepted"><strong id="summary_products">0</strong><span>Products</span></div>
                    <dl class="receiving-totals"><div><dt>Accepted units</dt><dd id="summary_accepted" class="receiving-total-accepted">0</dd></div><div><dt id="summary_cost_label">Purchase total</dt><dd id="summary_cost">Not recorded</dd></div></dl>
                    <p class="receiving-summary-note">Only good items are added to stock. Blank costs use the saved product cost, when available.</p>
                <fieldset class="receiving-fieldset-reset" <?= !$canReceive ? 'disabled' : '' ?>>
                <div class="form-section receiving-quantity">
                    <h3>Receipt details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="supplier">Bought from (optional)</label>
                            <input type="text" name="supplier" id="supplier" maxlength="150" placeholder="Where the goods were bought">
                        </div>
                        <div class="form-group">
                            <label for="invoice_number">Receipt reference (optional)</label>
                            <input type="text" name="invoice_number" id="invoice_number" maxlength="100" placeholder="Receipt reference">
                        </div>
                    </div>
                </div>
                </fieldset>

                <div class="u-flex-wrap receiving-actions" id="receiving_actions">
                    <button type="submit" class="btn-submit" <?= !$canReceive ? 'disabled' : '' ?> hidden>Save receiving &amp; update stock</button>
                    <button type="button" class="btn btn-secondary" id="receiving_back" hidden>Add products</button>
                    <button type="button" class="btn btn-primary" id="receiving_next">Review</button>
                </div>
                </div>
                </section>
                <input type="hidden" name="receiving_complete" value="1">
            </form>
        </div>
    </main>
</div>
<dialog class="receiving-help-dialog" id="receiving_help_dialog" aria-labelledby="receiving_help_title">
    <div class="receiving-panel-heading"><h2 id="receiving_help_title">How to receive stock</h2><button type="button" class="btn btn-secondary" id="receiving_help_close">Close</button></div>
    <ol>
        <li><strong>Select products:</strong> scan a barcode, search by name or SKU, or choose a category and add products from the catalog.</li>
        <li><strong>Enter quantities:</strong> choose units, packages, or sealed packs beside each selected product, then enter the good quantities received. Add cost, batch, or expiry when needed.</li>
        <li><strong>Review and save:</strong> check the delivery summary and receipt details, then save the entry to update stock.</li>
    </ol>
</dialog>

<script id="receiving_products_data" type="application/json"><?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script>
let productsCatalog = JSON.parse(document.getElementById('receiving_products_data').textContent);
const canReceive = <?= json_encode((bool)$canReceive) ?>;
const productMap = new Map(productsCatalog.map(p => [String(p.product_id), p]));
const receivingHelpDialog = document.getElementById('receiving_help_dialog');
document.getElementById('receiving_help_open').addEventListener('click', () => receivingHelpDialog.showModal());
document.getElementById('receiving_help_close').addEventListener('click', () => receivingHelpDialog.close());
receivingHelpDialog.addEventListener('click', event => { if (event.target === receivingHelpDialog) receivingHelpDialog.close(); });

function updateExtrasDisclosure(row) {
    const details = row?.querySelector('.receiving-extras');
    if (!details) return;
    const hasValue = Array.from(details.querySelectorAll('input:not([type="hidden"]), textarea')).some(el => el.value.trim() !== '');
    if (hasValue || details.querySelector('[aria-invalid="true"]')) {
        details.open = true;
    }
}
const productSearchScan = document.getElementById('product_search_scan');
const addScannedItem = document.getElementById('add_scanned_item');
const scanStatus = document.getElementById('scan_status');
const productCodeScan = productSearchScan;
const productSearch = productSearchScan;
let activeScanLookup = 0;
let scanLookupController = null;
let pendingScan = null;
const codeRecovery = document.getElementById('receiving_code_recovery');
const scanClassification = document.getElementById('scan_classification');
const scanConfirmation = document.getElementById('scan_package_confirmation');
const scanCreate = document.getElementById('scan_create_product');
const receivingEditor = document.querySelector('.receiving-form');
const handoffRecovery = document.getElementById('handoff_recovery');
const returnHandoffId = <?= json_encode(is_string($handoffId) && preg_match('/^[a-f0-9]{32}$/D', $handoffId) ? $handoffId : null) ?>;
let restoringHandoff = !!handoffRecovery;
let appliedHandoff = '';
function keepScanRecovery() {
    if (!restoringHandoff) window.RetailMindInventoryDrafts?.keep(form);
}
function showScanRecovery(code, outcome, message) {
    pendingScan = {code, outcome, packaging: '', finding: false};
    codeRecovery.hidden = false;
    scanClassification.hidden = outcome !== 'unknown';
    scanCreate.hidden = true;
    scanConfirmation.hidden = true;
    for (const radio of scanClassification.querySelectorAll('input')) radio.checked = false;
    document.getElementById('scan_packaging_hint').textContent = '';
    document.getElementById('scan_recovery_message').textContent = `${code}: ${message}`;
    document.getElementById('scan_manage_product').hidden = outcome !== 'inactive';
    document.getElementById('scan_manage_product').href = '../inventory_management/products.php?q=' + encodeURIComponent(code);
    productSearchScan.value = code;
    renderCatalog();
    document.getElementById('scan_recovery_message').focus();
    keepScanRecovery();
}
scanClassification.addEventListener('change', event => {
    pendingScan = {...pendingScan, productId: null};
    scanConfirmation.hidden = true;
    pendingScan.packaging = event.target.value;
    scanCreate.hidden = !canReceive || pendingScan.outcome !== 'unknown';
    document.getElementById('scan_packaging_hint').textContent = event.target.value === 'packages'
        ? 'Scan the outer carton. Keep the item barcode separate and confirm the actual units per carton.'
        : event.target.value === 'packs' ? 'A Sales Pack needs its own size, barcode and price. If received and sold unchanged, use only its Sales Pack barcode.'
        : 'Use the individual item barcode only.';
    keepScanRecovery();
});
document.getElementById('scan_retry').addEventListener('click', () => identifyReceivingProduct(pendingScan.code));
function receivingHandoff(action, id, details) {
    return RetailMindInventoryDrafts.productHandoff(form.action, form.elements.csrf_token.value, action, id, details);
}
async function loadReceivingCatalog() {
    const response = await fetch('?receiving_catalog=1', {cache: 'no-store', headers: {Accept: 'application/json'}});
    if (!response.ok || response.redirected) throw new Error('Check your connection and try again to refresh the catalog.');
    const {products} = await response.json();
    if (!Array.isArray(products)) throw new Error('Check your connection and try again to refresh the catalog.');
    return products;
}
scanCreate.addEventListener('click', async () => {
    if (pendingScan?.outcome !== 'unknown' || !pendingScan.packaging) return;
    const {code, packaging} = pendingScan;
    if (new TextEncoder().encode(code).length > (packaging === 'packages' ? 80 : 50)) {
        const status = document.getElementById('scan_recovery_message');
        status.textContent = 'This code is too long for the selected barcode field. Check the code and scan again.';
        status.focus();
        return;
    }
    receivingEditor.inert = true;
    let id;
    try {
        id = crypto.randomUUID().replaceAll('-', '');
        window.RetailMindInventoryDrafts?.tabProof(id, true);
        if (!await window.RetailMindInventoryDrafts?.checkpoint(form, 'receiving-' + id)) throw new Error('Draft could not be kept');
    } catch (_) {
        receivingEditor.inert = false;
        const status = document.getElementById('scan_recovery_message');
        status.textContent = 'Delivery could not be saved on this device. Keep this page open, free browser storage or allow site storage, then try again.';
        status.focus();
        return;
    }
    try {
        const result = await receivingHandoff('start', id, {code, packaging});
        if (!form.isConnected) return;
        const original = new URL(location.href);
        original.searchParams.set('handoff', id);
        history.replaceState(history.state, '', original);
        location.assign(result.url);
    } catch (_) {
        receivingEditor.inert = false;
        const status = document.getElementById('scan_recovery_message');
        status.textContent = 'Add Product could not open. Your delivery is saved on this device. Check your connection and try again.';
        status.focus();
    }
});
document.getElementById('scan_find_existing').addEventListener('click', () => {
    pendingScan.finding = true;
    productSearchScan.value = '';
    selectedCategory = 'all';
    renderCatalog();
    productSearchScan.focus();
    keepScanRecovery();
});
document.getElementById('scan_back').addEventListener('click', () => {
    pendingScan = {...pendingScan};
    pendingScan.finding = false;
    pendingScan.productId = null;
    scanConfirmation.hidden = true;
    productSearchScan.value = pendingScan.code;
    renderCatalog();
    document.getElementById('scan_recovery_message').focus();
    keepScanRecovery();
});
document.getElementById('scan_cancel').addEventListener('click', () => {
    productSearchScan.value = pendingScan.code;
    pendingScan = null;
    codeRecovery.hidden = true;
    productSearchScan.focus();
    renderCatalog();
    keepScanRecovery();
});
function addRecoveredProduct(product, mode) {
    const group = productGroup(product.product_id);
    const existing = group?.lastEdited?.isConnected ? group.lastEdited : group?.querySelector('.receiving-line');
    if (!group) productMap.set(String(product.product_id), product);
    const row = existing && existing.querySelector('[data-field="quantity_mode"]').value === mode ? existing
        : addReceivingLine({product_id: product.product_id, quantity_mode: mode}, !!group);
    pendingScan = null;
    codeRecovery.hidden = true;
    showStep(1, false);
    if (row) visibleQuantityInput(row)?.focus();
    scanStatus.textContent = row === existing ? 'Product already added. Entered quantities and mode preserved.'
        : 'Existing product selected. No identifier was attached. Enter actual delivered quantities.';
    keepScanRecovery();
}
function chooseRecoveryProduct(product) {
    pendingScan = {...pendingScan};
    pendingScan.productId = String(product.product_id);
    if (pendingScan.outcome !== 'unknown') {
        // Manual recovery must explicitly select a quantity mode, including legacy overlaps.
        scanConfirmation.hidden = false;
        document.getElementById('scan_saved_size').textContent = `${product.product_name}: choose actual base units, or correct the registered identifiers before retrying.`;
        document.getElementById('scan_confirm_size').hidden = true;
    } else if (pendingScan.packaging === 'units') {
        addRecoveredProduct(product, 'units');
        return;
    } else {
        scanConfirmation.hidden = false;
        const size = Number(pendingScan.packaging === 'packs' ? product.pack_size : product.units_per_package);
        pendingScan.size = size;
        pendingScan.packBarcode = product.pack_barcode;
        pendingScan.packPrice = Number(product.pack_price);
        const valid = Number.isInteger(size) && size > 0 && (pendingScan.packaging !== 'packs' || (size > 1 && product.pack_barcode && Number(product.pack_price) > 0));
        document.getElementById('scan_saved_size').textContent = `${product.product_name}: saved ${pendingScan.packaging === 'packs' ? 'Sales Pack' : 'carton'} size is ${size || 'not configured'} base units. Does this match the scanned packaging?`;
        document.getElementById('scan_confirm_size').hidden = !valid;
    }
    document.getElementById('scan_correct_config').href = '../inventory_management/products.php?q=' + encodeURIComponent(product.sku);
    document.getElementById('scan_use_units').focus();
    keepScanRecovery();
}
document.getElementById('scan_use_units').addEventListener('click', () => {
    const product = productMap.get(pendingScan.productId);
    if (product) addRecoveredProduct(product, 'units');
});
document.getElementById('scan_confirm_size').addEventListener('click', async () => {
    const scan = pendingScan;
    const button = document.getElementById('scan_confirm_size');
    button.disabled = true;
    try {
        const products = await loadReceivingCatalog();
        if (receivingEditor.inert || pendingScan !== scan) return;
        const product = products.find(item => String(item.product_id) === scan.productId);
        if (!product) throw new Error('Product unavailable');
        const key = scan.packaging === 'packs' ? 'pack_size' : 'units_per_package';
        if (scan.size !== Number(product[key]) || (scan.packaging === 'packs' && (product.pack_barcode !== scan.packBarcode || Number(product.pack_price) !== scan.packPrice))) {
            chooseRecoveryProduct(product);
            return;
        }
        addRecoveredProduct(product, scan.packaging);
    } catch (_) {
        if (receivingEditor.inert || pendingScan !== scan) return;
        document.getElementById('scan_saved_size').textContent = 'Current product could not be checked. Code and delivery kept. Retry lookup or find the product again.';
        document.getElementById('scan_retry').focus();
    } finally { button.disabled = false; }
});
const lineContainer = document.getElementById('receiving_lines');
const lineTemplate = document.getElementById('receiving_line_template');
let activeLine = null;
let draggedRow = null;

for (const label of lineTemplate.content.querySelectorAll('label[for]')) {
    label.dataset.labelFor = label.htmlFor;
}


async function identifyReceivingProduct(code) {
    if (receivingEditor.inert) return;
    code = (code || '').trim();
    if (!code) {
        scanStatus.textContent = 'Scan or type a barcode or SKU first.';
        return;
    }
    const lookupId = ++activeScanLookup;
    pendingScan = null;
    codeRecovery.hidden = true;
    if (scanLookupController) scanLookupController.abort();
    const controller = new AbortController();
    scanLookupController = controller;
    scanStatus.textContent = 'Checking product code…';
    try {
        const response = await fetch('../barcodeScanner/apiScanner/product_code_lookup.php?code=' + encodeURIComponent(code) + '&context=receiving', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller.signal
        });
        const result = await response.json();
        if (receivingEditor.inert || lookupId !== activeScanLookup) return;
        if (!response.ok || result.success === false) {
            scanStatus.textContent = result.error || 'The product code could not be checked right now. Try again or choose the product manually.';
            showScanRecovery(code, 'error', scanStatus.textContent);
            return;
        }
        if (result.outcome === 'unknown') {
            scanStatus.textContent = 'No product matches that code. Try again or choose the product manually.';
            showScanRecovery(code, 'unknown', 'Code is not registered. Identify the packaging or find an existing product.');
            return;
        }
        if (result.outcome === 'ambiguous') {
            scanStatus.textContent = 'That code matches more than one product. Choose the product manually.';
            showScanRecovery(code, 'ambiguous', 'Conflicting products or barcode types. Find the product and explicitly enter base units, or correct its identifiers before retrying.');
            return;
        }
        if (result.outcome === 'inactive') {
            scanStatus.textContent = 'This code belongs to an inactive product. Review it in Products or ask your Administrator. Nothing was reactivated.';
            showScanRecovery(code, 'inactive', scanStatus.textContent);
            return;
        }
        let product = productMap.get(String(result.product.product_id));
        if (!product) {
            const products = await loadReceivingCatalog();
            if (receivingEditor.inert || lookupId !== activeScanLookup) return;
            product = products.find(item => String(item.product_id) === String(result.product.product_id));
            if (!product) throw new Error('Product unavailable');
        }
        const matchedPack = result.matched_code_kind === 'pack_barcode';
        const matchedCase = result.matched_code_kind === 'case_barcode';
        const hasPackConfig = Number(product.pack_size || 0) > 1;
        if ((matchedPack && !hasPackConfig) || (matchedCase && !(Number.isInteger(Number(product.units_per_package)) && Number(product.units_per_package) > 0))) {
            scanStatus.textContent = 'This barcode has no valid saved conversion. Ask your Administrator to correct the product, or enter its base units manually.';
            showScanRecovery(code, 'error', scanStatus.textContent);
            return;
        }
        if (step === 3) showStep(1, false);
        let targetMode = 'units';
        if (matchedCase) {
            targetMode = 'packages';
        } else if (matchedPack && hasPackConfig) {
            targetMode = 'packs';
        }

        const existingGroup = productGroup(product.product_id);
        const existingRow = existingGroup?.lastEdited?.isConnected ? existingGroup.lastEdited : existingGroup?.querySelector('.receiving-line');
        if (existingRow) {
            const focusField = visibleQuantityInput(existingRow);
            focusField?.focus();
            scanStatus.textContent = `${product.product_name} (${product.sku}) already added. Entered quantities preserved.`;
            return;
        }

        if (!productMap.has(String(product.product_id))) {
            productMap.set(String(product.product_id), product);
        }

        const line = addReceivingLine({
            product_id: product.product_id,
            quantity_mode: targetMode
        });
        if (!line) return;
        const targetField = visibleQuantityInput(line);
        targetField?.focus();
        RetailMindUI.toast(`${product.product_name} added to delivery lines.`, 'success');
        productSearchScan.value = '';
        const matchedBy = result.matched_code_kind === 'sku'
            ? 'SKU'
            : (matchedPack ? 'pack barcode' : (matchedCase ? 'case barcode' : 'unit barcode'));
        const formPrompt = matchedPack
            ? 'Sales Packs'
            : (matchedCase ? 'packages / cases' : (targetMode === 'packs' ? 'sealed packs and loose pieces' : 'Base units'));
        scanStatus.textContent = `${product.product_name} (${product.sku}) selected. Matched by ${matchedBy}. Enter actual delivered ${formPrompt}.`;
    } catch (error) {
        if (receivingEditor.inert || error.name === 'AbortError' || lookupId !== activeScanLookup) return;
        scanStatus.textContent = 'The product code could not be checked right now. Try again or choose the product manually.';
        showScanRecovery(code, 'error', scanStatus.textContent);
    } finally {
        if (scanLookupController === controller) scanLookupController = null;
    }
}

productSearchScan.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return;
    event.preventDefault();
    if (event.repeat) return;
    const code = this.value.trim();
    if (code !== '') identifyReceivingProduct(code);
});

addScannedItem?.addEventListener('click', function () {
    const code = productSearchScan.value.trim();
    if (code !== '') identifyReceivingProduct(code);
    else scanStatus.textContent = 'Scan or type a barcode or SKU first.';
});

// Keyboard-wedge scanners send a rapid burst plus Enter without moving focus.
let keyboardReceivingCode = '';
let keyboardReceivingCodeAt = 0;
let keyboardReceivingQueue = Promise.resolve();
function resetKeyboardReceivingCode() {
    keyboardReceivingCode = '';
    keyboardReceivingCodeAt = 0;
}
document.addEventListener('keydown', event => {
    const editing = event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]');
    const dialogOpen = document.querySelector('dialog[open], .rm-modal-overlay.open, .command-overlay.open, .swal2-container');
    if (!canReceive || receivingEditor.inert || event.defaultPrevented || event.isComposing || event.repeat || event.ctrlKey || event.metaKey || event.altKey
        || document.hidden || editing || dialogOpen) {
        resetKeyboardReceivingCode();
        return;
    }
    const now = performance.now();
    // shortcut: scanners must send characters within 80ms; tune for slower hardware if needed.
    if (now - keyboardReceivingCodeAt > 80) resetKeyboardReceivingCode();
    if (event.key === 'Enter') {
        const code = keyboardReceivingCode;
        resetKeyboardReceivingCode();
        if (code.length < 3) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        keyboardReceivingQueue = keyboardReceivingQueue.then(() => identifyReceivingProduct(code));
    } else if (event.key.length === 1 && /^[\x21-\x7E]$/.test(event.key)) {
        keyboardReceivingCode += event.key;
        keyboardReceivingCodeAt = now;
        if (keyboardReceivingCode.length > 255) resetKeyboardReceivingCode();
    } else if (event.key !== 'Shift') resetKeyboardReceivingCode();
}, true);
window.addEventListener('blur', resetKeyboardReceivingCode);
document.addEventListener('visibilitychange', resetKeyboardReceivingCode);

function updatePackageConversion(row = activeLine) {
    if (!row) return;
    const field = name => row.querySelector('[data-field="' + name + '"]');
    const prodId = field('product_id')?.value;
    const prod = productMap.get(String(prodId));
    if (!prod) return;
    const mode = field('quantity_mode')?.value || 'units';

    const convEl = field('package_conversion');
    if (!convEl) return;

    if (mode === 'packs') {
        const packSize = Number(prod.pack_size || 1);
        const receivedPacksInput = Number(field('received_packs')?.value || 0);
        const receivedLooseInput = Number(field('received_loose')?.value || 0);
        const acceptedTotal = (receivedPacksInput * packSize) + receivedLooseInput;
        convEl.textContent = `Accepted: ${acceptedTotal} units (${receivedPacksInput} packs, ${receivedLooseInput} loose pieces).`;
    } else {
        const packageMode = mode === 'packages';
        const total = packageMode
            ? Number(field('received_packages')?.value || 0) * Number(prod.units_per_package || 1)
            : Number(field('received_qty')?.value || 0);
        convEl.textContent = `Accepted: ${total} units.`;
    }
    refreshDelivery();
}

function visibleQuantityInput(row) {
    const mode = row.querySelector('[data-field="quantity_mode"]').value;
    return row.querySelector('[data-field="' + (mode === 'packs' ? 'received_packs' : mode === 'packages' ? 'received_packages' : 'received_qty') + '"]');
}

function updateQuantityMode(row = activeLine) {
    if (!row) return;
    const field = name => row.querySelector('[data-field="' + name + '"]');
    const mode = field('quantity_mode')?.value || 'units';
    const isPacks = mode === 'packs';
    const isPackages = mode === 'packages';
    const isUnits = mode === 'units';

    const unitsGroup = field('units_qty_group');
    const packagesGroup = field('packages_qty_group');
    const packsGroup = field('packs_qty_group');
    const looseGroup = field('loose_qty_group');

    if (unitsGroup) unitsGroup.style.display = isUnits ? 'flex' : 'none';
    if (packagesGroup) packagesGroup.style.display = isPackages ? 'flex' : 'none';

    if (packsGroup) packsGroup.style.display = isPacks ? 'flex' : 'none';
    if (looseGroup) looseGroup.style.display = isPacks ? 'flex' : 'none';

    const receivedQty = field('received_qty');
    const receivedPackages = field('received_packages');

    if (receivedQty) {
        receivedQty.required = isUnits;
        receivedQty.disabled = !isUnits;
    }
    if (receivedPackages) {
        receivedPackages.required = isPackages;
        receivedPackages.disabled = !isPackages;
    }
    for (const name of ['received_packs', 'received_loose']) {
        const input = field(name);
        if (input) input.disabled = !isPacks;
    }

    for (const name of ['received_qty', 'received_packages']) {
        const input = field(name);
        if (input) {
            const label = row.querySelector('label[for="' + input.id + '"]');
            if (label) label.classList.toggle('required', input.required);
        }
    }
    updatePackageConversion(row);
}

let step = 1;
const form = document.getElementById('receiving_form');

function getLineQuantities(row, prod) {
    const field = name => row.querySelector('[data-field="' + name + '"]');
    const mode = field('quantity_mode')?.value || 'units';
    if (mode === 'packs') {
        const packSize = Number(prod.pack_size || 1);
        const value = name => /^\d+$/.test(field(name)?.value) ? Number(field(name).value) : 0;
        const total = (value('received_packs') * packSize) + value('received_loose');
        return {total, accepted: total};
    }

    const packages = mode === 'packages';
    const quantity = field(packages ? 'received_packages' : 'received_qty').value;
    const total = /^\d+$/.test(quantity) ? Number(quantity) * (packages ? Number(prod.units_per_package || 1) : 1) : 0;
    return {total, accepted: total};
}

function refreshDelivery(quotes = null) {
    const products = lineContainer.children.length;
    let accepted = 0;
    const costs = [];
    for (const row of receivingRows()) {
        const field = name => row.querySelector('[data-field="' + name + '"]');
        const prodId = field('product_id')?.value;
        const prod = productMap.get(String(prodId));
        if (!prodId || !prod) continue;
        const cost = quotes?.get(row) ?? draftCost(row, prod);
        accepted += cost.accepted_qty;
        costs.push(cost);
        const preview = cost.too_large ? 'Batch total is too large.'
            : cost.unit_cents === null ? ''
                : (cost.rounding_difference ? 'Approx. ' : '') + money(cost.unit_cents) + ' / ' + (prod.base_unit || 'unit')
                    + ' · Batch total ' + money(cost.total_cents);
        const previewField = field('cost_preview');
        if (previewField.textContent !== preview) previewField.textContent = preview;
    }
    for (const [name, value] of Object.entries({products, accepted})) {
        document.getElementById('summary_' + name).textContent = value;
    }
    const total = purchaseTotal(costs);
    document.getElementById('summary_cost_label').textContent = total.label;
    document.getElementById('summary_cost').textContent = total.value;
    document.getElementById('delivery_count').textContent = products + (products === 1 ? ' product' : ' products');
    const emptyState = document.getElementById('delivery_empty_state');
    if (emptyState) {
        emptyState.hidden = products > 0 || step === 3;
    }
    refreshGroups(quotes);
    renderCatalog();
}

let selectedCategory = 'all';
document.getElementById('receiving_category_filters')?.addEventListener('click', event => {
    const tile = event.target.closest('.receiving-category-tile');
    if (!tile) return;
    document.querySelectorAll('.receiving-category-tile').forEach(el => {
        el.classList.remove('active');
        el.setAttribute('aria-pressed', 'false');
    });
    tile.classList.add('active');
    tile.setAttribute('aria-pressed', 'true');
    selectedCategory = tile.dataset.category || 'all';
    renderCatalog();
});

function getStockState(quantityOnHand, reorderLevel, safetyStock) {
    const stock = Number(quantityOnHand || 0);
    const reorder = Number(reorderLevel || 0);
    const safety = Number(safetyStock || 0);
    const threshold = Math.max(reorder, safety);
    const isBelowThreshold = threshold > 0 && stock <= threshold;
    if (stock <= 0) return { state: 'out', label: 'Out of stock', isLow: isBelowThreshold || true };
    if (isBelowThreshold) {
        return { state: 'low', label: 'Low stock', isLow: true };
    }
    return { state: 'available', label: `${stock} in stock`, isLow: false };
}

function renderCatalog() {
    const query = productSearchScan.value.trim().toLowerCase();
    const selected = new Set(Array.from(receivingRows(), row => row.querySelector('[data-field="product_id"]').value));
    const matches = productsCatalog.filter(p => {
        const matchesCategory = selectedCategory === 'all' || String(p.category_id || '') === selectedCategory;
        const matchesQuery = !query || [
            p.product_name,
            p.sku,
            p.barcode,
            p.case_barcode,
            p.pack_barcode,
            p.variant_label
        ].filter(Boolean).join(' ').toLowerCase().includes(query);
        return matchesCategory && matchesQuery;
    });
    const catalog = document.getElementById('receiving_catalog');
    catalog.replaceChildren();

    for (const p of matches.slice(0, 24)) {
        const isAdded = selected.has(String(p.product_id));
        const stockInfo = getStockState(p.quantity_on_hand, p.reorder_level, p.safety_stock);
        const iconClass = 'bi-box-seam';

        const card = document.createElement('article');
        card.className = 'receiving-card' + (stockInfo.state === 'out' ? ' out-of-stock' : '');
        card.dataset.productId = String(p.product_id);

        const media = document.createElement('div');
        media.className = 'receiving-card-media';
        if (p.product_image) {
            const img = document.createElement('img');
            img.src = p.product_image;
            img.alt = '';
            img.loading = 'lazy';
            const fallback = document.createElement('i');
            fallback.className = 'bi ' + iconClass;
            fallback.setAttribute('aria-hidden', 'true');
            fallback.hidden = true;
            img.onerror = () => { img.hidden = true; fallback.hidden = false; };
            media.append(img, fallback);
        } else {
            const icon = document.createElement('i');
            icon.className = 'bi ' + iconClass;
            icon.setAttribute('aria-hidden', 'true');
            media.appendChild(icon);
        }

        if (stockInfo.isLow) {
            const badge = document.createElement('span');
            badge.className = 'receiving-card-badge low-stock';
            const badgeIcon = document.createElement('i');
            badgeIcon.className = 'bi bi-exclamation-triangle-fill';
            badgeIcon.setAttribute('aria-hidden', 'true');
            badge.append(badgeIcon, document.createTextNode(' Low stock'));
            media.appendChild(badge);
        }

        const body = document.createElement('div');
        body.className = 'receiving-card-body';

        const name = document.createElement('strong');
        name.className = 'receiving-card-name';
        name.title = p.product_name;
        name.textContent = p.product_name;

        const meta = document.createElement('span');
        meta.className = 'receiving-card-meta';
        const catLabel = p.category_name || 'General';
        meta.textContent = catLabel + ' · ' + (p.sku || 'No SKU');

        const costStock = document.createElement('div');
        costStock.className = 'receiving-card-cost-stock';

        const cost = document.createElement('span');
        cost.className = 'receiving-card-cost';
        const rawCost = p.cost_price;
        cost.textContent = knownProductCost(p)
            ? '₱' + Number(rawCost).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})
            : 'Not recorded';

        const stock = document.createElement('span');
        stock.className = 'receiving-card-stock ' + stockInfo.state;
        const stockIcon = document.createElement('i');
        stockIcon.className = 'bi bi-circle-fill';
        stockIcon.setAttribute('aria-hidden', 'true');
        stock.append(stockIcon, document.createTextNode(' ' + stockInfo.label));

        costStock.append(cost, stock);

        const pack = document.createElement('div');
        pack.className = 'receiving-card-pack';
        const unitsPerPkg = Number(p.units_per_package || 1);
        const baseUnit = p.base_unit || 'piece';
        const baseLabel = unitsPerPkg === 1 ? baseUnit : (baseUnit.endsWith('s') ? baseUnit : baseUnit + 's');
        const packSmall = document.createElement('small');
        packSmall.textContent = '1 ' + (p.receiving_unit || 'package') + ' = ' + unitsPerPkg + ' ' + baseLabel;
        pack.appendChild(packSmall);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary receiving-card-add';
        button.textContent = isAdded ? 'Added' : 'Add to delivery';
        button.setAttribute('aria-label', 'Add ' + p.product_name);
        button.disabled = isAdded && !pendingScan;
        button.addEventListener('click', () => {
            if (pendingScan) {
                if (pendingScan.outcome === 'unknown' && !pendingScan.packaging) {
                    scanClassification.querySelector('input').focus();
                    return;
                }
                chooseRecoveryProduct(productMap.get(String(p.product_id)) || p);
                return;
            }
            const row = addReceivingLine({product_id: p.product_id});
            showStep(1, false);
            if (row) visibleQuantityInput(row)?.focus();
        });

        body.append(name, meta, costStock, pack, button);
        card.append(media, body);
        catalog.append(card);
    }

    const hint = document.createElement('p');
    hint.className = 'receiving-catalog-hint';
    hint.textContent = matches.length ? 'Showing ' + Math.min(24, matches.length) + ' of ' + matches.length + ' products. Search to narrow the list.' : 'No matching products. Try another name or SKU.';
    catalog.append(hint);

    const matchesCount = matches.length;
    const countStatus = query
        ? (matchesCount ? matchesCount + ' matching ' + (matchesCount === 1 ? 'product.' : 'products.') : 'No matching products. Try another name or SKU, or scan a code.')
        : '';
    const statusEl = document.getElementById('product_search_status');
    if (statusEl) statusEl.textContent = countStatus;
}

function receivingRows() {
    return Array.from(lineContainer.querySelectorAll('.receiving-line'));
}

function productGroup(productId) {
    return Array.from(lineContainer.children).find(group => group.dataset.productId === String(productId));
}

function money(cents, grouped = true) {
    cents = BigInt(cents);
    const whole = cents / 100n;
    return (grouped ? '₱' + whole.toLocaleString('en-PH') : whole.toString()) + '.' + String(cents % 100n).padStart(2, '0');
}

function draftCost(row, product = productMap.get(row.dataset.productId)) {
    const accepted_qty = getLineQuantities(row, product).accepted;
    const entered = row.querySelector('[data-field="cost_price"]').value.trim();
    const basis = row.querySelector('[data-field="cost_basis"]').value;
    const amount = entered || (knownProductCost(product) ? Number(product.cost_price).toFixed(2) : '');
    const units = entered ? product.cost_conversions[basis] : 1;
    if (!units || accepted_qty > 2147483647 || !/^\d{1,8}(?:\.\d{1,2})?$/.test(amount)) {
        return {accepted_qty, unit_cents: null, total_cents: null};
    }
    const [whole, fraction = ''] = amount.split('.');
    const cents = BigInt(whole + fraction.padEnd(2, '0'));
    const divisor = BigInt(units);
    const quantity = BigInt(accepted_qty);
    const unit_cents = (cents + divisor / 2n) / divisor;
    const total_cents = (cents * quantity + divisor / 2n) / divisor;
    if (total_cents > 9999999999n) return {accepted_qty, unit_cents: null, total_cents: null, too_large: true};
    return {accepted_qty, unit_cents, total_cents, rounding_difference: unit_cents * quantity !== total_cents};
}

function purchaseTotal(costs) {
    const accepted = costs.filter(cost => cost.accepted_qty > 0);
    const known = accepted.filter(cost => cost.total_cents !== null);
    return {
        label: known.length && known.length < accepted.length ? 'Known cost' : 'Purchase total',
        value: known.length ? money(known.reduce((sum, cost) => sum + BigInt(cost.total_cents), 0n)) : 'Not recorded',
    };
}

function effectiveCost(row, quotes) {
    const cost = quotes?.get(row) ?? draftCost(row);
    return cost.accepted_qty > 0 && cost.unit_cents !== null ? money(cost.unit_cents, false) : '';
}

function groupCosts(group, quotes) {
    return [...new Set(Array.from(group.querySelectorAll('.receiving-line'), row => effectiveCost(row, quotes)).filter(Boolean))];
}

function refreshGroups(quotes) {
    for (const group of lineContainer.children) {
        const rows = Array.from(group.querySelectorAll('.receiving-line'));
        const product = productMap.get(group.dataset.productId);
        const batches = rows.map(row => quotes?.get(row) ?? draftCost(row, product));
        const total = purchaseTotal(batches);
        group.querySelector('.receiving-group-total').textContent = batches.reduce((sum, cost) => sum + cost.accepted_qty, 0)
            + ' accepted units · ' + total.label + ' ' + total.value;
        const choice = group.querySelector('.current-product-cost');
        const costs = groupCosts(group, quotes);
        if (!costs.includes(choice.value)) { choice.value = ''; group.dataset.choiceExplicit = ''; }
        if (costs.length === 1) choice.value = costs[0];
        else if (group.dataset.choiceExplicit !== 'true') choice.value = '';
    }
}

function renumberLines() {
    clearReceivingErrors();
    const rows = receivingRows();
    for (const [groupIndex, group] of Array.from(lineContainer.children).entries()) {
        group.querySelector('.current-product-cost').name = `lines[${groupIndex}][current_product_cost]`;
    }
    for (const [index, row] of rows.entries()) {
        const group = row.closest('.receiving-product-group');
        const siblings = Array.from(group.querySelectorAll('.receiving-line'));
        const allocationIndex = siblings.indexOf(row);
        const groupIndex = Array.from(lineContainer.children).indexOf(group);
        const lineNum = allocationIndex + 1;
        const total = siblings.length;
        const numEl = row.querySelector('.line-number');
        if (numEl) numEl.textContent = index + 1;
        row.setAttribute('aria-label', 'Product line ' + lineNum);

        const upBtn = row.querySelector('.move-line-up');
        if (upBtn) {
            upBtn.disabled = (allocationIndex === 0 && !group.previousElementSibling) || !canReceive;
            upBtn.setAttribute('aria-label', `Move line ${lineNum} up`);
        }
        const downBtn = row.querySelector('.move-line-down');
        if (downBtn) {
            downBtn.disabled = (allocationIndex === total - 1 && !group.nextElementSibling) || !canReceive;
            downBtn.setAttribute('aria-label', `Move line ${lineNum} down`);
        }
        const removeBtn = row.querySelector('.remove-receiving-line');
        if (removeBtn && !canReceive) {
            removeBtn.disabled = true;
        }

        for (const field of row.querySelectorAll('[data-field]')) {
            field.id = index === 0 ? field.dataset.field : field.dataset.field + '_' + index;
            if (field.hasAttribute('name')) {
                field.name = ['product_id', 'pullout_id'].includes(field.dataset.field)
                    ? `lines[${groupIndex}][${field.dataset.field}]`
                    : `lines[${groupIndex}][allocations][${allocationIndex}][${field.dataset.field}]`;
            }
        }
        for (const label of row.querySelectorAll('label[for]')) {
            const name = label.dataset.labelFor || label.htmlFor.replace(/_\d+$/, '');
            label.dataset.labelFor = name;
            label.htmlFor = index === 0 ? name : name + '_' + index;
        }
        const costHint = row.querySelector('[data-field="cost_hint"]');
        const costPrice = row.querySelector('[data-field="cost_price"]');
        const costPreview = row.querySelector('[data-field="cost_preview"]');
        costPrice.setAttribute('aria-describedby', costHint.id + ' ' + costPreview.id);
    }
    if (activeLine && !activeLine.isConnected) {
        activeLine = receivingRows().at(-1) || null;
    }
    refreshDelivery();
}

function activateLine(row) {
    activeLine = row;
    row.closest('.receiving-product-group').lastEdited = row;
}

function restoreField(field, value) {
    if (!field || typeof value !== 'string') return;
    if (field.tagName === 'SELECT' && !Array.from(field.options).some(option => option.value === value)) {
        field.add(new Option(value, value));
    }
    field.value = value;
    if (field.type === 'date' && value !== '' && field.value !== value) {
        field.type = 'text';
        field.placeholder = 'YYYY-MM-DD';
        field.value = value;
    }
}

function isLineTouched(row) {
    const field = name => row.querySelector(`[data-field="${name}"]`);
    return Boolean(
        field('received_qty')?.value.trim()
        || field('received_packages')?.value.trim()
        || field('received_packs')?.value.trim()
        || Number(field('received_loose')?.value || 0) !== 0
        || field('cost_price')?.value.trim()
        || field('batch_number')?.value.trim()
        || field('expiration_date')?.value.trim()
    );
}

function connectLine(row) {
    row.addEventListener('focusin', () => activateLine(row));
    row.addEventListener('input', clearReceivingErrors, {capture: true});
    row.addEventListener('change', clearReceivingErrors, {capture: true});
    const price = row.querySelector('[data-field="cost_price"]');
    const costChange = row.querySelector('[data-field="cost_change"]');
    price.addEventListener('input', () => {
        costChange.textContent = '';
        refreshDelivery();
    });
    row.querySelector('[data-field="cost_basis"]').addEventListener('change', () => {
        if (price.value.trim()) {
            price.value = '';
            costChange.textContent = 'Cost cleared. Enter the amount for the new basis.';
        }
        updateCostBasis(row, productMap.get(row.dataset.productId));
        refreshDelivery();
    });

    row.querySelector('.move-line-up')?.addEventListener('click', () => {
        const prev = row.previousElementSibling;
        const group = row.closest('.receiving-product-group');
        if (prev || group.previousElementSibling) {
            if (prev) row.parentElement.insertBefore(row, prev);
            else lineContainer.insertBefore(group, group.previousElementSibling);
            renumberLines();
            const upBtn = row.querySelector('.move-line-up');
            if (upBtn && !upBtn.disabled) upBtn.focus();
            else row.querySelector('.move-line-down')?.focus();
        }
    });

    row.querySelector('.move-line-down')?.addEventListener('click', () => {
        const next = row.nextElementSibling;
        const group = row.closest('.receiving-product-group');
        if (next || group.nextElementSibling) {
            if (next) row.parentElement.insertBefore(next, row);
            else lineContainer.insertBefore(group.nextElementSibling, group);
            renumberLines();
            const downBtn = row.querySelector('.move-line-down');
            if (downBtn && !downBtn.disabled) downBtn.focus();
            else row.querySelector('.move-line-up')?.focus();
        }
    });

    row.querySelector('.remove-receiving-line')?.addEventListener('click', () => {
        if (isLineTouched(row)) {
            if (!window.confirm('Remove this batch and its entered receiving details?')) return;
        }
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
        const group = row.closest('.receiving-product-group');
        row.remove();
        if (!group.querySelector('.receiving-line')) group.remove();
        document.getElementById('entry_status').textContent = '';
        activeLine = receivingRows().at(-1) || null;
        renumberLines();
        if (lineContainer.children.length === 0) {
            if (step !== 1) showStep(1);
            productSearchScan.focus();
        } else if (activeLine) {
            const btn = activeLine.querySelector('.remove-receiving-line');
            if (btn) btn.focus();
        }
    });

    row.querySelector('[data-field="quantity_mode"]')?.addEventListener('change', () => {
        row.dataset.modeEdited = 'true';
        activateLine(row);
        updateQuantityMode(row);
    });

    for (const name of ['received_qty', 'received_packages', 'received_packs', 'received_loose']) {
        row.querySelector('[data-field="' + name + '"]')?.addEventListener('input', () => {
            activateLine(row);
            updatePackageConversion(row);
        });
    }

    for (const name of ['cost_price', 'batch_number', 'expiration_date']) {
        const input = row.querySelector(`[data-field="${name}"]`);
        if (input) {
            input.addEventListener('input', () => updateExtrasDisclosure(row));
            input.addEventListener('change', () => updateExtrasDisclosure(row));
        }
    }

    row.addEventListener('dragstart', event => {
        if (step !== 1 || !canReceive || event.target.closest('input, textarea, select')) {
            event.preventDefault();
            return;
        }
        draggedRow = row;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.productId);
        row.classList.add('is-dragging');
    });
    row.addEventListener('dragend', () => {
        draggedRow = null;
        for (const child of receivingRows()) {
            child.classList.remove('is-dragging', 'drag-over-above', 'drag-over-below');
        }
    });
    row.addEventListener('dragover', event => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        if (!draggedRow || draggedRow === row) return;
        const rect = row.getBoundingClientRect();
        const midY = rect.top + rect.height / 2;
        if (event.clientY < midY) {
            row.classList.add('drag-over-above');
            row.classList.remove('drag-over-below');
        } else {
            row.classList.add('drag-over-below');
            row.classList.remove('drag-over-above');
        }
    });
    row.addEventListener('dragleave', () => {
        row.classList.remove('drag-over-above', 'drag-over-below');
    });
    row.addEventListener('drop', event => {
        event.preventDefault();
        if (!draggedRow || draggedRow === row) return;
        const isAbove = row.classList.contains('drag-over-above');
        row.classList.remove('drag-over-above', 'drag-over-below');
        if (draggedRow.parentElement !== row.parentElement) {
            const group = row.closest('.receiving-product-group');
            lineContainer.insertBefore(draggedRow.closest('.receiving-product-group'), isAbove ? group : group.nextElementSibling);
            renumberLines();
            return;
        }
        if (isAbove) {
            row.parentElement.insertBefore(draggedRow, row);
        } else {
            row.parentElement.insertBefore(draggedRow, row.nextElementSibling);
        }
        renumberLines();
    });
}

function knownProductCost(product) {
    return product && product.cost_price !== null && product.cost_price !== ''
        && (Number(product.cost_price) > 0 || Number(product.cost_known) === 1);
}

function costBasisName(product, basis) {
    return basis === 'packages' ? (product.receiving_unit || 'package')
        : basis === 'packs' ? 'Sales Pack' : (product.base_unit || 'base unit');
}

function selectedCostUnits(row, product) {
    if (!row.querySelector('[data-field="cost_price"]').value.trim()) return '1';
    const basis = row.querySelector('[data-field="cost_basis"]').value;
    return String(product.cost_conversions[basis] || 0);
}

function updateCostBasis(row, product, selected) {
    const select = row.querySelector('[data-field="cost_basis"]');
    selected ||= select.value || 'units';
    select.replaceChildren();
    if (!Object.hasOwn(product.cost_conversions, selected)) {
        const unavailable = new Option('Saved cost basis unavailable', selected);
        unavailable.disabled = true;
        select.add(unavailable);
    }
    for (const [basis, units] of Object.entries(product.cost_conversions)) {
        const conversion = basis === 'units' ? '' : ' (' + units + ' × ' + costBasisName(product, 'units') + ')';
        select.add(new Option('Per ' + costBasisName(product, basis) + conversion, basis));
    }
    select.value = selected;
    row.querySelector('[data-field="cost_hint"]').textContent = knownProductCost(product)
        ? 'Blank uses saved cost: ₱' + Number(product.cost_price).toFixed(2) + ' per ' + costBasisName(product, 'units')
        : 'Cost not recorded — enter cost if known';
}

function addReceivingLine(values = {}, allocation = false) {
    if (Array.isArray(values.allocations)) {
        for (const batch of values.allocations) addReceivingLine({...batch, product_id: values.product_id, pullout_id: values.pullout_id}, true);
        const group = productGroup(values.product_id);
        if (group) {
            group.querySelector('.current-product-cost').value = values.current_product_cost || '';
            group.dataset.choiceExplicit = values.current_product_cost ? 'true' : '';
        }
        refreshDelivery();
        return group;
    }
    const productId = String(values.product_id || values.productId || '');
    if (!productId) return null;
    let product = productMap.get(productId);
    if (!product) {
        product = {product_id: productId, product_name: 'Unavailable product #' + productId, cost_conversions: {units: 1}, unavailable: true};
        productMap.set(productId, product);
    }

    let group = productGroup(productId);
    if (group && !allocation) return null;
    if (!group) {
        group = document.createElement('section');
        group.className = 'receiving-product-group';
        group.dataset.productId = productId;
        const heading = document.createElement('div');
        heading.className = 'receiving-group-heading';
        const title = document.createElement('h3');
        title.textContent = product.product_name;
        const total = document.createElement('span');
        total.className = 'receiving-group-total';
        heading.append(title, total);
        const batches = document.createElement('div');
        batches.className = 'receiving-allocations';
        const choice = document.createElement('input');
        choice.type = 'hidden';
        choice.className = 'current-product-cost';
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'btn btn-secondary add-receiving-batch';
        add.textContent = 'Add another batch';
        add.disabled = !canReceive;
        add.addEventListener('click', () => {
            const row = addReceivingLine({product_id: productId, pullout_id: group.querySelector('[data-field="pullout_id"]').value}, true);
            visibleQuantityInput(row)?.focus();
        });
        group.append(heading, batches, choice, add);
        lineContainer.append(group);
    }

    const row = lineTemplate.content.firstElementChild.cloneNode(true);
    row.dataset.productId = productId;
    if (values.mode_edited) row.dataset.modeEdited = values.mode_edited;
    row.querySelector('[data-field="product_id"]').value = productId;
    row.querySelector('[data-field="cost_fallback"]').value = knownProductCost(product) ? Number(product.cost_price).toFixed(2) : '';
    updateCostBasis(row, product, values.cost_basis);

    const hasSalesPack = Number(product.pack_size || 0) > 1;
    updateProductIdentity(row, product);

    const qtyModeSelect = row.querySelector('[data-field="quantity_mode"]');
    if (qtyModeSelect) {
        if (!hasSalesPack) {
            const packOpt = qtyModeSelect.querySelector('option[value="packs"]');
            if (packOpt) packOpt.remove();
        } else if (!values.quantity_mode) {
            qtyModeSelect.value = 'packs';
        }
    }

    group.querySelector('.receiving-allocations').appendChild(row);
    connectLine(row);
    activateLine(row);
    renumberLines();

    // Inactive inputs stay disabled for saving, but their counts survive a rejected POST.
    try {
        const counts = JSON.parse(values.quantity_counts || '{}');
        for (const name of ['received_qty', 'received_packages', 'received_packs', 'received_loose']) {
            restoreField(row.querySelector('[data-field="' + name + '"]'), counts[name]);
        }
    } catch (_) { /* Older callers have no retained mode counts. */ }
    for (const [name, value] of Object.entries(values)) {
        if (!['product_id', 'cost_basis'].includes(name) && value !== undefined && value !== null) {
            restoreField(row.querySelector('[data-field="' + name + '"]'), String(value));
        }
    }
    const pulloutInput = row.querySelector('[data-field="pullout_id"]');
    if (pulloutInput && pulloutInput.value) {
        const badge = row.querySelector('[data-field="pullout_badge"]');
        const badgeId = row.querySelector('[data-field="pullout_badge_id"]');
        if (badge && badgeId) {
            badgeId.textContent = pulloutInput.value;
            badge.style.display = 'inline-block';
        }
    }
    if (values.current_product_cost !== undefined) {
        group.querySelector('.current-product-cost').value = values.current_product_cost;
        group.dataset.choiceExplicit = values.cost_choice_explicit || '';
    }
    updateQuantityMode(row);
    updateExtrasDisclosure(row);
    if (!canReceive) {
        row.setAttribute('draggable', 'false');
        row.querySelectorAll('input, select, textarea, button').forEach(el => el.disabled = true);
    }
    refreshDelivery();
    return row;
}

function updateProductIdentity(row, product) {
    const hasSalesPack = Number(product.pack_size || 0) > 1;
    const unitsPerPkg = Number(product.units_per_package || 1);
    const receivingUnit = product.receiving_unit || 'package';
    const baseUnit = product.base_unit || 'piece';
    const baseLabel = unitsPerPkg === 1 ? baseUnit : (baseUnit.endsWith('s') ? baseUnit : baseUnit + 's');
    const packText = hasSalesPack
        ? `1 Sales Pack = ${product.pack_size} ${baseLabel} · 1 ${receivingUnit} = ${unitsPerPkg} ${baseLabel}`
        : `1 ${receivingUnit} = ${unitsPerPkg} ${baseLabel}`;

    row.querySelector('.receiving-product-name').textContent = product.product_name;
    row.querySelector('.receiving-product-sku').textContent = product.sku ? `(${product.sku})` : '';
    row.querySelector('.receiving-product-pack').textContent = packText;
    row.querySelector('.remove-receiving-line').setAttribute('aria-label', `Remove ${product.product_name}`);

}

function showStep(next, focus = true) {
    queueMicrotask(() => window.RetailMindInventoryDrafts?.keep(form));
    step = next === 3 ? 3 : 1;
    document.querySelector('.receiving-form').dataset.step = String(step);
    document.getElementById('receiving_step_title').textContent = step === 3 ? 'Review receiving' : 'Receive stock';
    document.getElementById('receiving_selection').hidden = step !== 1;
    lineContainer.hidden = step === 3;
    const emptyState = document.getElementById('delivery_empty_state');
    if (emptyState) emptyState.hidden = lineContainer.children.length > 0 || step === 3;
    document.getElementById('receiving_review').hidden = step !== 3;
    document.querySelector('.receiving-summary').hidden = false;
    document.getElementById('delivery_title').textContent = step === 3 ? 'Check your delivery' : 'Delivery lines';
    document.getElementById('summary_status').textContent = step === 3 ? 'Review' : 'Draft';
    document.getElementById('receiving_back').hidden = step === 1;
    document.getElementById('receiving_back').textContent = 'Back to editing';
    document.getElementById('receiving_next').hidden = step === 3;
    document.getElementById('receiving_next').textContent = 'Review';
    form.querySelector('.btn-submit').hidden = step !== 3;
    if (focus) document.getElementById('receiving_step_title').focus();
}

function validateProducts() {
    clearReceivingErrors();
    const rows = receivingRows();
    if (rows.length === 0) {
        document.getElementById('entry_status').textContent = 'Add at least one product before continuing.';
        productSearchScan.focus();
        return false;
    }
    const products = Array.from(lineContainer.children).map(row => row.querySelector('[data-field="product_id"]')?.value).filter(Boolean);
    const valid = products.length === lineContainer.children.length && new Set(products).size === products.length;
    document.getElementById('entry_status').textContent = valid ? '' : 'Add at least one product before continuing.';
    if (!valid) {
        productSearchScan.focus();
    }
    return valid;
}

function clearReceivingErrors() {
    for (const field of form.querySelectorAll('[aria-invalid="true"]')) {
        field.removeAttribute('aria-invalid');
        const errorId = field.id + '_error';
        const describedBy = (field.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== errorId);
        if (describedBy.length) field.setAttribute('aria-describedby', describedBy.join(' '));
        else field.removeAttribute('aria-describedby');
    }
    for (const error of form.querySelectorAll('[data-receiving-error]')) error.remove();
    document.getElementById('entry_status').textContent = '';
}

function receivingError(field, message) {
    const details = field.closest('details');
    if (details) {
        details.open = true;
    }
    const error = document.createElement('small');
    error.id = field.id + '_error';
    error.dataset.receivingError = '';
    error.textContent = message;
    field.after(error);
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
    const summary = document.getElementById('entry_status');
    const link = document.createElement('a');
    link.href = '#' + field.id;
    link.textContent = summary.textContent || message;
    link.addEventListener('click', event => { event.preventDefault(); field.focus(); });
    summary.replaceChildren(link);
    field.focus();
}

function validateQuantities(checkCostConfiguration = true) {
    clearReceivingErrors();
    if (!canReceive) return true;
    for (const [index, row] of receivingRows().entries()) {
        const field = name => row.querySelector('[data-field="' + name + '"]');
        const prodId = field('product_id')?.value;
        const prod = productMap.get(String(prodId));
        const mode = field('quantity_mode')?.value || 'units';
        let invalid = null;
        let message = '';
        if (!prod || prod.unavailable) {
            invalid = field('quantity_mode');
            message = 'This product is no longer available. Remove it or ask your Administrator to check it.';
        } else if (!['units', 'packages', 'packs'].includes(mode) || (mode === 'packs' && !(Number(prod.pack_size) > 1))) {
            invalid = field('quantity_mode');
            message = 'Choose a supported quantity type with a saved conversion.';
        } else if (mode === 'packs') {
            const packSize = Number(prod?.pack_size || 1);
            const receivedPacksField = field('received_packs');
            const receivedLooseField = field('received_loose');
            const receivedPacksValue = receivedPacksField ? receivedPacksField.value.trim() : '';
            const receivedLooseValue = receivedLooseField ? receivedLooseField.value.trim() : '';
            if (!/^\d+$/.test(receivedPacksValue)) {
                invalid = receivedPacksField;
                message = 'Enter received packs as a whole number from zero upward.';
            } else if (!/^\d+$/.test(receivedLooseValue)) {
                invalid = receivedLooseField;
                message = 'Enter received loose pieces as a whole number from zero upward.';
            } else if (Number(receivedPacksValue) === 0 && Number(receivedLooseValue) === 0) {
                invalid = receivedPacksField;
                message = 'Enter at least one received pack or loose piece.';
            } else if (((Number(receivedPacksValue) * packSize) + Number(receivedLooseValue)) > 2147483647) {
                invalid = receivedPacksField;
                message = 'Resulting unit quantity is too large.';
            }
        } else {
            const packages = mode === 'packages';
            const quantity = field(packages ? 'received_packages' : 'received_qty');
            const pack = packages ? Number(prod?.units_per_package || 1) : 1;
            const total = Number(quantity.value) * pack;
            if (!/^\d+$/.test(quantity.value) || Number(quantity.value) <= 0 || !Number.isInteger(pack) || pack <= 0 || total > 2147483647) {
                invalid = quantity;
                message = 'Enter a positive whole-number bought quantity within range and use a valid saved pack size.';
            }
        }
        if (!invalid) {
            invalid = Array.from(row.querySelectorAll('input, select, textarea')).find(input => !input.checkValidity());
            message = invalid?.validationMessage || '';
            const cost = field('cost_price');
            if (!invalid && cost.value.trim() !== '' && !/^\d{1,8}(?:\.\d{1,2})?$/.test(cost.value.trim())) {
                invalid = cost;
                message = 'Enter a non-negative purchase cost with at most two decimal places, or leave it blank.';
            }
            if (!invalid && checkCostConfiguration && selectedCostUnits(row, prod) === '0') {
                invalid = field('cost_basis');
                message = 'Choose a purchase cost basis configured for this product.';
            }
            if (!invalid && checkCostConfiguration && draftCost(row, prod).too_large) {
                invalid = cost;
                message = 'Total purchase cost is too large.';
            }
        }
        if (invalid) {
            showStep(2, false);
            document.getElementById('entry_status').textContent = 'Line ' + (index + 1) + ': ' + message;
            receivingError(invalid, message);
            return false;
        }
    }
    for (const name of ['supplier', 'invoice_number']) {
        const input = form.elements.namedItem(name);
        if (!input.checkValidity()) { showStep(2, false); input.reportValidity(); return false; }
    }
    document.getElementById('entry_status').textContent = '';
    return true;
}

let reviewQuotes = null;
function renderReview(quotes = null) {
    reviewQuotes = quotes;
    refreshDelivery(quotes);
    const review = document.getElementById('receiving_review');
    review.replaceChildren();
    const reviewGroups = new Map();
    for (const [index, group] of Array.from(lineContainer.children).entries()) {
        const section = document.createElement('section');
        section.className = 'receiving-review-product';
        const heading = document.createElement('h3');
        heading.textContent = productMap.get(group.dataset.productId).product_name + ' · ' + group.querySelector('.receiving-group-total').textContent;
        section.append(heading);
        review.append(section);
        reviewGroups.set(group.dataset.productId, section);
    }
    const sharedDetails = [
        form.elements.supplier.value && 'Bought from: ' + form.elements.supplier.value,
        form.elements.invoice_number.value && 'Receipt: ' + form.elements.invoice_number.value,
    ].filter(Boolean);
    if (sharedDetails.length) {
        const shared = document.createElement('p');
        shared.className = 'receiving-review-entry-details';
        shared.textContent = sharedDetails.join(' · ');
        review.append(shared);
    }
    for (const [index, row] of receivingRows().entries()) {
        const field = name => row.querySelector('[data-field="' + name + '"]');
        const prodId = field('product_id')?.value;
        const prod = productMap.get(String(prodId));
        const section = document.createElement('section');
        section.className = 'receiving-review-line';
        const header = document.createElement('div');
        header.className = 'receiving-review-header';
        const identity = document.createElement('div');
        const title = document.createElement('h3');
        title.textContent = 'Batch ' + (Array.from(row.parentElement.children).indexOf(row) + 1);
        identity.append(title);
        if (prod?.sku) {
            const sku = document.createElement('span');
            sku.className = 'receiving-review-sku';
            sku.textContent = prod.sku;
            identity.append(sku);
        }
        const mode = document.createElement('span');
        mode.className = 'receiving-review-mode';
        const qtyModeSelect = field('quantity_mode');
        const modeText = qtyModeSelect.options[qtyModeSelect.selectedIndex]?.textContent || 'Units';
        mode.textContent = modeText;
        identity.append(mode);
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-secondary';
        edit.textContent = 'Edit line ' + (index + 1);
        edit.addEventListener('click', () => {
            showStep(2, false);
            row.scrollIntoView({behavior: 'instant', block: 'center'});
            const target = field('quantity_mode') || row.querySelector('input:not([type="hidden"]), select');
            target?.focus();
        });
        header.append(identity, edit);
        const quantities = getLineQuantities(row, prod);
        const quantityList = document.createElement('dl');
        quantityList.className = 'receiving-review-quantities';
        for (const [label, value] of [['Accepted', quantities.accepted]]) {
            const item = document.createElement('div');
            item.className = 'receiving-review-quantity is-' + label.toLowerCase() + (label === 'Damaged' && value > 0 ? ' is-positive' : '');
            const term = document.createElement('dt');
            term.textContent = label;
            const detail = document.createElement('dd');
            detail.textContent = value + ' units';
            item.append(term, detail);
            quantityList.append(item);
        }
        section.append(header, quantityList);
        const pulloutIdVal = field('pullout_id')?.value;
        if (pulloutIdVal) {
            const pulloutInfo = document.createElement('p');
            pulloutInfo.textContent = 'Linked replacement: Supplier Pullout #' + pulloutIdVal;
            section.append(pulloutInfo);
        }
        const cost = quotes?.get(row) ?? draftCost(row, prod);
        const basis = cost.cost_basis || field('cost_basis').value;
        const basisAmount = cost.entered_cost !== null && cost.entered_cost !== undefined && basis !== 'units'
            ? 'Entered: ₱' + cost.entered_cost.toFixed(2) + ' per ' + costBasisName(prod, basis)
                + ' (' + cost.cost_units_used + ' × ' + costBasisName(prod, 'units') + ')'
            : '';
        const details = [
            modeText + ': ' + (field('quantity_mode').value === 'packs'
                ? field('received_packs').value + ' packs + ' + field('received_loose').value + ' loose; 1 pack = ' + prod.pack_size + ' base units'
                : field('quantity_mode').value === 'packages'
                    ? field('received_packages').value + ' packages; 1 ' + (prod.receiving_unit || 'package') + ' = ' + prod.units_per_package + ' base units'
                    : field('received_qty').value + ' base units'),
            basisAmount,
            cost.unit_cents === null ? 'Cost: Not recorded'
                : 'Cost: ' + money(cost.unit_cents) + ' per ' + costBasisName(prod, 'units')
                    + (cost.cost_source === 'product' ? ' (saved)' : '') + ' · Batch total: ' + money(cost.total_cents),
            cost.rounding_difference ? 'Unit cost is rounded. The batch total uses the entered amount.' : '',
            'Batch: ' + (field('batch_number').value || 'Not recorded'),
            'Expiry: ' + (field('expiration_date').value || 'Not recorded'),
        ].filter(Boolean);
        if (details.length) {
            const detailText = document.createElement('p');
            detailText.className = 'receiving-review-details';
            detailText.textContent = details.join(' · ');
            section.append(detailText);
        }
        reviewGroups.get(String(prodId)).append(section);
    }
    for (const [index, group] of Array.from(lineContainer.children).entries()) {
        const section = reviewGroups.get(group.dataset.productId);
        const costs = groupCosts(group, quotes);
        const choice = group.querySelector('.current-product-cost');
        const container = document.createElement('div');
        container.className = 'form-group receiving-current-cost';
        if (costs.length > 1) {
            const label = document.createElement('label');
            const select = document.createElement('select');
            select.id = 'current_product_cost_' + index;
            label.htmlFor = select.id;
            label.textContent = 'Current product cost';
            select.add(new Option('Choose a batch cost', ''));
            for (const cost of costs) {
                const batches = Array.from(group.querySelectorAll('.receiving-line'))
                    .flatMap((row, n) => effectiveCost(row, quotes) === cost ? ['Batch ' + (n + 1)] : []);
                select.add(new Option('₱' + cost + ' / base unit · ' + batches.join(', '), cost));
            }
            select.value = choice.value;
            select.addEventListener('change', () => {
                choice.value = select.value;
                group.dataset.choiceExplicit = select.value ? 'true' : '';
                clearReceivingErrors();
                window.RetailMindInventoryDrafts?.keep(form);
            });
            container.append(label, select);
        } else {
            container.textContent = costs.length ? 'Current product cost: ₱' + costs[0] + ' / base unit' : 'Current product cost unchanged · Cost not recorded';
        }
        section.append(container);
    }
}

document.getElementById('receiving_back').addEventListener('click', () => showStep(1));
function reviewConversion(row, product) {
    const mode = row.querySelector('[data-field="quantity_mode"]').value;
    return String(mode === 'packs' ? product.pack_size || 0 : mode === 'packages' ? product.units_per_package || 0 : 1);
}

async function loadReviewCosts() {
    for (const row of receivingRows()) {
        const product = productMap.get(row.dataset.productId);
        row.querySelector('[data-field="cost_fallback"]').value = knownProductCost(product) ? Number(product.cost_price).toFixed(2) : '';
        row.querySelector('[data-field="review_conversion"]').value = reviewConversion(row, product);
        row.querySelector('[data-field="review_cost_units"]').value = selectedCostUnits(row, product);
        row.querySelector('[data-field="quantity_counts"]').value = JSON.stringify(Object.fromEntries(
            ['received_qty', 'received_packages', 'received_packs', 'received_loose'].map(name => [name, row.querySelector('[data-field="' + name + '"]').value])));
    }
    const data = new FormData(form);
    const snapshot = fields => new URLSearchParams(Array.from(fields).filter(([name]) =>
        name.startsWith('lines[') || name === 'supplier' || name === 'invoice_number')).toString();
    const reviewedFields = snapshot(data);
    data.set('preview_costs', '1');
    const response = await fetch(form.action, {method: 'POST', body: data, headers: {Accept: 'application/json'}});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error);
    if (receivingEditor.inert) return null;
    if (reviewedFields !== snapshot(new FormData(form))) throw new Error('Delivery changed during Review. Review again.');
    const costs = result.costs.flat();
    const rows = receivingRows();
    if (costs.length !== rows.length) throw new Error('The receiving entry is incomplete. Split it into smaller entries and try again.');
    return new Map(rows.map((row, index) => [row, costs[index]]));
}

let reviewing = false;
document.getElementById('receiving_next').addEventListener('click', async () => {
    if (reviewing) return;
    if (!validateProducts()) return;
    if (!validateQuantities(false)) return;
    if (!canReceive) { renderReview(); showStep(3); return; }
    reviewing = true;
    const button = document.getElementById('receiving_next');
    button.disabled = true;
    try {
        const products = await loadReceivingCatalog();
        if (receivingEditor.inert) return;
        const fresh = new Map(products.map(product => [String(product.product_id), product]));
        const priorProducts = new Map(productMap);
        let changed = false;
        for (const row of receivingRows()) {
            const id = row.querySelector('[data-field="product_id"]').value;
            const prior = priorProducts.get(id), current = fresh.get(id);
            if (!current) { prior.unavailable = true; changed = true; continue; }
            const inheritsCost = row.querySelector('[data-field="cost_price"]').value.trim() === '';
            changed ||= reviewConversion(row, prior) !== reviewConversion(row, current)
                || selectedCostUnits(row, prior) !== selectedCostUnits(row, current)
                || (inheritsCost && (knownProductCost(prior) !== knownProductCost(current) || Number(prior.cost_price) !== Number(current.cost_price)));
            productMap.set(id, current);
            updateProductIdentity(row, current);
            updateCostBasis(row, current);
            updatePackageConversion(row);
        }
        if (changed) {
            const status = document.getElementById('entry_status');
            status.textContent = 'Product conversion or saved cost changed. Quantities kept. Check the updated values, then Review again.';
            status.focus();
            return;
        }
        if (!validateQuantities()) return;
        const quotes = await loadReviewCosts();
        if (quotes) { renderReview(quotes); showStep(3); }
    } catch (error) {
        const status = document.getElementById('entry_status');
        status.textContent = error.message && !(error instanceof TypeError || error instanceof SyntaxError)
            ? error.message : 'Current products could not be checked. Values kept. Check your connection and try Review again.';
        status.focus();
    } finally { reviewing = false; button.disabled = false; }
});

productSearchScan.addEventListener('input', renderCatalog);

renderCatalog();
renumberLines();

const receivingDraftObserver = new MutationObserver(() => {
    if (!restoringHandoff) window.RetailMindInventoryDrafts?.keep(form);
});
receivingDraftObserver.observe(lineContainer, {childList: true, subtree: true});
window.addEventListener('pagehide', () => receivingDraftObserver.disconnect(), {once: true});
window.RetailMindInventoryDrafts?.register({
    eligible: candidate => candidate === form,
    adopt(draft) {
        if (!/^receiving-[a-f0-9]{32}$/.test(draft.id)) return;
        // Explicit recovery from another tab must not overwrite the handoff snapshot.
        try { return !!RetailMindInventoryDrafts.tabProof(draft.id.slice('receiving-'.length)); }
        catch (_) { return false; }
    },
    offer: () => !restoringHandoff,
    submissionKind: () => 'receiving',
    capture(draft) {
        const productBasis = {};
        for (const group of lineContainer.children) {
            const product = productMap.get(group.dataset.productId);
            const {receiving_unit, units_per_package, pack_size, cost_price, cost_known, cost_conversions} = product;
            productBasis[group.dataset.productId] = {receiving_unit, units_per_package, pack_size, cost_price, cost_known, cost_conversions};
        }
        draft.context = {...draft.context, appliedHandoff, pendingScan, productBasis, scanText: productSearchScan.value, step, allocations: true, lines: Array.from(receivingRows(), row => ({...Object.fromEntries(
            Array.from(row.querySelectorAll('[data-field]'), field => [field.dataset.field, field.value])), mode_edited: row.dataset.modeEdited || '', current_product_cost: row.closest('.receiving-product-group').querySelector('.current-product-cost').value, cost_choice_explicit: row.closest('.receiving-product-group').dataset.choiceExplicit || ''}))};
    },
    prepare(draft) {
        const lines = draft.context.lines || [];
        const linesWithExcludedQuantity = lines.filter(line => ['damaged_qty', 'damaged_packs', 'damaged_loose', 'rejected_packs', 'rejected_loose']
            .some(name => Number(line[name] || 0) > 0));
        if (lines.some(line => !productMap.has(String(line.product_id)) || productMap.get(String(line.product_id)).unavailable)) {
            throw new Error('A selected Product is no longer available. Your draft is still kept; review the current catalog before resuming.');
        }
        for (const [id, basis] of Object.entries(draft.context.productBasis || {})) {
            if (productMap.has(id)) productMap.set(id, {...productMap.get(id), ...basis});
        }
        appliedHandoff = draft.context.appliedHandoff || '';
        pendingScan = null;
        codeRecovery.hidden = true;
        for (const line of linesWithExcludedQuantity) {
            for (const name of ['received_qty', 'received_packages', 'received_packs', 'received_loose']) line[name] = '';
            line.quantity_counts = '';
        }
        draft.values = draft.values.filter(item => {
            const match = item.name.match(/^lines\[(\d+)\](?:\[allocations\]\[(\d+)\])?\[(received_qty|received_packages|received_packs|received_loose|quantity_counts)\]$/);
            if (!match) return true;
            const productIds = [...new Set(lines.map(line => line.product_id))];
            const line = match[2] === undefined ? lines[Number(match[1])]
                : lines.filter(line => line.product_id === productIds[Number(match[1])])[Number(match[2])];
            return !linesWithExcludedQuantity.includes(line);
        });
        lineContainer.replaceChildren();
        for (const line of lines) addReceivingLine(line, draft.context.allocations === true);
        if (linesWithExcludedQuantity.length > 0) {
            document.getElementById('entry_status').textContent = 'A saved draft used damaged or rejected quantities. Enter the good quantities received again; your draft is still kept.';
        }
        showStep(draft.context.step === 3 ? 2 : (draft.context.step || 1));
        productSearchScan.value = String(draft.context.scanText || '');
        const recovery = draft.context.pendingScan;
        if (recovery?.code) {
            showStep(1, false);
            showScanRecovery(String(recovery.code), recovery.outcome, 'Code kept. Choose packaging and find the product again before applying a conversion.');
            pendingScan.packaging = ['units', 'packages', 'packs'].includes(recovery.packaging) ? recovery.packaging : '';
        }
        renderCatalog();
    },
    restored() {
        if (step === 3) renderReview();
        if (pendingScan) {
            for (const radio of scanClassification.querySelectorAll('input')) radio.checked = radio.value === pendingScan.packaging;
            scanClassification.querySelector('input:checked')?.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }
});
if (handoffRecovery) {
    const retry = document.getElementById('handoff_retry');
    const status = document.getElementById('handoff_status');
    async function restoreReceivingHandoff() {
        retry.hidden = true;
        status.textContent = 'Restoring your delivery…';
        try {
            if (!returnHandoffId || !RetailMindInventoryDrafts.tabProof(returnHandoffId)) throw new Error('Open the original tab to return to this delivery.');
            await navigator.locks.request('retailmind:receiving:' + returnHandoffId, async () => {
                const result = await receivingHandoff('return', returnHandoffId);
                const products = await loadReceivingCatalog();
                productsCatalog = products;
                productMap.clear();
                for (const product of products) productMap.set(String(product.product_id), product);
                const product = result.product;
                const current = product && productMap.get(String(product.id));
                if (result.state !== 'consumed' && product && !current) throw new Error('The new product is unavailable. Check Products, then retry.');
                const draft = await window.RetailMindInventoryDrafts?.restore('receiving-' + returnHandoffId);
                if (!draft) throw new Error('Check your connection and current Products, then retry. You can also open Stock Receiving and choose Resume for the saved delivery.');
                let focus = null;
                if (result.state !== 'consumed' && product && appliedHandoff !== returnHandoffId) {
                    const mode = (product.mode === 'packs' && !(Number(current.pack_size) > 1 && current.pack_barcode))
                        || (product.mode === 'packages' && !(Number(current.units_per_package) > 0)) ? 'units' : product.mode;
                    const row = addReceivingLine({product_id: product.id, quantity_mode: mode});
                    focus = row && visibleQuantityInput(row);
                    pendingScan = null;
                    codeRecovery.hidden = true;
                    productSearchScan.value = '';
                    renderCatalog();
                    appliedHandoff = returnHandoffId;
                    if (!await RetailMindInventoryDrafts.keep(form)) throw new Error('Allow browser storage or free space, then retry.');
                }
                if (result.state !== 'consumed') await receivingHandoff('consume', returnHandoffId);
                await RetailMindInventoryDrafts.discard('product-' + returnHandoffId, <?= json_encode(app_url('components/inventory_management/products.php')) ?>);
                restoringHandoff = false;
                receivingEditor.inert = false;
                handoffRecovery.hidden = true;
                if (focus) focus.focus();
                else if (pendingScan) document.getElementById('scan_recovery_message').focus();
                else document.getElementById('receiving_step_title').focus();
            });
        } catch (error) {
            status.textContent = 'Could not restore the delivery. ' + error.message;
            retry.hidden = false;
            status.focus();
        }
    }
    retry.addEventListener('click', restoreReceivingHandoff);
    restoreReceivingHandoff();
}
window.addEventListener('pageshow', event => {
    if (event.persisted && new URLSearchParams(location.search).has('handoff')) location.reload();
});
let retainedValues = <?= json_encode($formValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
for (const name of ['supplier', 'invoice_number']) restoreField(form.elements.namedItem(name), retainedValues[name]);
const linesToRestore = Array.isArray(retainedValues.lines)
    ? retainedValues.lines
    : (retainedValues.lines && typeof retainedValues.lines === 'object' ? Object.values(retainedValues.lines) : []);
if (linesToRestore.length > 0) {
    for (const values of linesToRestore) addReceivingLine(values);
} else if (retainedValues.product_id) {
    addReceivingLine(retainedValues);
}

let saving = false;
form.addEventListener('submit', event => {
    if (saving) { event.preventDefault(); return; }
    if (!canReceive) { event.preventDefault(); return; }
    if (step !== 3) { event.preventDefault(); document.getElementById('receiving_next').click(); return; }
    const products = Array.from(lineContainer.children).map(row => row.querySelector('[data-field="product_id"]').value);
    if (products.length === 0 || new Set(products).size !== products.length) {
        event.preventDefault();
        document.getElementById('entry_status').textContent = products.length === 0
            ? 'Add at least one product before saving.' : 'Duplicate product: remove the duplicate line or choose another product.';
        return;
    }
    if (!validateQuantities()) { event.preventDefault(); return; }
    for (const [index, group] of Array.from(lineContainer.children).entries()) {
        if (groupCosts(group, reviewQuotes).length > 1 && !group.querySelector('.current-product-cost').value) {
            event.preventDefault();
            receivingError(document.getElementById('current_product_cost_' + index), 'Choose Current product cost from the batches.');
            return;
        }
    }
    saving = true;
    try {
        sessionStorage.removeItem('retailmind_replenishment_snapshot');
    } catch (_) {}
    form.querySelector('.btn-submit').disabled = true;
    form.querySelector('.btn-submit').textContent = 'Saving receipt…';
});

const hasQuantities = linesToRestore.some(line =>
    (line.received_qty !== undefined && line.received_qty !== '') ||
    (line.received_packages !== undefined && line.received_packages !== '')
);
showStep(hasQuantities ? 2 : 1);

</script>
</body>
</html>
