<?php
// dashboard/notifications.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/NotificationService.php';

require_role(['super_admin', 'admin', 'inventory_manager', 'cashier']);
$user_id = (int)$_SESSION['user_id'];
(new \App\Services\FiscalPeriodClosingReminder($pdo))->synchronize($user_id, current_role());
check_and_notify_low_stock($pdo);
reconcile_notification_stock($pdo, $user_id);
$category_labels = [
    'low_stock' => 'Low stock',
    'adjustment' => 'Inventory adjustments',
    'system' => 'System',
    'fiscal_period' => 'Fiscal periods',
    'expiring_stock' => 'Expiring stock',
    'expired_stock' => 'Expired stock',
    'attention' => 'Attention',
    'forecasting' => 'Forecasting',
];
$notification_source = "notifications LEFT JOIN products p ON notifications.reference_type = 'product' AND p.product_id = notifications.reference_id";
$workspace_scope = notification_workspace_scope($pdo);
$visible_notifications = notification_visibility_scope();
$inbox_scope = 'notifications.dismissed_at IS NULL';
$stmt = $pdo->prepare('SELECT notifications.type, SUM(CASE WHEN ' . $inbox_scope . ' THEN 1 ELSE 0 END) AS total, SUM(CASE WHEN notifications.is_read = FALSE AND ' . $inbox_scope . ' THEN 1 ELSE 0 END) AS unread FROM ' . $notification_source . ' WHERE notifications.user_id = ? AND ' . $workspace_scope . ' AND ' . $visible_notifications . ' GROUP BY notifications.type ORDER BY notifications.type');
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
$category = $_GET['category'] ?? '';
if ($category === 'stocking') $category = 'low_stock';
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
$view_query = http_build_query(['category' => $category, 'view' => $view, 'batch' => $batch]);
$unread_count = $category === '' ? array_sum(array_column($categories, 'unread')) : (int)$categories[$category]['unread'];
$total = $view === 'unread' ? $unread_count : ($category === '' ? array_sum(array_column($categories, 'total')) : (int)$categories[$category]['total']);
$batch = min($batch, max(1, (int)ceil($total / 10)));
$limit = min($total, $batch * 10);
$where = 'notifications.user_id = ? AND ' . $workspace_scope . ($category === '' ? '' : ' AND notifications.type = ?');
$params = $category === '' ? [$user_id] : [$user_id, $category];

$notification_service = new NotificationService($pdo);
$batch_reminders = $notification_service->getBatchReminders($user_id);
$role = current_role();
$inventory_workspace = $role === 'inventory_manager' && $notification_service->inWorkspace($user_id, 'inventory_manager');
$is_batch_history = static fn(array $notif): bool => in_array($notif['type'], ['expiring_stock', 'expired_stock', 'system'], true)
    && ($notif['reference_type'] === 'product_batch' || str_starts_with($notif['reference_type'] ?? '', 'product_batch_expiry_'));
$active_batch_reminders = $inventory_workspace
    ? array_filter($batch_reminders, static fn(array $reminder): bool => $reminder['active']) : [];
$dismissible = "notifications.is_read = TRUE AND notifications.type <> 'low_stock'
    AND NOT EXISTS (SELECT 1 FROM attention_states s WHERE s.user_id = notifications.user_id
        AND s.attention_key = notifications.attention_key AND s.is_active = 1
        AND COALESCE(notifications.attention_severity, '') <> 'info')
    AND NOT EXISTS (SELECT 1 FROM inventory_adjustments ia WHERE notifications.reference_type = 'stock_issue'
        AND ia.adjustment_id = notifications.reference_id AND ia.status IN ('pending', 'returned'))";
