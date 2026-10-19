<?php
// dashboard/notifications.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/NotificationService.php';

$user_id = (int)$_SESSION['user_id'];
$category_labels = [
    'low_stock' => 'Low stock',
    'adjustment' => 'Inventory adjustments',
    'system' => 'System',
    'fiscal_period' => 'Fiscal periods',
    'expiring_stock' => 'Expiring stock',
    'expired_stock' => 'Expired stock',
    'attention' => 'Attention',
];
$notification_source = "notifications LEFT JOIN products p ON notifications.reference_type = 'product' AND p.product_id = notifications.reference_id";
$visible_notifications = "(notifications.type <> 'low_stock' OR p.product_id IS NOT NULL)";
$stmt = $pdo->prepare('SELECT notifications.type, COUNT(*) AS total, SUM(CASE WHEN notifications.is_read = FALSE THEN 1 ELSE 0 END) AS unread FROM ' . $notification_source . ' WHERE notifications.user_id = ? AND ' . $visible_notifications . ' GROUP BY notifications.type ORDER BY notifications.type');
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
$view = $_GET['view'] ?? 'all';
if (!is_string($view) || !in_array($view, ['all', 'unread'], true)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(400);
        exit('Choose All or Unread notifications and try again.');
    }
    $view = 'all';
}
$batch = filter_var($_GET['batch'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$unread_count = $category === '' ? array_sum(array_column($categories, 'unread')) : (int)$categories[$category]['unread'];
$total = $view === 'unread' ? $unread_count : ($category === '' ? array_sum(array_column($categories, 'total')) : (int)$categories[$category]['total']);
$batch = min($batch, max(1, (int)ceil($total / 10)));
$limit = min($total, $batch * 10);
$where = 'notifications.user_id = ?' . ($category === '' ? '' : ' AND notifications.type = ?');
$params = $category === '' ? [$user_id] : [$user_id, $category];
$view_query = http_build_query(['category' => $category, 'view' => $view, 'batch' => $batch]);

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

$list_where = $where . ' AND ' . $visible_notifications . ($view === 'unread' ? ' AND notifications.is_read = FALSE' : '');
$stmt = $pdo->prepare('SELECT notifications.notification_id, notifications.type, notifications.title, notifications.message, notifications.reference_type, notifications.reference_id, notifications.is_read, notifications.created_at, p.product_name, p.sku FROM ' . $notification_source . ' WHERE ' . $list_where . ' ORDER BY notifications.created_at DESC, notifications.notification_id DESC LIMIT ' . (int)$limit);
$stmt->execute($params);
$notifications = $stmt->fetchAll();
$batch_reminders = (new NotificationService($pdo))->getBatchReminders($user_id);
$inventory_workspace = in_array(current_role(), ['inventory_manager', 'admin', 'super_admin'], true);
$active_batch_reminders = $inventory_workspace
    ? array_filter($batch_reminders, static fn(array $reminder): bool => $reminder['active']) : [];
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/notifications.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/notifications-utilities.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content notifications-page">
            <header class="notification-heading">
                <h1>Notifications</h1>
            </header>

            <?php if ($active_batch_reminders): ?>
                <section class="active-batch-reminders" aria-label="Active batch reminders">
                    <h2>Active batch reminders</h2>
                    <div class="notification-list">
                    <?php foreach ($active_batch_reminders as $reminder): ?>
                        <article class="notification-item notification-priority">
                            <div class="notification-content">
                                <div class="notification-row grid items-center gap-4 p-5 sm:p-6">

                                    <h3 class="min-w-0"><a class="notification-product-link" href="<?= htmlspecialchars(app_url('components/inventory_management/products.php?' . http_build_query(['q' => $reminder['sku']]))) ?>"><?= htmlspecialchars($reminder['title']) ?></a></h3>
                                    <span class="notification-category rounded-md px-3 py-1">Action needed</span>

                                </div>
                                <div class="notification-details">
                                    <div class="min-h-0 overflow-hidden">
                                        <div class="notification-description space-y-4 p-5 sm:p-6">
                                            <p><?= htmlspecialchars($reminder['message']) ?></p>
                                            <?php if (current_role() === 'inventory_manager'): ?>
                                                <a class="mark-all-btn" href="<?= htmlspecialchars(app_url('components/inventory_management/supplier_pullouts.php?' . http_build_query(['product_id' => $reminder['product_id'], 'batch_id' => $reminder['batch_id']]))) ?>">Record Supplier Pullout</a>
                                            <?php else: ?>
                                                <a class="mark-all-btn" href="<?= htmlspecialchars(app_url('components/inventory_management/supplier_pullouts.php?' . http_build_query(['product_id' => $reminder['product_id'], 'batch_id' => $reminder['batch_id']]))) ?>">View Supplier Pullout History</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="notification-toolbar">
            <nav class="notification-views" aria-label="Notification view">
                <a href="?<?= htmlspecialchars(http_build_query(['category' => $category, 'view' => 'all'])) ?>" <?= $view === 'all' ? 'aria-current="page"' : '' ?>>All</a>
                <a href="?<?= htmlspecialchars(http_build_query(['category' => $category, 'view' => 'unread'])) ?>" <?= $view === 'unread' ? 'aria-current="page"' : '' ?>>Unread <span class="notification-view-count"><?= (int)$unread_count ?></span></a>
            </nav>
            <form class="notification-filter" method="GET">
                <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                <label for="notification-category">Category</label>
                <select id="notification-category" name="category">
                    <option value="">All</option>
                    <?php foreach ($categories as $type => $counts): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $category === $type ? 'selected' : '' ?>><?= htmlspecialchars($category_labels[$type] ?? ucfirst(str_replace('_', ' ', $type))) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="mark-all-btn btn-secondary">Apply filter</button>
            </form>

            <div class="notifications-header">
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
                    <?php if ($view === 'unread'): ?>
                        <h2>You're all caught up</h2>
                        <a class="mark-all-btn" href="?<?= htmlspecialchars(http_build_query(['category' => $category, 'view' => 'all'])) ?>">View all notifications</a>
                    <?php else: ?>
                        <p><?= $category === '' ? 'No notifications yet.' : 'No notifications in this category.' ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <section class="notification-list" aria-label="<?= $view === 'unread' ? 'Unread notifications' : 'All notifications' ?><?= $category === '' ? '' : ': ' . htmlspecialchars($category_labels[$category] ?? ucfirst(str_replace('_', ' ', $category))) ?>">
                <?php foreach ($notifications as $notif): ?>
                    <?php
                    $batch_history = in_array($notif['type'], ['expiring_stock', 'expired_stock', 'system'], true)
                        && ($notif['reference_type'] === 'product_batch' || str_starts_with($notif['reference_type'] ?? '', 'product_batch_expiry_'));
                    $product_search = $notif['sku'];
                    if ($batch_history) {
                        $reminder = $batch_reminders[(int)$notif['reference_id']] ?? null;
                        $product_search = $reminder['sku'] ?? null;
                        $notif['title'] = 'Expiry alert history: ' . $notif['title'];
                        $notif['message'] = $reminder
                            ? "Batch {$reminder['batch_number']} (#{$reminder['batch_id']}). Expiration: " . ($reminder['expiration_date'] ?? 'Not recorded') . ". Current remaining: {$reminder['remaining_quantity']}. " . ($reminder['active'] ? 'See Active batch reminders for current work.' : 'No active reminder.')
                            : 'Historical alert. The referenced batch is no longer available.';
                    }
                    ?>
                    <article class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>" data-notification-type="<?= htmlspecialchars($notif['type']) ?>">
                        <div class="notification-content">
                            <div class="notification-row grid items-center gap-4 p-5 sm:p-6">

                                <span class="notification-category rounded-md px-3 py-1"><span class="notification-status"><span class="sr-only"><?= $notif['is_read'] ? 'Read' : 'Unread' ?></span><?php if (!$notif['is_read']): ?><span class="block h-2 w-2 rounded-full bg-blue-500 motion-safe:animate-pulse" aria-hidden="true"></span><?php endif; ?></span><?= htmlspecialchars($category_labels[$notif['type']] ?? ucfirst(str_replace('_', ' ', $notif['type']))) ?></span>
                                <h2 class="min-w-0"><?php if ($inventory_workspace && $product_search !== null): ?><a class="notification-product-link" href="<?= htmlspecialchars(app_url('components/inventory_management/products.php?' . http_build_query(['q' => $product_search]))) ?>"><?= htmlspecialchars($notif['product_name'] ?? $notif['title']) ?></a><?php else: ?><?= htmlspecialchars($notif['product_name'] ?? $notif['title']) ?><?php endif; ?></h2>
                                <dl class="notification-quantities" hidden>
                                    <div><dt>Current</dt><dd class="notification-current"></dd></div>
                                    <div><dt>Threshold</dt><dd class="notification-threshold"></dd></div>
                                </dl>
                                <span class="notification-time"><?= htmlspecialchars(format_display_datetime($notif['created_at'])) ?></span>
                        <?php if (!$notif['is_read']): ?>
                                <form class="notification-read-action" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= $notif['notification_id'] ?>">
                                    <button type="submit" class="btn-mark-read hover:text-blue-600" aria-label="Mark as read" title="Mark as read"><svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m8 12 3 3 5-6"/><rect x="3" y="3" width="18" height="18" rx="4"/></svg></button>
                                </form>
                        <?php endif; ?>

                            </div>
                            <div class="notification-details">
                                <div class="min-h-0 overflow-hidden">
                                    <div class="notification-description p-5 sm:p-6"><p><?= htmlspecialchars($notif['message']) ?></p></div>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
                </section>

                <div class="notification-more">
                    <p class="u-text-muted-legacy">Showing <?= count($notifications) ?> of <?= (int)$total ?> notifications</p>
                    <?php if ($limit < $total): ?>
                        <a class="mark-all-btn" href="?<?= htmlspecialchars(http_build_query(['category' => $category, 'view' => $view, 'batch' => $batch + 1])) ?>">Show more</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script>
    document.querySelectorAll('.notifications-page [data-notification-type="low_stock"]').forEach(item => {
        const description = item.querySelector('.notification-description p');
        const quantities = description.textContent.match(/Current: (-?\d+(?:\.\d+)?), Threshold: (-?\d+(?:\.\d+)?)\s*$/);
        if (quantities) {
            item.querySelector('.notification-current').textContent = quantities[1];
            item.querySelector('.notification-threshold').textContent = quantities[2];
            item.querySelector('.notification-quantities').hidden = false;
            item.querySelector('.notification-description').hidden = true;
            item.classList.add('notification-stock-row');
        }
    });
    </script>
</body>

</html>
