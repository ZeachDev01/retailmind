<?php
// components/modals/fiscal_periods.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/admin.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
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
                            <form class="u-flex-inline" method="POST" data-confirm="Closing prevents new entries but keeps records viewable." data-confirm-title="Close fiscal period" data-confirm-button="Close period">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="action" value="close">
                                <input type="hidden" name="period_id" value="<?= $period['period_id'] ?>">
                                <button type="submit" class="btn-action btn-close">Close Period</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($period['status'] === 'closed'): ?>
                            <form class="u-flex-inline" method="POST" data-confirm="Locking makes all period records read-only for compliance." data-confirm-title="Lock fiscal period" data-confirm-button="Lock period" data-confirm-danger="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="action" value="lock">
                                <input type="hidden" name="period_id" value="<?= $period['period_id'] ?>">
                                <button type="submit" class="btn-action btn-lock">Lock Period (Read-only)</button>
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
