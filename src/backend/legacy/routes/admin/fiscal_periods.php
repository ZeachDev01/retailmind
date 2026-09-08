<?php
// admin/fiscal_periods.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role(['admin']);

// Handle form actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'create') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $period_name = trim($_POST['period_name'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');

        if (!$period_name || !$start_date || !$end_date) {
            $error = 'All fields are required';
        } elseif (strtotime($start_date) > strtotime($end_date)) {
            $error = 'Start date must be before end date';
        } else {
            $stmt = $pdo->prepare("INSERT INTO fiscal_periods (period_name, start_date, end_date, created_by, status) 
                                   VALUES (?, ?, ?, ?, 'open')");
            try {
                $stmt->execute([$period_name, $start_date, $end_date, $_SESSION['user_id']]);
                $periodId = (int)$pdo->lastInsertId();
                $message = "Fiscal period \"$period_name\" created successfully";
                log_activity(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    'Fiscal period creation',
                    'Fiscal Periods',
                    $periodId,
                    null,
                    [
                        'period_id' => $periodId,
                        'period_name' => $period_name,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'status' => 'open',
                    ]
                );
            } catch (PDOException $e) {
                $error = "Error creating period: " . ($e->errorInfo[2] ?? $e->getMessage());
            }
        }
    } elseif ($_POST['action'] === 'close') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $period_id = (int)($_POST['period_id'] ?? 0);
        if ($period_id <= 0) {
            $error = 'Invalid period';
        } else {
            $beforeStmt = $pdo->prepare("SELECT * FROM fiscal_periods WHERE period_id = ?");
            $beforeStmt->execute([$period_id]);
            $before = $beforeStmt->fetch();
            $stmt = $pdo->prepare("UPDATE fiscal_periods SET status = 'closed', closed_at = NOW(), closed_by = ? 
                                   WHERE period_id = ?");
            if ($stmt->execute([$_SESSION['user_id'], $period_id])) {
                $afterStmt = $pdo->prepare("SELECT * FROM fiscal_periods WHERE period_id = ?");
                $afterStmt->execute([$period_id]);
                $message = "Fiscal period closed successfully";
                log_activity(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    'Fiscal-period closing',
                    'Fiscal Periods',
                    $period_id,
                    $before,
                    $afterStmt->fetch()
                );
            } else {
                $error = "Error closing period";
            }
        }
    } elseif ($_POST['action'] === 'lock') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $period_id = (int)($_POST['period_id'] ?? 0);
        if ($period_id <= 0) {
            $error = 'Invalid period';
        } else {
            $beforeStmt = $pdo->prepare("SELECT * FROM fiscal_periods WHERE period_id = ?");
            $beforeStmt->execute([$period_id]);
            $before = $beforeStmt->fetch();
            $stmt = $pdo->prepare("UPDATE fiscal_periods SET status = 'locked' WHERE period_id = ?");
            if ($stmt->execute([$period_id])) {
                $afterStmt = $pdo->prepare("SELECT * FROM fiscal_periods WHERE period_id = ?");
                $afterStmt->execute([$period_id]);
                $message = "Fiscal period locked successfully";
                log_activity($pdo, (int)$_SESSION['user_id'], 'Fiscal period locked', 'Fiscal Periods', $period_id, $before, $afterStmt->fetch());
            } else {
                $error = "Error locking period";
            }
        }
    }
}

// Get all fiscal periods
$periods_stmt = $pdo->query("SELECT fp.*, u.full_name as created_by_name, cu.full_name as closed_by_name
                             FROM fiscal_periods fp
                             LEFT JOIN users u ON fp.created_by = u.user_id
                             LEFT JOIN users cu ON fp.closed_by = cu.user_id
                             ORDER BY fp.start_date DESC");
$periods = $periods_stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiscal Period Management</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <style>
        .create-form {
            background: #f5f5f5;
            padding: 1.5rem;
            border-radius: 4px;
            margin-bottom: 2rem;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .form-group input {
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .btn-submit {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .btn-submit:hover {
            background: #218838;
        }
        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .period-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .period-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .period-name {
            font-size: 1.2rem;
            font-weight: 600;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-badge.open {
            background: #d4edda;
            color: #155724;
        }
        .status-badge.closed {
            background: #fff3cd;
            color: #856404;
        }
        .status-badge.locked {
            background: #f8d7da;
            color: #721c24;
        }
        .period-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-weight: 600;
            color: #666;
            margin-bottom: 0.25rem;
        }
        .period-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-action {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
        }
        .btn-close {
            background: #ffc107;
            color: black;
        }
        .btn-close:hover {
            background: #e0a800;
        }
        .btn-lock {
            background: #dc3545;
            color: white;
        }
        .btn-lock:hover {
            background: #c82333;
        }
        .btn-close:disabled,
        .btn-lock:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../../../../frontend/components/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Fiscal Period Management</h1>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="create-form">
            <h3>Create New Fiscal Period</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="create">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="period_name">Period Name</label>
                        <input type="text" id="period_name" name="period_name" placeholder="e.g., Q1 2024" required>
                    </div>
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" required>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Create Period</button>
            </form>
        </div>

        <h3>Fiscal Periods</h3>
        <?php if (empty($periods)): ?>
            <p>No fiscal periods created yet.</p>
        <?php else: ?>
            <?php foreach ($periods as $period): ?>
                <div class="period-card">
                    <div class="period-header">
                        <div class="period-name"><?= htmlspecialchars($period['period_name']) ?></div>
                        <span class="status-badge <?= htmlspecialchars(strtolower($period['status'])) ?>">
                            <?= htmlspecialchars(ucfirst($period['status'])) ?>
                        </span>
                    </div>

                    <div class="period-details">
                        <div class="detail-item">
                            <span class="detail-label">Date Range</span>
                            <span><?= htmlspecialchars($period['start_date']) ?> to <?= htmlspecialchars($period['end_date']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Created By</span>
                            <span><?= htmlspecialchars($period['created_by_name'] ?? 'Unknown') ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Created At</span>
                            <span><?= htmlspecialchars($period['created_at']) ?></span>
                        </div>
                        <?php if ($period['status'] === 'closed' || $period['status'] === 'locked'): ?>
                            <div class="detail-item">
                                <span class="detail-label">Closed By</span>
                                <span><?= htmlspecialchars($period['closed_by_name'] ?? 'Unknown') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Closed At</span>
                                <span><?= htmlspecialchars($period['closed_at'] ?? 'N/A') ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="period-actions">
                        <?php if ($period['status'] === 'open'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="action" value="close">
                                <input type="hidden" name="period_id" value="<?= $period['period_id'] ?>">
                                <button type="submit" class="btn-action btn-close" onclick="return confirm('Close this fiscal period? This prevents new entries but allows viewing.')">Close Period</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($period['status'] === 'closed'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="action" value="lock">
                                <input type="hidden" name="period_id" value="<?= $period['period_id'] ?>">
                                <button type="submit" class="btn-action btn-lock" onclick="return confirm('Lock this fiscal period? This makes all records read-only for compliance.')">Lock Period (Read-only)</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($period['status'] === 'locked'): ?>
                            <button class="btn-action btn-lock" disabled>Period Locked</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
