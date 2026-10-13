<?php
// dashboard/notifications.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

$user_id = (int)$_SESSION['user_id'];
$category_labels = [
    'low_stock' => 'Low stock',
    'replenishment' => 'Replenishment',
    'adjustment' => 'Inventory adjustments',
    'system' => 'System',
    'fiscal_period' => 'Fiscal periods',
    'expiring_stock' => 'Expiring stock',
    'expired_stock' => 'Expired stock',
    'attention' => 'Attention',
];
$stmt = $pdo->prepare('SELECT type, COUNT(*) AS total, SUM(CASE WHEN is_read = FALSE THEN 1 ELSE 0 END) AS unread FROM notifications WHERE user_id = ? GROUP BY type ORDER BY type');
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
$category = $_GET['category'] ?? '';
if (!is_string($category) || !isset($categories[$category])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $category !== '') {
        http_response_code(400);
        exit('Choose an available notification category and try again.');
    }
    $category = '';
}
$batch = filter_var($_GET['batch'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$total = $category === '' ? array_sum(array_column($categories, 'total')) : (int)$categories[$category]['total'];
$unread_count = $category === '' ? array_sum(array_column($categories, 'unread')) : (int)$categories[$category]['unread'];
$batch = min($batch, max(1, (int)ceil($total / 10)));
$limit = min($total, $batch * 10);
$where = 'user_id = ?' . ($category === '' ? '' : ' AND type = ?');
$params = $category === '' ? [$user_id] : [$user_id, $category];
$view_query = http_build_query(['category' => $category, 'batch' => $batch]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    if ($_POST['action'] === 'mark_read') {
        $notification_id = (int)($_POST['notification_id'] ?? 0);
        if ($notification_id > 0) {
            mark_notification_read($pdo, $notification_id, $user_id);
        }
    } elseif ($_POST['action'] === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = TRUE WHERE ' . $where . ' AND is_read = FALSE');
        $stmt->execute($params);
    }
    header('Location: ' . app_url('components/notification/notifications.php') . '?' . $view_query);
    exit;
}

$stmt = $pdo->prepare('SELECT notification_id, type, title, message, reference_type, reference_id, is_read, created_at FROM notifications WHERE ' . $where . ' ORDER BY created_at DESC, notification_id DESC LIMIT ' . (int)$limit);
$stmt->execute($params);
$notifications = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/notifications.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content notifications-page">
            <header class="notification-heading">
                <h1>Notifications</h1>
                <p>Review Store updates and mark them as read when you're done.</p>
            </header>

            <div class="notification-toolbar">
            <form class="notification-filter" method="GET">
                <label for="notification-category">Category</label>
                <select id="notification-category" name="category">
                    <option value="">All</option>
                    <?php foreach ($categories as $type => $counts): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $category === $type ? 'selected' : '' ?>><?= htmlspecialchars($category_labels[$type] ?? ucfirst(str_replace('_', ' ', $type))) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="mark-all-btn">Apply filter</button>
            </form>

            <div class="notifications-header">
                <div>
                    <?php if ($unread_count > 0): ?>
                        <span class="unread-badge"><?= $unread_count ?> Unread</span>
                    <?php else: ?>
                        <span class="notification-caught-up">All caught up. No unread notifications<?= $category !== '' ? ' in this category' : '' ?>.</span>
                    <?php endif; ?>
                </div>
                <?php if ($unread_count > 0): ?>
                    <form class="u-flex-inline" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="mark-all-btn btn-secondary"><?= $category === '' ? 'Mark all as read' : 'Mark category as read' ?></button>
                    </form>
                <?php endif; ?>
            </div>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></div>
                    <p><?= $category === '' ? "No notifications yet. You'll see activity updates here." : 'No notifications in this category.' ?></p>
                </div>
            <?php else: ?>
                <section class="notification-list" aria-label="<?= $category === '' ? 'All notifications' : htmlspecialchars($category_labels[$category] ?? ucfirst(str_replace('_', ' ', $category))) ?>">
                <?php foreach ($notifications as $notif): ?>
                    <article class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>">
                        <div class="notification-content">
                            <div class="notification-meta"><span><?= htmlspecialchars($category_labels[$notif['type']] ?? ucfirst(str_replace('_', ' ', $notif['type']))) ?></span><?php if (!$notif['is_read']): ?><span class="notification-unread-label">Unread</span><?php endif; ?></div>
                            <h2><?= htmlspecialchars($notif['title']) ?></h2>
                            <p><?= htmlspecialchars($notif['message']) ?></p>
                        </div>
                        <div class="u-action-summary">
                            <div class="notification-time"><?= htmlspecialchars(format_display_datetime($notif['created_at'])) ?></div>
                            <?php if (!$notif['is_read']): ?>
                                <form class="u-flex-inline u-mt-05" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= $notif['notification_id'] ?>">
                                    <button type="submit" class="btn-mark-read btn-secondary">Mark Read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </section>

                <div class="notification-more">
                    <p class="u-text-muted-legacy">Showing <?= count($notifications) ?> of <?= (int)$total ?> notifications</p>
                    <?php if ($limit < $total): ?>
                        <a class="mark-all-btn" href="?<?= htmlspecialchars(http_build_query(['category' => $category, 'batch' => $batch + 1])) ?>">Show more</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>