if ($active_batch_reminders) {
    $active_batch_ids = implode(', ', array_map('intval', array_keys($active_batch_reminders)));
    $dismissible .= " AND NOT (notifications.type IN ('expiring_stock', 'expired_stock', 'system')
        AND (COALESCE(notifications.reference_type, '') = 'product_batch'
            OR LEFT(COALESCE(notifications.reference_type, ''), 21) = 'product_batch_expiry_')
        AND COALESCE(notifications.reference_id, 0) IN ($active_batch_ids))";
}
$notification_destinations = static function (array $notif) use ($role, $inventory_workspace, $batch_reminders, $is_batch_history): array {
    $sku = $is_batch_history($notif) ? ($batch_reminders[(int)$notif['reference_id']]['sku'] ?? null) : $notif['sku'];
    $product = $inventory_workspace && $sku !== null
        ? 'components/inventory_management/products.php?' . http_build_query(['q' => $sku]) : null;

    $destination = $notif['type'] === 'low_stock' && in_array($role, ['admin', 'cashier'], true)
        ? null : $notif['attention_destination'];
    if ($destination === null && $notif['reference_type'] === 'stock_issue') {
        $id = (int)$notif['reference_id'];
        $destination = match ($role) {
            'admin' => 'components/administrator/stock_issues.php?detail=' . $id,
            'cashier' => 'components/cashier/stock_issues.php?detail=' . $id . '#conversation-' . $id,
            default => 'components/inventory_management/stock_issues.php?detail=' . $id . '#conversation-' . $id,
        };
    }
    return ['open_product' => $product, 'open_notification' => $destination ?: null];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    if ($_POST['action'] === 'mark_read') {
        $notification_id = (int)($_POST['notification_id'] ?? 0);
        if ($notification_id > 0) {
            mark_notification_read($pdo, $notification_id, $user_id);
        }
    } elseif ($_POST['action'] === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = TRUE WHERE ' . $where . ' AND is_read = FALSE');
        $stmt->execute($params);
    } elseif (in_array($_POST['action'], ['dismiss', 'undo_dismiss'], true)) {
        $notification_id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $undo = $_POST['action'] === 'undo_dismiss';
        $feedback = ['message' => $undo ? 'Could not undo dismissal.' : 'This notification could not be dismissed.'];
        if ($notification_id !== false && $view === 'all') {
            $target = $where . ' AND notifications.notification_id = ?';
            $undo_stamp = $_POST['dismissed_at'] ?? '';
            if ($undo && is_string($undo_stamp) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $undo_stamp)) {
                $stmt = $pdo->prepare('UPDATE notifications SET dismissed_at = NULL WHERE ' . $target . ' AND dismissed_at = ?');
                $stmt->execute([...$params, $notification_id, $undo_stamp]);
                if ($stmt->rowCount() === 1) {
                    $feedback = ['message' => 'Notification restored.'];
                }
            } elseif (!$undo) {
                $dismissed_at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
                $stmt = $pdo->prepare('UPDATE notifications SET dismissed_at = ? WHERE ' . $target . ' AND ' . $inbox_scope . ' AND ' . $visible_notifications . ' AND ' . $dismissible);
                $stmt->execute([$dismissed_at, ...$params, $notification_id]);
                if ($stmt->rowCount() === 1) {
                    $feedback = ['message' => 'Notification dismissed.', 'id' => $notification_id, 'dismissed_at' => $dismissed_at];
                }
            }
        }
        $_SESSION['notification_dismissal_feedback'][$role] = $feedback;
    } elseif (in_array($_POST['action'], ['open_notification', 'open_product'], true)) {
        $notification_id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($notification_id !== false) {
            $stmt = $pdo->prepare('SELECT notifications.type, notifications.reference_type, notifications.reference_id, notifications.attention_destination, p.sku FROM ' . $notification_source . ' WHERE ' . $where . ' AND ' . $visible_notifications . ' AND ' . $inbox_scope . ' AND notifications.notification_id = ?');
            $stmt->execute([...$params, $notification_id]);
            $notif = $stmt->fetch();
            $destination = $notif ? $notification_destinations($notif)[$_POST['action']] : null;
            if ($destination !== null) {
                mark_notification_read($pdo, $notification_id, $user_id);
                header('Location: ' . app_url($destination), true, 303);
                exit;
            }
        }
    }
    header('Location: ' . app_url('components/notification/notifications.php') . '?' . $view_query, true, 303);
    exit;
}

