<?php
// report/inventory_adjustments.php
require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/includes/functions.php';
require_once __DIR__ . '/../../backend/includes/csrf.php';
require_once __DIR__ . '/../../backend/app/Services/FiscalPeriodGuardService.php';
require_role(['admin', 'cashier']);

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $product_id = (int)($_POST['product_id'] ?? 0);
    $adjustment_qty = (int)($_POST['adjustment_qty'] ?? 0);
    $adjustment_type = trim($_POST['adjustment_type'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($product_id <= 0 || $adjustment_qty <= 0 || !$adjustment_type) {
        $error = 'Product, quantity, and damage type are required';
    } elseif (!in_array($adjustment_type, ['damaged', 'missing', 'expired', 'other'])) {
        $error = 'Invalid damage type';
    } else {
        try {
            (new FiscalPeriodGuardService($pdo))->assertOpenNow('inventory_adjustments', 'inventory adjustment');

            // Insert damage report record (pending approval)
            $stmt = $pdo->prepare("INSERT INTO inventory_adjustments (product_id, adjustment_qty, adjustment_type, 
                                   reported_by, reason, status) 
                                   VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([
                $product_id,
                -$adjustment_qty,  // Negative for loss
                $adjustment_type,
                $_SESSION['user_id'],
                $reason
            ]);

            $adjustment_id = $pdo->lastInsertId();
            $message = "Damage report #$adjustment_id reported and sent for approval.";
            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Stock adjustment reported',
                'Inventory Adjustments',
                (int)$adjustment_id,
                null,
                [
                    'adjustment_id' => (int)$adjustment_id,
                    'product_id' => $product_id,
                    'adjustment_type' => $adjustment_type,
                    'adjustment_qty' => -$adjustment_qty,
                    'reason' => $reason,
                    'status' => 'pending',
                ]
            );
        } catch (Exception $e) {
            $error = "Error recording adjustment: " . $e->getMessage();
        }
    }
}

// Get all active products
$products = $pdo->query("SELECT product_id, sku, product_name FROM products WHERE status = 'active' ORDER BY product_name")->fetchAll();

