<?php
// dashboard/notifications.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

// Get all notifications for current user
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$sql = "SELECT notification_id, type, title, message, reference_type, reference_id, is_read, created_at
                       FROM notifications WHERE user_id = ?
                       ORDER BY created_at DESC
                       LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// Get total count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$count_stmt->execute([$_SESSION['user_id']]);
$total = $count_stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Get unread count
$unread_count = get_notification_count($pdo, $_SESSION['user_id']);

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    if ($_POST['action'] === 'mark_read') {
        $notification_id = (int)($_POST['notification_id'] ?? 0);
        if ($notification_id > 0) {
            mark_notification_read($pdo, $notification_id);
        }
    } elseif ($_POST['action'] === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
        $stmt->execute([$_SESSION['user_id']]);
    }
    header('Location: ' . app_url('Sidebar/notification/notifications.php'));
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <style>
        .notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .unread-badge {
            background: #dc3545;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .mark-all-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .mark-all-btn:hover {
            background: #5a6268;
        }
        .notification-item {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 1rem;
            align-items: start;
        }
        .notification-item.unread {
            background: #f0f7ff;
            border-left: 4px solid #007bff;
        }
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .notification-icon.low_stock {
            background: #fff3cd;
            color: #856404;
        }
        .notification-icon.replenishment {
            background: #d1ecf1;
            color: #0c5460;
        }
        .notification-icon.adjustment {
            background: #f8d7da;
            color: #721c24;
        }
        .notification-icon.system {
            background: #d4edda;
            color: #155724;
        }
        .notification-icon.fiscal_period {
            background: #e7e8ea;
            color: #383d41;
        }
        .notification-content h4 {
            margin: 0 0 0.5rem 0;
            font-size: 1rem;
        }
        .notification-content p {
            margin: 0;
            color: #666;
            font-size: 0.95rem;
            line-height: 1.4;
        }
        .notification-time {
            color: #999;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        .notification-actions {
            display: flex;
            gap: 0.5rem;
        }
        .btn-mark-read {
            background: #007bff;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            text-decoration: none;
            display: inline-block;
        }
        .btn-mark-read:hover {
            background: #0056b3;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #666;
        }
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .pagination {
            display: flex;
            gap: 0.5rem;
            margin-top: 2rem;
            align-items: center;
            justify-content: center;
        }
        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #007bff;
        }
        .pagination a:hover {
            background: #f5f5f5;
        }
        .pagination .current {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Notifications</h1>
        </div>

        <div class="notifications-header">
            <div>
                <?php if ($unread_count > 0): ?>
                    <span class="unread-badge"><?= $unread_count ?> Unread</span>
                <?php else: ?>
                    <span style="color: #666;">All caught up!</span>
                <?php endif; ?>
            </div>
            <?php if ($unread_count > 0): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="mark-all-btn">Mark All as Read</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🔔</div>
                <p>No notifications yet. You'll see activity updates here.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>">
                    <div class="notification-icon <?= htmlspecialchars($notif['type']) ?>">
                        <?php
                        $icons = [
                            'low_stock' => '⚠️',
                            'replenishment' => '📦',
                            'adjustment' => '🔧',
                            'system' => 'ℹ️',
                            'fiscal_period' => '📋',
                            'expiring_stock' => '⏳',
                            'expired_stock' => '⛔'
                        ];
                        echo $icons[$notif['type']] ?? '•';
                        ?>
                    </div>
                    <div class="notification-content">
                        <h4><?= htmlspecialchars($notif['title']) ?></h4>
                        <p><?= htmlspecialchars($notif['message']) ?></p>
                    </div>
                    <div style="text-align: right; min-width: 120px;">
                        <div class="notification-time"><?= htmlspecialchars(date('M d, H:i', strtotime($notif['created_at']))) ?></div>
                        <?php if (!$notif['is_read']): ?>
                            <form method="POST" style="display: inline; margin-top: 0.5rem;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= $notif['notification_id'] ?>">
                                <button type="submit" class="btn-mark-read">Mark Read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1">« First</a>
                        <a href="?page=<?= $page - 1 ?>">‹ Prev</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>">Next ›</a>
                        <a href="?page=<?= $total_pages ?>">Last »</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
