<?php
// components/inventory_management/replenishment_requests.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_role(['admin', 'super_admin', 'inventory_manager']);
$isAdmin = in_array(current_role(), ['admin', 'super_admin'], true);

$message = '';
$error = '';

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $requestQty = (int)($_POST['request_qty'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $source = ($_POST['source'] ?? '') === 'ml_forecast' ? 'ml_forecast' : 'manual';
            $predictionId = $source === 'ml_forecast' ? (int)($_POST['forecast_prediction_id'] ?? 0) : 0;
            $originalQty = $source === 'ml_forecast' ? max(0, (int)($_POST['original_suggested_qty'] ?? 0)) : null;
            $overrideReason = trim((string)($_POST['override_reason'] ?? ''));
            if ($productId <= 0 || $requestQty <= 0) {
                throw new RuntimeException('Product and quantity are required.');
            }
            if ($source === 'ml_forecast' && $originalQty !== null && $requestQty !== $originalQty && $overrideReason === '') {
                throw new RuntimeException('Explain why the forecast quantity was changed.');
            }
            $stmt = $pdo->prepare("INSERT INTO replenishment_requests
                (product_id, request_qty, requested_by, source, forecast_prediction_id, original_suggested_qty, override_reason, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $requestQty, (int)$_SESSION['user_id'], $source, $predictionId ?: null, $originalQty, $overrideReason ?: null, $notes ?: null]);
            $requestId = (int)$pdo->lastInsertId();
            if ($source === 'ml_forecast' && $predictionId > 0) {
                $decision = $requestQty === $originalQty ? 'accepted' : 'modified';
                $pdo->prepare("INSERT INTO forecast_decisions
                    (prediction_id, product_id, original_suggested_qty, final_quantity, decision, override_reason, decided_by, replenishment_request_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$predictionId, $productId, $originalQty, $requestQty, $decision, $overrideReason ?: null, (int)$_SESSION['user_id'], $requestId]);
            }
            log_activity($pdo, (int)$_SESSION['user_id'], 'Replenishment request creation', 'Replenishment', $requestId, null, [
                'product_id'=>$productId,'request_qty'=>$requestQty,'source'=>$source,'original_suggested_qty'=>$originalQty,'override_reason'=>$overrideReason,'status'=>'pending'
            ]);
            $message = "Replenishment request #{$requestId} created successfully";
        } elseif ($action === 'reject_forecast') {
            $predictionId = (int)($_POST['forecast_prediction_id'] ?? 0);
            $productId = (int)($_POST['product_id'] ?? 0);
            $originalQty = max(0, (int)($_POST['original_suggested_qty'] ?? 0));
            $reason = trim((string)($_POST['override_reason'] ?? ''));
            if ($predictionId <= 0 || $productId <= 0 || $reason === '') {
                throw new RuntimeException('A reason is required to reject a forecast suggestion.');
            }
            $pdo->prepare("INSERT INTO forecast_decisions
                (prediction_id, product_id, original_suggested_qty, final_quantity, decision, override_reason, decided_by)
                VALUES (?, ?, ?, 0, 'rejected', ?, ?)")
                ->execute([$predictionId, $productId, $originalQty, $reason, (int)$_SESSION['user_id']]);
            $message = 'Forecast suggestion rejected and recorded.';
        } elseif (in_array($action, ['approve','reject'], true)) {
            if (!$isAdmin) {
                throw new RuntimeException('Only an administrator can approve or reject replenishment requests.');
            }
            $requestId = (int)($_POST['request_id'] ?? 0);
            if ($requestId <= 0) {
                throw new RuntimeException('Invalid request ID.');
            }
            $pdo->beginTransaction();
            $beforeStmt = $pdo->prepare("SELECT * FROM replenishment_requests WHERE request_id = ? FOR UPDATE");
            $beforeStmt->execute([$requestId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('Replenishment request not found.');
            }
            if ($before['status'] !== 'pending') {
                throw new RuntimeException('Only pending requests can be reviewed.');
            }
            if ((int)$before['requested_by'] === (int)$_SESSION['user_id']) {
                throw new RuntimeException('You cannot approve or reject your own replenishment request.');
            }
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare("UPDATE replenishment_requests SET status = ?, approved_by = ?, approved_at = NOW()
                                  WHERE request_id = ? AND status = 'pending' AND requested_by <> ?");
            $stmt->execute([$newStatus, (int)$_SESSION['user_id'], $requestId, (int)$_SESSION['user_id']]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('The request changed before it could be reviewed.');
            }
            $afterStmt = $pdo->prepare("SELECT * FROM replenishment_requests WHERE request_id = ?");
            $afterStmt->execute([$requestId]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Replenishment ' . $action, 'Replenishment', $requestId, $before, $after);
            $pdo->commit();

            $notifyStmt = $pdo->prepare("SELECT u.email,u.user_id,p.product_name,rr.request_qty,
                COALESCE(np.notify_email,0) notify_email,COALESCE(np.notify_inapp,1) notify_inapp
                FROM replenishment_requests rr JOIN users u ON u.user_id=rr.requested_by
                JOIN products p ON p.product_id=rr.product_id LEFT JOIN notification_preferences np ON np.user_id=u.user_id
                WHERE rr.request_id=?");
            $notifyStmt->execute([$requestId]);
            $recipient = $notifyStmt->fetch(PDO::FETCH_ASSOC);
            if ($recipient) {
                $title = "Replenishment request #{$requestId} {$newStatus}";
                $body = "Your request for {$recipient['request_qty']} unit(s) of {$recipient['product_name']} was {$newStatus}.";
                if (!empty($recipient['notify_inapp'])) create_notification($pdo, (int)$recipient['user_id'], 'replenishment', $title, $body, $requestId, 'replenishment');
                if (!empty($recipient['notify_email']) && !empty($recipient['email'])) send_email_notification((string)$recipient['email'], $title, $body);
            }
            $message = "Replenishment request #{$requestId} {$newStatus}";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Get all products
$products_stmt = $pdo->query("SELECT product_id, sku, product_name FROM products WHERE status = 'active' ORDER BY product_name");
$products = $products_stmt->fetchAll();

// Get replenishment requests
$status_filter = $_GET['status'] ?? 'pending';
$status_sql = '';
$status_param = [];
if ($status_filter && $status_filter !== 'all') {
    $status_sql = "WHERE rr.status = ?";
    $status_param = [$status_filter];
}

$requests_stmt = $pdo->prepare("SELECT rr.*, p.sku, p.product_name, u.full_name as requested_by_name, au.full_name as approved_by_name,
                                       COALESCE(received.received_to_date, 0) AS received_to_date,
                                       GREATEST(rr.request_qty - COALESCE(received.received_to_date, 0), 0) AS remaining_qty
                                FROM replenishment_requests rr
                                JOIN products p ON rr.product_id = p.product_id
                                JOIN users u ON rr.requested_by = u.user_id
                                LEFT JOIN users au ON rr.approved_by = au.user_id
                                LEFT JOIN (
                                    SELECT replenishment_request_id, SUM(accepted_qty) AS received_to_date
                                    FROM stock_receiving
                                    WHERE replenishment_request_id IS NOT NULL
                                    GROUP BY replenishment_request_id
                                ) received ON received.replenishment_request_id = rr.request_id
                                $status_sql
                                ORDER BY rr.request_date DESC");
$requests_stmt->execute($status_param);
$requests = $requests_stmt->fetchAll();

// Get stored predictions for Random Forest forecast suggestions
$predictions = get_stored_predictions($pdo);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Replenishment Requests</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Replenishment Requests</h1>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="create-section">
            <h3>Create Manual Replenishment Request</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="create">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="product_id">Product *</label>
                        <select name="product_id" id="product_id" required>
                            <option value="">-- Select Product --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['product_id'] ?>">
                                    <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="request_qty">Quantity to Request *</label>
                        <input type="number" name="request_qty" id="request_qty" min="1" required>
                    </div>
                </div>

                <div class="form-group u-mb-1">
                    <label for="notes">Notes / Comments</label>
                    <textarea name="notes" id="notes" placeholder="e.g., Preferred supplier, delivery date..."></textarea>
                </div>

                <button type="submit" class="btn-submit">Create Request</button>
            </form>
        </div>

        <?php if (!empty($predictions)): ?>
            <div class="forecast-section">
                <h3>Random Forest Forecast Suggestions</h3>
                <p>The following products are recommended for reordering based on Random Forest demand forecasts:</p>
                <div class="forecast-items">
                    <?php foreach ($predictions as $pred): ?>
                        <?php if ($pred['reorder_suggested']): ?>
                            <div class="forecast-item">
                                <div class="product"><?= htmlspecialchars($pred['product_name']) ?></div>
                                <div class="prediction">
                                    <strong>SKU:</strong> <?= htmlspecialchars($pred['sku']) ?><br>
                                    <strong>7-day demand:</strong> <?= $pred['predicted_demand_next_7_days'] ?? 'N/A' ?> units<br>
                                    <strong>30-day demand:</strong> <?= $pred['predicted_demand_next_30_days'] ?? 'N/A' ?> units<br>
                                    <strong>Lead-time demand:</strong> <?= $pred['forecasted_demand_during_lead_time'] ?? 'N/A' ?> units over <?= (int)($pred['prediction_supplier_lead_time_days'] ?? $pred['supplier_lead_time_days'] ?? 0) ?> day<?= (int)($pred['prediction_supplier_lead_time_days'] ?? $pred['supplier_lead_time_days'] ?? 0) === 1 ? '' : 's' ?><br>
                                    <strong>Current / incoming:</strong> <?= (int)($pred['current_stock_used'] ?? $pred['current_stock'] ?? 0) ?> / <?= (int)($pred['incoming_stock_used'] ?? $pred['incoming_stock'] ?? 0) ?> units<br>
                                    <strong>Safety stock:</strong> <?= (int)($pred['safety_stock_used'] ?? $pred['safety_stock'] ?? 0) ?> units<br>
                                    <strong>MOQ / case:</strong> <?= (int)($pred['minimum_order_quantity_used'] ?? $pred['minimum_order_quantity'] ?? 1) ?> / <?= (int)($pred['units_per_package_used'] ?? $pred['units_per_package'] ?? 1) ?><br>
                                    <strong>Preferred supplier:</strong> <?= htmlspecialchars($pred['preferred_supplier'] ?: '-') ?><br>
                                    <strong>Suggested qty:</strong> <?= $pred['suggested_reorder_qty'] ?? 'N/A' ?>
                                </div>
                                <div class="confidence">
                                    Confidence: <?= round(($pred['confidence_score'] ?? 0) * 100, 1) ?>%
                                </div>
                                <form class="u-width-full" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="create">
                                    <input type="hidden" name="source" value="ml_forecast">
                                    <input type="hidden" name="product_id" value="<?= $pred['product_id'] ?>">
                                    <input type="hidden" name="forecast_prediction_id" value="<?= (int)($pred['prediction_id'] ?? 0) ?>">
                                    <input type="hidden" name="original_suggested_qty" value="<?= (int)($pred['suggested_reorder_qty'] ?? 0) ?>">
                                    <input type="hidden" name="notes" value="<?= htmlspecialchars("Generated from Random Forest forecast using lead-time demand, safety stock, current stock, incoming stock, MOQ, and package size (confidence: " . round(($pred['confidence_score'] ?? 0) * 100, 1) . "%)") ?>">
                                    <label style="display:block;margin-top:.5rem;font-weight:600">Final request quantity</label>
                                    <input type="number" name="request_qty" min="1" value="<?= (int)($pred['suggested_reorder_qty'] ?? 0) ?>" required style="width:100%;padding:.55rem">
                                    <label style="display:block;margin-top:.5rem;font-weight:600">Override reason (required when changed)</label>
                                    <input class="u-field-compact" type="text" name="override_reason" placeholder="Why should the suggested quantity change?">
                                    <button type="submit" class="btn-add-request">Create Request from Forecast</button>
                                </form>
                                <form class="u-width-full u-mt-05" method="POST" data-required-field="override_reason" data-required-message="Enter a rejection reason." data-confirm="Reject this forecast suggestion?" data-confirm-title="Reject forecast suggestion" data-confirm-button="Reject suggestion" data-confirm-danger="1">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reject_forecast">
                                    <input type="hidden" name="forecast_prediction_id" value="<?= (int)($pred['prediction_id'] ?? 0) ?>">
                                    <input type="hidden" name="product_id" value="<?= (int)$pred['product_id'] ?>">
                                    <input type="hidden" name="original_suggested_qty" value="<?= (int)($pred['suggested_reorder_qty'] ?? 0) ?>">
                                    <input class="u-field-compact-spaced" type="text" name="override_reason" placeholder="Reason for rejecting suggestion">
                                    <button type="submit" class="btn-action btn-reject u-width-full">Reject Forecast Suggestion</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <h3>Request Status</h3>
        <div class="tabs">
            <button class="tab-link <?= $status_filter === 'pending' ? 'active' : '' ?>" onclick="location.href='?status=pending'">Pending</button>
            <button class="tab-link <?= $status_filter === 'approved' ? 'active' : '' ?>" onclick="location.href='?status=approved'">Approved</button>
            <button class="tab-link <?= $status_filter === 'partially_received' ? 'active' : '' ?>" onclick="location.href='?status=partially_received'">Partial</button>
            <button class="tab-link <?= $status_filter === 'rejected' ? 'active' : '' ?>" onclick="location.href='?status=rejected'">Rejected</button>
            <button class="tab-link <?= $status_filter === 'received' ? 'active' : '' ?>" onclick="location.href='?status=received'">Received</button>
            <button class="tab-link <?= $status_filter === 'all' ? 'active' : '' ?>" onclick="location.href='?status=all'">All</button>
        </div>

        <?php if (empty($requests)): ?>
            <p>No replenishment requests found.</p>
        <?php else: ?>
            <?php foreach ($requests as $req): ?>
                <div class="request-card">
                    <div class="request-info">
                        <div class="product-name"><?= htmlspecialchars($req['product_name']) ?></div>
                        <div class="sku"><?= htmlspecialchars($req['sku']) ?></div>
                        <div class="details">
                            Requested by <?= htmlspecialchars($req['requested_by_name']) ?> on <?= htmlspecialchars($req['request_date']) ?>
                            | Source: <?= htmlspecialchars(ucfirst($req['source'])) ?>
                            <?php if ($req['notes']): ?>
                                | <em><?= htmlspecialchars(mb_strimwidth($req['notes'], 0, 60, '...')) ?></em>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="request-qty">
                        <div class="qty"><?= $req['request_qty'] ?></div>
                        <div class="label">Requested</div>
                        <?php if ((int)$req['received_to_date'] > 0): ?>
                            <div class="label">
                                <?= (int)$req['received_to_date'] ?> received<br>
                                <?= (int)$req['remaining_qty'] ?> remaining
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="u-text-right">
                        <div class="u-mb-05">
                            <span class="status-badge <?= $req['status'] ?>">
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $req['status']))) ?>
                            </span>
                        </div>

                        <?php if ($req['status'] === 'pending' && $isAdmin && (int)$req['requested_by'] !== (int)$_SESSION['user_id']): ?>
                            <div class="request-actions">
                                <form class="u-flex-inline" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                                    <button type="submit" class="btn-action btn-approve">Approve</button>
                                </form>
                                <form class="u-flex-inline" method="POST" data-confirm="Reject this replenishment request?" data-confirm-title="Reject request" data-confirm-button="Reject" data-confirm-danger="1">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                                    <button type="submit" class="btn-action btn-reject">Reject</button>
                                </form>
                            </div>
                        <?php elseif ($req['status'] === 'pending'): ?><small>Awaiting review by a different administrator.</small>
                        <?php elseif (in_array($req['status'], ['approved', 'partially_received'], true)): ?>
                            <small>Approved by <?= htmlspecialchars($req['approved_by_name'] ?? 'Unknown') ?> at <?= htmlspecialchars($req['approved_at']) ?></small>
                        <?php else: ?>
                            <small><?php if ($req['approved_by_name']) echo "by " . htmlspecialchars($req['approved_by_name']) . " at " . htmlspecialchars($req['approved_at']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
