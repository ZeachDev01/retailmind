<?php
// admin/audit_log.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

// Get filter parameters
$user_filter = $_GET['user_id'] ?? '';
$action_filter = $_GET['action'] ?? '';
$module_filter = $_GET['module'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build SQL query
$where_clauses = [];
$params = [];
$log_columns = activity_log_columns($pdo);

if ($user_filter) {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $user_filter;
}

if ($action_filter) {
    $where_clauses[] = "al.action LIKE ?";
    $params[] = "%$action_filter%";
}

if ($module_filter && isset($log_columns['module'])) {
    $where_clauses[] = "al.module = ?";
    $params[] = $module_filter;
}

if ($date_from) {
    $where_clauses[] = "DATE(al.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "DATE(al.created_at) <= ?";
    $params[] = $date_to;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total count
$count_sql = "SELECT COUNT(*) FROM activity_log al $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get logs
$moduleSelect = isset($log_columns['module']) ? 'al.module' : 'NULL AS module';
$recordSelect = isset($log_columns['record_id']) ? 'al.record_id' : 'NULL AS record_id';
$previousSelect = isset($log_columns['previous_value']) ? 'al.previous_value' : 'NULL AS previous_value';
$newSelect = isset($log_columns['new_value']) ? 'al.new_value' : 'NULL AS new_value';
$ipSelect = isset($log_columns['ip_address']) ? 'al.ip_address' : 'NULL AS ip_address';

$sql = "SELECT al.log_id, al.user_id, al.action, $moduleSelect, $recordSelect, $previousSelect, $newSelect, $ipSelect, al.created_at, u.full_name, u.username
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.user_id
    $where_sql
    ORDER BY al.created_at DESC
    LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get all users for filter dropdown
$users_stmt = $pdo->query("SELECT user_id, full_name, username FROM users ORDER BY full_name");
$all_users = $users_stmt->fetchAll();
$modules = [];
if (isset($log_columns['module'])) {
    $modules = $pdo->query("SELECT DISTINCT module FROM activity_log WHERE module IS NOT NULL AND module <> '' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
}
$pagination_filters = array_filter([
    'user_id' => $user_filter,
    'action' => $action_filter,
    'module' => $module_filter,
    'date_from' => $date_from,
    'date_to' => $date_to,
], fn($value) => $value !== '');
$page_url = function (int $targetPage) use ($pagination_filters): string {
    return '?' . http_build_query(array_merge(['page' => $targetPage], $pagination_filters));
};
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log Viewer</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <style>
        .filter-section {
            background: #f5f5f5;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 1rem;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .filter-group input,
        .filter-group select {
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .filter-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .filter-btn:hover {
            background: #0056b3;
        }
        .clear-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .clear-btn:hover {
            background: #545b62;
        }
        .action-cell {
            max-width: 400px;
            word-break: break-word;
            font-family: monospace;
            font-size: 0.85rem;
        }
        .pagination {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.5rem;
            align-items: center;
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
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-box {
            background: white;
            padding: 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }
        .stat-box .value {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
        }
        .stat-box .label {
            color: #666;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Audit Log Viewer</h1>
        </div>

        <div class="stats">
            <div class="stat-box">
                <div class="value"><?= $total_records ?></div>
                <div class="label">Total Activity Records</div>
            </div>
            <div class="stat-box">
                <div class="value"><?= count($all_users) ?></div>
                <div class="label">Active Users</div>
            </div>
        </div>

        <form method="GET" class="filter-section">
            <div class="filter-group">
                <label for="user_id">User</label>
                <select name="user_id" id="user_id">
                    <option value="">All Users</option>
                    <?php foreach ($all_users as $u): ?>
                        <option value="<?= $u['user_id'] ?>" <?= $user_filter == $u['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="action">Action (contains)</label>
                <input type="text" name="action" id="action" placeholder="Search action..." value="<?= htmlspecialchars($action_filter) ?>">
            </div>

            <div class="filter-group">
                <label for="module">Module</label>
                <select name="module" id="module">
                    <option value="">All Modules</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= htmlspecialchars($module) ?>" <?= $module_filter === $module ? 'selected' : '' ?>>
                            <?= htmlspecialchars($module) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="date_from">From Date</label>
                <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <div class="filter-group">
                <label for="date_to">To Date</label>
                <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <div class="filter-group">
                <button type="submit" class="filter-btn">Filter</button>
                <a href="<?= htmlspecialchars(app_url('admin/audit_log.php')) ?>" class="clear-btn" style="text-align: center; text-decoration: none;">Clear</a>
            </div>
        </form>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Record ID</th>
                    <th>Previous Value</th>
                    <th>New Value</th>
                    <th>IP Address</th>
                    <th>Date & Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="9" style="text-align: center; padding: 2rem;">No activity logs found</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['log_id']) ?></td>
                            <td>
                                <?php if ($log['user_id']): ?>
                                    <strong><?= htmlspecialchars($log['full_name']) ?></strong><br>
                                    <small><?= htmlspecialchars($log['username']) ?></small>
                                <?php else: ?>
                                    <em>System / Unknown</em>
                                <?php endif; ?>
                            </td>
                            <td class="action-cell"><?= htmlspecialchars($log['action']) ?></td>
                            <td><?= htmlspecialchars($log['module'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['record_id'] ?? '-') ?></td>
                            <td class="action-cell"><?= htmlspecialchars($log['previous_value'] ?? '-') ?></td>
                            <td class="action-cell"><?= htmlspecialchars($log['new_value'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($page_url(1)) ?>">« First</a>
                    <a href="<?= htmlspecialchars($page_url($page - 1)) ?>">‹ Prev</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($page_url($i)) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= htmlspecialchars($page_url($page + 1)) ?>">Next ›</a>
                    <a href="<?= htmlspecialchars($page_url($total_pages)) ?>">Last »</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
