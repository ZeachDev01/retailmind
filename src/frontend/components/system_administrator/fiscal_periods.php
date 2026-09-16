<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_role(['admin']);

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $periodName = trim((string)($_POST['period_name'] ?? ''));
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));

        if ($periodName === '' || $startDate === '' || $endDate === '') {
            $error = 'All fields are required';
        } elseif (strtotime($startDate) > strtotime($endDate)) {
            $error = 'Start date must be before end date';
        } else {
            $statement = $pdo->prepare("INSERT INTO fiscal_periods (period_name, start_date, end_date, created_by, status)
                                        VALUES (?, ?, ?, ?, 'open')");
            try {
                $statement->execute([$periodName, $startDate, $endDate, $_SESSION['user_id']]);
                $periodId = (int)$pdo->lastInsertId();
                $message = "Fiscal period \"{$periodName}\" created successfully";
                log_activity(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    'Fiscal period creation',
                    'Fiscal Periods',
                    $periodId,
                    null,
                    [
                        'period_id' => $periodId,
                        'period_name' => $periodName,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'status' => 'open',
                    ]
                );
            } catch (PDOException $exception) {
                $error = 'Error creating period: ' . ($exception->errorInfo[2] ?? $exception->getMessage());
            }
        }
    } elseif ($action === 'close') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $periodId = (int)($_POST['period_id'] ?? 0);
        if ($periodId <= 0) {
            $error = 'Invalid period';
        } else {
            $beforeStatement = $pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?');
            $beforeStatement->execute([$periodId]);
            $before = $beforeStatement->fetch();
            $statement = $pdo->prepare("UPDATE fiscal_periods SET status = 'closed', closed_at = NOW(), closed_by = ?
                                        WHERE period_id = ?");
            if ($statement->execute([$_SESSION['user_id'], $periodId])) {
                $afterStatement = $pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?');
                $afterStatement->execute([$periodId]);
                $message = 'Fiscal period closed successfully';
                log_activity(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    'Fiscal-period closing',
                    'Fiscal Periods',
                    $periodId,
                    $before,
                    $afterStatement->fetch()
                );
            } else {
                $error = 'Error closing period';
            }
        }
    } elseif ($action === 'lock') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $periodId = (int)($_POST['period_id'] ?? 0);
        if ($periodId <= 0) {
            $error = 'Invalid period';
        } else {
            $beforeStatement = $pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?');
            $beforeStatement->execute([$periodId]);
            $before = $beforeStatement->fetch();
            $statement = $pdo->prepare("UPDATE fiscal_periods SET status = 'locked' WHERE period_id = ?");
            if ($statement->execute([$periodId])) {
                $afterStatement = $pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?');
                $afterStatement->execute([$periodId]);
                $message = 'Fiscal period locked successfully';
                log_activity(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    'Fiscal period locked',
                    'Fiscal Periods',
                    $periodId,
                    $before,
                    $afterStatement->fetch()
                );
            } else {
                $error = 'Error locking period';
            }
        }
    }
}