$list_where = $where . ' AND ' . $visible_notifications . ' AND ' . $inbox_scope . ($view === 'unread' ? ' AND notifications.is_read = FALSE' : '');
$stmt = $pdo->prepare('SELECT notifications.notification_id, notifications.type, notifications.title, notifications.message, notifications.reference_type, notifications.reference_id, notifications.is_read, notifications.resolved_at, notifications.created_at, notifications.attention_destination, p.product_name, p.sku, (' . $dismissible . ') AS can_dismiss FROM ' . $notification_source . ' WHERE ' . $list_where . ' ORDER BY notifications.created_at DESC, notifications.notification_id DESC LIMIT ' . (int)$limit);
$stmt->execute($params);
$notifications = $stmt->fetchAll();
$required_work = $notification_service->requiredWork($user_id, $role);
$dismissal_feedback = null;
if (($_SERVER['HTTP_X_RETAILMIND_BACKGROUND'] ?? '') !== '1') {
    $dismissal_feedback = $_SESSION['notification_dismissal_feedback'][$role] ?? null;
    unset($_SESSION['notification_dismissal_feedback'][$role]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/notifications.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/notifications-utilities.css')) ?>">
    <script src="<?= htmlspecialchars(theme_url('assets/js/notification-dismissal.js')) ?>" defer></script>
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content notifications-page">
            <header class="notification-heading">
                <h1>Notifications</h1>
            </header>

            <?php if ($required_work): ?>
                <section class="notification-required-work" aria-labelledby="required-work-heading">
                    <div class="notification-section-heading">
                        <h2 id="required-work-heading">Action needed</h2>
                        <span class="notification-required-count"><?= count($required_work) ?></span>
                    </div>
                    <div class="notification-list">
                    <?php foreach ($required_work as $work): ?>
                        <article class="notification-required-item">
                            <span class="notification-category rounded-md px-3 py-1" hidden>Low stock</span>
                            <h3><?php if (current_role() === 'admin' && ($work['type'] ?? null) === 'low_stock'): ?><?= htmlspecialchars($work['title']) ?><?php else: ?><a href="<?= htmlspecialchars(app_url($work['destination'])) ?>"><?= htmlspecialchars($work['title']) ?><svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg></a><?php endif; ?></h3>
                            <dl class="notification-quantities" hidden>
                                <div><dt>Current</dt><dd class="notification-current"></dd></div>
                                <div><dt>Threshold</dt><dd class="notification-threshold"></dd></div>
                            </dl>
                            <p><?= htmlspecialchars($work['message']) ?></p>
                        </article>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

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
                                            <?php if (!$reminder['supplier_pullout_eligible']): ?>
                                                <a class="mark-all-btn" href="<?= htmlspecialchars(app_url('components/inventory_management/stock_issues.php')) ?>">Review Stock Issues</a>
                                            <?php elseif (current_role() === 'inventory_manager'): ?>
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
                        <?php if ((int)$counts['total'] === 0 && $category !== $type) continue; ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $category === $type ? 'selected' : '' ?>><?= htmlspecialchars($category_labels[$type] ?? ucfirst(str_replace('_', ' ', $type))) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="mark-all-btn btn-secondary">Apply Filter</button>
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

            <?php if ($dismissal_feedback): ?>
                <div class="notification-dismissal-feedback">
                    <p role="status"><?= htmlspecialchars($dismissal_feedback['message']) ?></p>
                    <?php if (isset($dismissal_feedback['id'])): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                            <input type="hidden" name="notification_id" value="<?= (int)$dismissal_feedback['id'] ?>">
                            <input type="hidden" name="dismissed_at" value="<?= htmlspecialchars($dismissal_feedback['dismissed_at']) ?>">
                            <button type="submit" name="action" value="undo_dismiss" class="mark-all-btn" autofocus>Undo</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></div>
                    <?php if ($view === 'unread'): ?>
                        <h2>You’re all caught up</h2>
                        <a class="mark-all-btn" href="?<?= htmlspecialchars(http_build_query(['category' => $category, 'view' => 'all'])) ?>">View All Notifications</a>
                    <?php else: ?>
                        <h2><?= $category === '' ? 'No notifications' : 'No notifications in this category' ?></h2>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <section class="notification-list" aria-label="<?= $view === 'unread' ? 'Unread notifications' : 'All notifications' ?><?= $category === '' ? '' : ': ' . htmlspecialchars($category_labels[$category] ?? ucfirst(str_replace('_', ' ', $category))) ?>">
                <?php foreach ($notifications as $notif): ?>
                    <?php
                    if ($is_batch_history($notif)) {
                        $reminder = $batch_reminders[(int)$notif['reference_id']] ?? null;
                        $notif['title'] = 'Expiry alert history: ' . $notif['title'];
                        $notif['message'] = $reminder
                            ? "Batch {$reminder['batch_number']} (#{$reminder['batch_id']}). Expiration: " . ($reminder['expiration_date'] ?? 'Not recorded') . ". Current remaining: {$reminder['remaining_quantity']}."
                            : 'Historical alert. The referenced batch is no longer available.';
                    }
                    $destinations = $notification_destinations($notif);
                    $open_label = $notif['reference_type'] === 'stock_issue' ? 'Open Stock Issue' : 'Review Condition';
                    if ($notif['type'] === 'fiscal_period') {
                        $open_label = match ($role) {
                            'inventory_manager' => 'Review Inventory Counts',
                            'cashier' => str_contains($destinations['open_notification'] ?? '', '/gcash.php') ? 'Reconcile GCash' : 'Close shift',
                            default => 'Review Fiscal Period',
                        };
                    }
                    $open_action = $destinations['open_product'] !== null ? 'open_product'
                        : ($destinations['open_notification'] !== null ? 'open_notification' : null);
                    $form_id = 'notification-action-' . (int)$notif['notification_id'];
                    $unread = !$notif['is_read'] && $notif['resolved_at'] === null;
                    ?>
                    <article class="notification-item <?= $unread ? 'unread' : '' ?>" data-notification-type="<?= htmlspecialchars($notif['type']) ?>" <?= $notif['can_dismiss'] ? 'data-notification-dismissible' : '' ?>>
                        <div class="notification-content">
                            <div class="notification-row grid items-center gap-4 p-5 sm:p-6">

                                <span class="notification-category rounded-md px-3 py-1"><span class="notification-status"><span class="sr-only"><?= $notif['resolved_at'] !== null ? 'Resolved' : ($unread ? 'Unread' : 'Read') ?></span><?php if ($unread): ?><span class="block h-2 w-2 rounded-full bg-blue-500 motion-safe:animate-pulse" aria-hidden="true"></span><?php endif; ?></span><?= htmlspecialchars($category_labels[$notif['type']] ?? ucfirst(str_replace('_', ' ', $notif['type']))) ?><?= $notif['resolved_at'] !== null ? ' · Resolved' : '' ?></span>
                                <h2 class="min-w-0"><?php if ($open_action !== null): ?><button type="submit" class="notification-open-row" form="<?= $form_id ?>" name="action" value="<?= $open_action ?>"><?= htmlspecialchars($notif['product_name'] ?? $notif['title']) ?></button><?php else: ?><?= htmlspecialchars($notif['product_name'] ?? $notif['title']) ?><?php endif; ?></h2>
                                <dl class="notification-quantities" hidden>
                                    <div><dt>Current</dt><dd class="notification-current"></dd></div>
                                    <div><dt>Threshold</dt><dd class="notification-threshold"></dd></div>
                                </dl>
                                <span class="notification-time"><?= htmlspecialchars(format_display_datetime($notif['created_at'])) ?></span>
                        <?php if ($unread || $open_action !== null || $notif['can_dismiss']): ?>
                                <form id="<?= $form_id ?>" class="notification-read-action" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="notification_id" value="<?= (int)$notif['notification_id'] ?>">
                                    <?php if ($unread): ?>
                                        <button type="submit" name="action" value="mark_read" class="btn-mark-read hover:text-blue-600" aria-label="Mark as read" title="Mark as read"><svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m8 12 3 3 5-6"/><rect x="3" y="3" width="18" height="18" rx="4"/></svg></button>
                                    <?php elseif ($notif['can_dismiss']): ?>
                                        <button type="submit" name="action" value="dismiss" class="mark-all-btn notification-dismiss">Dismiss</button>
                                    <?php endif; ?>
                                </form>
                        <?php endif; ?>

                            </div>
                            <div class="notification-details">
                                <div class="min-h-0 overflow-hidden">
                                    <div class="notification-description p-5 sm:p-6">
                                        <p><?= htmlspecialchars($notif['message']) ?></p>
                                        <?php if ($destinations['open_notification'] !== null): ?>
                                            <button type="submit" class="btn btn-quiet btn-small mt-2 notification-open-action" form="<?= $form_id ?>" name="action" value="open_notification"><?= $open_label ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
                </section>

                <div class="notification-more">
                    <p class="u-text-muted-legacy">Showing <?= count($notifications) ?> of <?= (int)$total ?> notifications</p>
                    <?php if ($limit < $total): ?>
                        <a class="mark-all-btn" href="?<?= htmlspecialchars(http_build_query(['category' => $category, 'view' => $view, 'batch' => $batch + 1])) ?>">Show More</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script>
    document.querySelectorAll('.notifications-page [data-notification-type="low_stock"], .notification-required-item').forEach(item => {
        const description = item.querySelector('.notification-description p, :scope > p');
        const quantities = description.textContent.match(/Current: (-?\d+(?:\.\d+)?), Threshold: (-?\d+(?:\.\d+)?)\s*$/);
        if (quantities) {
            if (item.classList.contains('notification-required-item')) {
                const heading = item.querySelector('h3 a') || item.querySelector('h3');
                const title = heading.textContent.match(/^(?:Out of stock|Low Stock Alert): (.+)$/);
                if (!title) return;
                heading.firstChild.textContent = title[1];
                item.querySelector('.notification-category').hidden = false;
            }
            item.querySelector('.notification-current').textContent = quantities[1];
            item.querySelector('.notification-threshold').textContent = quantities[2];
            item.querySelector('.notification-quantities').hidden = false;
            (item.querySelector('.notification-description') || description).hidden = true;
            item.classList.add('notification-stock-row');
        }
    });
    </script>
</body>

</html>