// Get pending adjustments reported by current user
$pending_stmt = $pdo->prepare("SELECT ia.adjustment_id, ia.product_id, ia.adjustment_qty, ia.adjustment_type, 
                               ia.reported_at, ia.reason, p.sku, p.product_name
                        FROM inventory_adjustments ia
                        JOIN products p ON ia.product_id = p.product_id
                        WHERE ia.reported_by = ? AND ia.status = 'pending'
                        ORDER BY ia.reported_at DESC");
$pending_stmt->execute([$_SESSION['user_id']]);
$pending_adjustments = $pending_stmt->fetchAll();

// Get all adjustments reported by current user (including approved/rejected)
$all_stmt = $pdo->prepare("SELECT ia.adjustment_id, ia.product_id, ia.adjustment_qty, ia.adjustment_type, 
                               ia.reported_at, ia.reason, ia.status, ia.approved_at, ia.approved_by,
                               p.sku, p.product_name, u.full_name as approved_by_name
                        FROM inventory_adjustments ia
                        JOIN products p ON ia.product_id = p.product_id
                        LEFT JOIN users u ON ia.approved_by = u.user_id
                        WHERE ia.reported_by = ?
                        ORDER BY ia.reported_at DESC
                        LIMIT 20");
$all_stmt->execute([$_SESSION['user_id']]);
$all_adjustments = $all_stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damage Report</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../modules/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Damage Report</h1>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="report-form">
            <h3>Report Damage</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

                <div class="form-section">
                    <h4>Product & Damage Type</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="product_id" class="required">Product</label>
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
                            <label for="adjustment_type" class="required">Damage Type</label>
                            <select name="adjustment_type" id="adjustment_type" required onchange="updateTypeInfo()">
                                <option value="">-- Select Type --</option>
                                <option value="damaged">Damaged</option>
                                <option value="missing">Missing / Lost</option>
                                <option value="expired">Expired</option>
                                <option value="other">Other</option>
                            </select>
                            <div class="type-info" id="type-info"></div>
                        </div>
                        <div class="form-group">
                            <label for="adjustment_qty" class="required">Quantity Affected</label>
                            <input type="number" name="adjustment_qty" id="adjustment_qty" min="1" required placeholder="Units affected">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-group">
                        <label for="reason" class="required">Reason / Details</label>
                        <textarea name="reason" id="reason" placeholder="Describe what happened, when discovered, any relevant details..." required></textarea>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Report Damage</button>
            </form>
        </div>

        <?php if (!empty($pending_adjustments)): ?>
            <div class="history-section">
                <h3>⏳ Pending Damage Reports</h3>
                <p>These damage reports are waiting for approval:</p>
                <?php foreach ($pending_adjustments as $adj): ?>
                    <div class="adjustment-card">
                        <div class="adjustment-header">
                            <div>
                                <div class="adjustment-product"><?= htmlspecialchars($adj['product_name']) ?></div>
                                <div class="adjustment-sku"><?= htmlspecialchars($adj['sku']) ?></div>
                                <span class="adjustment-type <?= $adj['adjustment_type'] ?>">
                                    <?= htmlspecialchars(ucfirst($adj['adjustment_type'])) ?>
                                </span>
                            </div>
                            <div class="u-text-right">
                                <div class="adjustment-qty">-<?= abs($adj['adjustment_qty']) ?></div>
                                <span class="adjustment-status pending">Pending</span>
                            </div>
                        </div>
                        <div class="adjustment-details">
                            <strong>Reported:</strong> <?= htmlspecialchars($adj['reported_at']) ?> | 
                            <strong>Adjustment ID:</strong> #<?= $adj['adjustment_id'] ?>
                        </div>
                        <?php if ($adj['reason']): ?>
                            <div class="adjustment-reason">
                                <strong>Details:</strong> <?= htmlspecialchars($adj['reason']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($all_adjustments)): ?>
            <div class="history-section u-mt-2">
                <h3>Damage Report History (My Reports)</h3>

                <div class="tabs">
                    <button class="tab-link active" onclick="filterByStatus('all')">All</button>
                    <button class="tab-link" onclick="filterByStatus('pending')">Pending</button>
                    <button class="tab-link" onclick="filterByStatus('approved')">Approved</button>
                    <button class="tab-link" onclick="filterByStatus('rejected')">Rejected</button>
                </div>

                <div id="adjustments-list">
                    <?php foreach ($all_adjustments as $adj): ?>
                        <div class="adjustment-card <?= $adj['status'] ?>" data-status="<?= $adj['status'] ?>">
                            <div class="adjustment-header">
                                <div>
                                    <div class="adjustment-product"><?= htmlspecialchars($adj['product_name']) ?></div>
                                    <div class="adjustment-sku"><?= htmlspecialchars($adj['sku']) ?></div>
                                    <span class="adjustment-type <?= $adj['adjustment_type'] ?>">
                                        <?= htmlspecialchars(ucfirst($adj['adjustment_type'])) ?>
                                    </span>
                                </div>
                                <div class="u-text-right">
                                    <div class="adjustment-qty">-<?= abs($adj['adjustment_qty']) ?></div>
                                    <span class="adjustment-status <?= $adj['status'] ?>">
                                        <?= htmlspecialchars(ucfirst($adj['status'])) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="adjustment-details">
                                <strong>Reported:</strong> <?= htmlspecialchars($adj['reported_at']) ?> | 
                                <strong>Report ID:</strong> #<?= $adj['adjustment_id'] ?>
                                <?php if ($adj['status'] !== 'pending'): ?>
                                    <br><strong>Approved by:</strong> <?= htmlspecialchars($adj['approved_by_name'] ?? 'Unknown') ?> at <?= htmlspecialchars($adj['approved_at']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($adj['reason']): ?>
                                <div class="adjustment-reason">
                                    <strong>Details:</strong> <?= htmlspecialchars($adj['reason']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function updateTypeInfo() {
    const type = document.getElementById('adjustment_type').value;
    const infoDiv = document.getElementById('type-info');
    
    const info = {
        damaged: 'Item physically damaged or unusable',
        missing: 'Item lost or cannot be located in inventory',
        expired: 'Item past expiration date and cannot be sold',
        other: 'Other inventory discrepancy'
    };
    
    infoDiv.textContent = info[type] || '';
}

function filterByStatus(status) {
    const cards = document.querySelectorAll('[data-status]');
    
    // Update active tab
    document.querySelectorAll('.tab-link').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    // Filter cards
    cards.forEach(card => {
        if (status === 'all' || card.getAttribute('data-status') === status) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}
</script>
</body>
</html>