$periodsStatement = $pdo->query("SELECT fp.*, u.full_name AS created_by_name, cu.full_name AS closed_by_name
                                 FROM fiscal_periods fp
                                 LEFT JOIN users u ON fp.created_by = u.user_id
                                 LEFT JOIN users cu ON fp.closed_by = cu.user_id
                                 ORDER BY fp.start_date DESC");
$periods = $periodsStatement->fetchAll();
$statusCounts = ['open' => 0, 'closed' => 0, 'locked' => 0];
foreach ($periods as $period) {
    $status = strtolower((string)$period['status']);
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiscal Periods</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/fiscal-periods.css')) ?>">
</head>
<body class="fiscal-period-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Fiscal Periods</h1>
                    <p class="page-subtitle">Open, close, and lock accounting windows for controlled retail operations.</p>
                </div>
            </div>

            <?php if ($message !== ''): ?>
                <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="message error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card-grid fiscal-period-stats" aria-label="Fiscal period summary">
                <div class="stat-card with-icon success">
                    <span class="stat-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></span>
                    <div class="value"><?= $statusCounts['open'] ?></div>
                    <div class="label">Open Periods</div>
                    <div class="hint">Accepting operational entries</div>
                </div>
                <div class="stat-card with-icon warning">
                    <span class="stat-icon"><i class="bi bi-calendar2-minus" aria-hidden="true"></i></span>
                    <div class="value"><?= $statusCounts['closed'] ?></div>
                    <div class="label">Closed Periods</div>
                    <div class="hint">Available for final review</div>
                </div>
                <div class="stat-card with-icon fiscal-stat-locked">
                    <span class="stat-icon"><i class="bi bi-lock" aria-hidden="true"></i></span>
                    <div class="value"><?= $statusCounts['locked'] ?></div>
                    <div class="label">Locked Periods</div>
                    <div class="hint">Read-only for compliance</div>
                </div>
            </div>

            <section class="dashboard-section fiscal-period-create" aria-labelledby="create-period-title">
                <div class="section-header">
                    <div>
                        <h3 id="create-period-title">Create a fiscal period</h3>
                        <p class="section-description">Define the date range in which transactions can be recorded.</p>
                    </div>
                </div>
                <form method="POST" class="fiscal-period-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="fiscal-period-form-grid">
                        <div class="form-group">
                            <label for="period_name">Period Name</label>
                            <input type="text" id="period_name" name="period_name" placeholder="e.g., Q1 2026" autocomplete="off" required>
                        </div>
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" required>
                        </div>
                        <button type="submit" class="btn btn-primary fiscal-create-button"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create Period</button>
                    </div>
                </form>
            </section>

            <section class="fiscal-period-list" aria-labelledby="period-list-title">
                <div class="fiscal-period-list-heading">
                    <div>
                        <h2 id="period-list-title">Period history</h2>
                        <p>Review status, ownership, and controls for every fiscal period.</p>
                    </div>
                    <span class="fiscal-period-count"><?= count($periods) ?> total</span>
                </div>

                <?php if (empty($periods)): ?>
                    <div class="dashboard-section fiscal-period-empty">
                        <i class="bi bi-calendar2-plus" aria-hidden="true"></i>
                        <strong>No fiscal periods yet</strong>
                        <span>Create the first period using the form above.</span>
                    </div>
                <?php else: ?>
                    <div class="fiscal-period-card-grid">
                        <?php foreach ($periods as $period): ?>
                            <?php $periodStatus = strtolower((string)$period['status']); ?>
                            <article class="fiscal-period-card">
                                <div class="fiscal-period-card-header">
                                    <div class="fiscal-period-title">
                                        <span class="fiscal-period-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
                                        <div>
                                            <h3><?= htmlspecialchars($period['period_name']) ?></h3>
                                            <span><?= htmlspecialchars(date('M j, Y', strtotime((string)$period['start_date']))) ?> – <?= htmlspecialchars(date('M j, Y', strtotime((string)$period['end_date']))) ?></span>
                                        </div>
                                    </div>
                                    <span class="fiscal-status-badge <?= htmlspecialchars($periodStatus) ?>"><?= htmlspecialchars(ucfirst($periodStatus)) ?></span>
                                </div>

                                <dl class="fiscal-period-details">
                                    <div>
                                        <dt>Created by</dt>
                                        <dd><?= htmlspecialchars($period['created_by_name'] ?? 'Unknown') ?></dd>
                                    </div>
                                    <div>
                                        <dt>Created at</dt>
                                        <dd><?= htmlspecialchars(format_display_datetime($period['created_at'])) ?></dd>
                                    </div>
                                    <?php if (in_array($periodStatus, ['closed', 'locked'], true)): ?>
                                        <div>
                                            <dt>Closed by</dt>
                                            <dd><?= htmlspecialchars($period['closed_by_name'] ?? 'Unknown') ?></dd>
                                        </div>
                                        <div>
                                            <dt>Closed at</dt>
                                            <dd><?= htmlspecialchars($period['closed_at'] ? format_display_datetime($period['closed_at']) : 'N/A') ?></dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>

                                <div class="fiscal-period-actions">
                                    <?php if ($periodStatus === 'open'): ?>
                                        <form method="POST" data-confirm="Closing prevents new entries but keeps records viewable." data-confirm-title="Close fiscal period" data-confirm-button="Close period">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="close">
                                            <input type="hidden" name="period_id" value="<?= (int)$period['period_id'] ?>">
                                            <button type="submit" class="btn fiscal-action-close"><i class="bi bi-calendar2-minus" aria-hidden="true"></i> Close Period</button>
                                        </form>
                                    <?php elseif ($periodStatus === 'closed'): ?>
                                        <form method="POST" data-confirm="Locking makes all period records read-only for compliance." data-confirm-title="Lock fiscal period" data-confirm-button="Lock period" data-confirm-danger="1">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="lock">
                                            <input type="hidden" name="period_id" value="<?= (int)$period['period_id'] ?>">
                                            <button type="submit" class="btn fiscal-action-lock"><i class="bi bi-lock" aria-hidden="true"></i> Lock Period</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="fiscal-read-only"><i class="bi bi-shield-lock" aria-hidden="true"></i> Read-only</span>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
