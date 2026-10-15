<?php
// components/inventory_management/replenishment_requests.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_role(['admin', 'super_admin', 'inventory_manager']);
$isAdmin = current_role() === 'admin';

$message = '';
$error = '';

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    try {
        $forecastJobId = null;
        if ($action === 'reject_forecast' || ($action === 'create' && ($_POST['source'] ?? '') === 'ml_forecast')) {
            require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
            App\Store\StoreWriteGate::begin($pdo);
            $blockedRecommendations = $pdo->query("SELECT setting_key FROM platform_settings
                WHERE setting_key IN ('ml_observation_mode', 'ml_recommendations_suspended')
                  AND setting_value IN ('1', 'true') FOR UPDATE");
            if ($blockedRecommendations->fetchColumn()) {
                throw new RuntimeException('Forecast-backed purchasing is unavailable during observation or suspension. Manual requests remain available.');
            }
            $forecastJobId = (int)($_POST['forecast_job_id'] ?? 0);
            [$scopeSql, $scopeParams] = store_product_scope('p');
            $forecastStmt = $pdo->prepare("SELECT df.forecast_id FROM demand_forecasts df
                JOIN ml_jobs j ON j.job_id = df.job_id
                JOIN products p ON p.product_id = df.product_id
                WHERE df.job_id = ? AND df.product_id = ? AND j.trigger_type = 'forecast_generation'
                  AND j.status = 'succeeded' AND p.status = 'active'{$scopeSql} LIMIT 1 FOR UPDATE");
            $forecastStmt->execute(array_merge([$forecastJobId, (int)($_POST['product_id'] ?? 0)], $scopeParams));
            if (!$forecastStmt->fetchColumn()) {
                throw new RuntimeException('The forecast source is unavailable for this product. Refresh and try again.');
            }
        }
        if ($action === 'create') {
            require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
            $productId = (int)($_POST['product_id'] ?? 0);
            $requestQty = (int)($_POST['request_qty'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $source = ($_POST['source'] ?? '') === 'ml_forecast' ? 'ml_forecast' : 'manual';
            $originalQty = $source === 'ml_forecast' ? max(0, (int)($_POST['original_suggested_qty'] ?? 0)) : null;
            $overrideReason = trim((string)($_POST['override_reason'] ?? ''));
            if ($productId <= 0 || $requestQty <= 0) {
                throw new RuntimeException('Product and quantity are required.');
            }
            [$scopeSql, $scopeParams] = store_product_scope('p');
            $productStmt = $pdo->prepare("SELECT product_id FROM products p WHERE p.product_id = ?{$scopeSql}");
            $productStmt->execute(array_merge([$productId], $scopeParams));
            if (!$productStmt->fetchColumn()) {
                throw new RuntimeException('Product not found in this Store.');
            }
            if ($source === 'ml_forecast' && $originalQty !== null && $requestQty !== $originalQty && $overrideReason === '') {
                throw new RuntimeException('Explain why the forecast quantity was changed.');
            }
            App\Store\StoreWriteGate::begin($pdo);
            $stmt = $pdo->prepare("INSERT INTO replenishment_requests
                (product_id, request_qty, requested_by, source, forecast_job_id, original_suggested_qty, override_reason, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $requestQty, (int)$_SESSION['user_id'], $source, $forecastJobId, $originalQty, $overrideReason ?: null, $notes ?: null]);
            $requestId = (int)$pdo->lastInsertId();
            if ($source === 'ml_forecast') {
                $decision = $requestQty === $originalQty ? 'accepted' : 'modified';
                $pdo->prepare("INSERT INTO forecast_decisions
                    (forecast_job_id, product_id, original_suggested_qty, final_quantity, decision, override_reason, decided_by, replenishment_request_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$forecastJobId, $productId, $originalQty, $requestQty, $decision, $overrideReason ?: null, (int)$_SESSION['user_id'], $requestId]);
            }
            log_activity($pdo, (int)$_SESSION['user_id'], 'Replenishment request creation', 'Replenishment', $requestId, null, [
                'product_id' => $productId,
                'request_qty' => $requestQty,
                'source' => $source,
                'forecast_job_id' => $forecastJobId,
                'original_suggested_qty' => $originalQty,
                'override_reason' => $overrideReason,
                'status' => 'pending'
            ]);
            $pdo->commit();
            $message = "Replenishment request #{$requestId} created successfully";
        } elseif ($action === 'reject_forecast') {
            require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
            $productId = (int)($_POST['product_id'] ?? 0);
            $originalQty = max(0, (int)($_POST['original_suggested_qty'] ?? 0));
            $reason = trim((string)($_POST['override_reason'] ?? ''));
            if ($productId <= 0 || $reason === '') {
                throw new RuntimeException('A reason is required to reject a forecast suggestion.');
            }
            $pdo->prepare("INSERT INTO forecast_decisions
                (forecast_job_id, product_id, original_suggested_qty, final_quantity, decision, override_reason, decided_by)
                VALUES (?, ?, ?, 0, 'rejected', ?, ?)")
                ->execute([$forecastJobId, $productId, $originalQty, $reason, (int)$_SESSION['user_id']]);
            $pdo->commit();
            $message = 'Forecast suggestion rejected and recorded.';
        } elseif (in_array($action, ['approve', 'reject'], true)) {
            if (!$isAdmin) {
                throw new RuntimeException('Only an administrator can approve or reject replenishment requests.');
            }
            $requestId = (int)($_POST['request_id'] ?? 0);
            if ($requestId <= 0) {
                throw new RuntimeException('Invalid request ID.');
            }
            App\Store\StoreWriteGate::begin($pdo);
            [$scopeSql, $scopeParams] = store_product_scope('p');
            $beforeStmt = $pdo->prepare("SELECT rr.* FROM replenishment_requests rr JOIN products p ON p.product_id = rr.product_id WHERE rr.request_id = ?{$scopeSql} FOR UPDATE");
            $beforeStmt->execute(array_merge([$requestId], $scopeParams));
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
            $stmt = $pdo->prepare("UPDATE replenishment_requests rr JOIN products p ON p.product_id = rr.product_id
                                  SET rr.status = ?, rr.approved_by = ?, rr.approved_at = NOW()
                                  WHERE rr.request_id = ? AND rr.status = 'pending' AND rr.requested_by <> ?{$scopeSql}");
            $stmt->execute(array_merge([$newStatus, (int)$_SESSION['user_id'], $requestId, (int)$_SESSION['user_id']], $scopeParams));
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
        $error = \App\Support\OperatorAlert::message($e, 'The replenishment request could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.');
    }
}

// Get all products in the server-derived Store scope.
[$scopeSql, $scopeParams] = store_product_scope('p');
$productsStmt = $pdo->prepare("SELECT product_id, sku, product_name FROM products p WHERE status = 'active'{$scopeSql} ORDER BY product_name");
$productsStmt->execute($scopeParams);
$products = $productsStmt->fetchAll();

// Get replenishment requests.
$status_filter = $_GET['status'] ?? 'pending';
$status_sql = 'WHERE 1 = 1';
$status_param = [];
if ($status_filter && $status_filter !== 'all') {
    $status_sql .= ' AND rr.status = ?';
    $status_param[] = $status_filter;
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
                                $status_sql{$scopeSql}
                                ORDER BY rr.request_date DESC");
$requests_stmt->execute(array_merge($status_param, $scopeParams));
$requests = $requests_stmt->fetchAll();

// Load forecast-backed replenishment recommendations
$forecastRecommendations = [];
$activeModelVersion = 'Approved Model';
$isDemo = false;

try {
    $forecastService = new \App\Forecasting\DemandForecastService($pdo);
    $latestJobId = $forecastService->getLatestSuccessfulJobId();
    if ($latestJobId) {
        $jobMeta = $forecastService->getJobMetadata($latestJobId);
        $activeModelVersion = $jobMeta['model_version'] ?? 'Approved Model';
        $isDemo = $jobMeta['is_demo'] ?? false;

        $queueService = new \App\Forecasting\ReplenishmentQueueService($pdo);
        $fullQueue = $queueService->getQueue($latestJobId);
        foreach ($fullQueue as $item) {
            if ($item['urgency_label'] === 'Urgent' || $item['urgency_label'] === 'Watch' || $item['recommended_quantity'] > 0) {
                $forecastRecommendations[] = $item;
            }
        }
    }
} catch (Throwable $e) {
    // If forecasting tables are empty or not configured yet, degrade gracefully
}

$selectedProductId = (int)($_GET['product_id'] ?? 0);

?>
<!DOCTYPE html>
<html>

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Replenishment Requests</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/inventory.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
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
                                    <option value="<?= $p['product_id'] ?>" <?= $selectedProductId === (int)$p['product_id'] ? 'selected' : '' ?>>
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

            <?php if (!empty($forecastRecommendations)): ?>
                <div class="forecast-section u-mb-2">
                    <div class="u-flex-between u-mb-1">
                        <div>
                            <h3>Demand Forecast Recommendations (Non-Binding)</h3>
                            <p class="text-muted">
                                Products recommended for review based on Random Forest model
                                <strong><?= htmlspecialchars($activeModelVersion) ?></strong>.
                                Recommendations require human review and standard purchasing approval.
                            </p>
                        </div>
                        <?php if ($isDemo): ?>
                            <div>
                                <span class="badge tag-info">Demo Mode</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="forecast-items">
                        <?php foreach ($forecastRecommendations as $rec): ?>
                            <div id="forecast-product-<?= (int)$rec['product_id'] ?>" class="forecast-card u-mb-1 p-3 border rounded <?= $rec['urgency_label'] === 'Urgent' ? 'border-danger' : 'border-warning' ?>" style="background: var(--surface, #fff);">
                                <div class="u-flex-between u-mb-1">
                                    <div>
                                        <h4 class="u-m-0">
                                            <?= htmlspecialchars($rec['product_name']) ?>
                                            <small class="text-muted">(<?= htmlspecialchars($rec['sku']) ?>)</small>
                                        </h4>
                                        <div class="badges u-mt-05">
                                            <span class="badge <?= $rec['urgency_label'] === 'Urgent' ? 'tag-danger' : 'tag-warning' ?>">
                                                <?= htmlspecialchars($rec['urgency_label']) ?>
                                            </span>
                                            <span class="badge tag-secondary">Readiness: <?= htmlspecialchars($rec['readiness_level'] ?? 'Unknown') ?></span>
                                            <?php if ($rec['forecast_origin'] && strtotime((string)$rec['forecast_origin']) < strtotime('-3 days')): ?>
                                                <span class="badge tag-warning">Stale Forecast (Origin: <?= htmlspecialchars($rec['forecast_origin']) ?>)</span>
                                            <?php else: ?>
                                                <span class="badge tag-light">Origin: <?= htmlspecialchars($rec['forecast_origin'] ?? 'N/A') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="u-text-right">
                                        <div class="text-muted"><small>Stock Exposure</small></div>
                                        <div class="fw-bold <?= ($rec['stock_exposure'] ?? 0) < 0 ? 'text-danger' : '' ?>">
                                            <?= $rec['stock_exposure'] !== null ? $rec['stock_exposure'] . ' units' : 'Unknown' ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="rec-details text-muted u-mb-1">
                                    <span><strong>Current Stock:</strong> <?= (int)$rec['current_stock'] ?></span> |
                                    <span><strong>Reliable Inbound:</strong> <?= (int)($rec['inbound_quantity'] ?? 0) ?></span>
                                    <?php if (!empty($rec['unreliable_inbound_quantity'])): ?>
                                        <small class="text-muted">(<?= (int)$rec['unreliable_inbound_quantity'] ?> late/untimed excluded)</small>
                                    <?php endif; ?> |
                                    <span><strong>Lead Time:</strong> <?= (int)$rec['lead_time_days'] ?>d (Demand: <?= (int)$rec['forecast_lead_time'] ?>)</span> |
                                    <span><strong>7-Day Demand:</strong> <?= (int)$rec['forecast_7_day'] ?></span> |
                                    <span><strong>28-Day Demand:</strong> <?= (int)$rec['forecast_28_day'] ?></span> |
                                    <span><strong>MOQ:</strong> <?= (int)$rec['moq'] ?></span>
                                </div>
                                <p class="explanation u-mb-1"><small><em><?= htmlspecialchars($rec['explanation']) ?></em></small></p>

                                <?php if (!$rec['recommendations_active']): ?>
                                    <p class="text-muted">Forecast-backed purchasing is unavailable during observation or suspension. Manual requests remain available.</p>
                                <?php else: ?>
                                <div class="rec-actions u-flex-wrap gap-2">
                                    <form class="u-flex-inline u-flex-grow" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="create">
                                        <input type="hidden" name="source" value="ml_forecast">
                                        <input type="hidden" name="product_id" value="<?= (int)$rec['product_id'] ?>">
                                        <input type="hidden" name="forecast_job_id" value="<?= (int)$latestJobId ?>">
                                        <input type="hidden" name="original_suggested_qty" value="<?= (int)$rec['recommended_quantity'] ?>">
                                        <input type="hidden" name="notes" value="<?= htmlspecialchars("Forecast recommendation from {$activeModelVersion}: lead time {$rec['lead_time_days']}d, exposure " . ($rec['stock_exposure'] ?? 0)) ?>">

                                        <div class="u-flex gap-2 align-items-center">
                                            <div>
                                                <label class="u-text-xs" style="display:block;font-size:0.75rem;">Request Qty</label>
                                                <input type="number" aria-label="Request quantity" name="request_qty" min="1" value="<?= max(1, (int)$rec['recommended_quantity']) ?>" required style="width: 100px; padding: 0.35rem;">
                                            </div>
                                            <div class="u-flex-grow">
                                                <label class="u-text-xs" style="display:block;font-size:0.75rem;">Override Reason (if modifying quantity)</label>
                                                <input type="text" aria-label="Reason for changing quantity" name="override_reason" placeholder="Explain why quantity was changed" style="width: 100%; padding: 0.35rem;">
                                            </div>
                                            <div style="align-self: flex-end;">
                                                <button type="submit" class="btn-submit" style="padding: 0.4rem 0.8rem;">Create Request from Forecast</button>
                                            </div>
                                        </div>
                                    </form>

                                    <form class="u-flex-inline" method="POST" data-confirm="Dismiss this forecast recommendation?" data-confirm-title="Dismiss recommendation" data-confirm-button="Dismiss" data-confirm-danger="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reject_forecast">
                                        <input type="hidden" name="forecast_job_id" value="<?= (int)$latestJobId ?>">
                                        <input type="hidden" name="product_id" value="<?= (int)$rec['product_id'] ?>">
                                        <input type="hidden" name="original_suggested_qty" value="<?= (int)$rec['recommended_quantity'] ?>">
                                        <input type="hidden" name="override_reason" value="Dismissed by operator during review">
                                        <div style="align-self: flex-end;">
                                            <button type="submit" class="btn-action btn-reject" style="padding: 0.4rem 0.8rem;">Dismiss</button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <h3>Request Status</h3>
            <div class="tabs">
                <button type="button" class="tab-link <?= $status_filter === 'pending' ? 'active' : '' ?>" onclick="RetailMindUI.navigate('?status=pending')">Pending</button>
                <button type="button" class="tab-link <?= $status_filter === 'approved' ? 'active' : '' ?>" onclick="RetailMindUI.navigate('?status=approved')">Approved</button>
                <button type="button" class="tab-link <?= $status_filter === 'partially_received' ? 'active' : '' ?>" onclick="RetailMindUI.navigate('?status=partially_received')">Partial</button>
                <button type="button" class="tab-link <?= $status_filter === 'rejected' ? 'active' : '' ?>" onclick="RetailMindUI.navigate('?status=rejected')">Rejected</button>
                <button type="button" class="tab-link <?= $status_filter === 'received' ? 'active' : '' ?>" onclick="RetailMindUI.navigate('?status=received')">Received</button>
                <button type="button" class="tab-link <?= $status_filter === 'all' ? 'active' : '' ?>" onclick="RetailMindUI.navigate('?status=all')">All</button>
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
                                <small>Approved by <?= htmlspecialchars($req['approved_by_name'] ?? 'Unknown') ?> at <?= htmlspecialchars(format_display_datetime($req['approved_at'])) ?></small>
                            <?php else: ?>
                                <small><?php if ($req['approved_by_name']) echo "by " . htmlspecialchars($req['approved_by_name']) . " at " . htmlspecialchars(format_display_datetime($req['approved_at'])); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>
