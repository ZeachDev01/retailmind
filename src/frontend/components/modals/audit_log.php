<?php
// components/modals/audit_log.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['admin']);

// Get filter parameters
$user_filter = $_GET['user_id'] ?? '';
$action_filter = $_GET['action'] ?? '';
$module_filter = $_GET['module'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$isEmbedded = ($_GET['embed'] ?? '') === '1';
$isExport = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv';

if ($isExport) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $user_filter = $_POST['user_id'] ?? '';
    $action_filter = $_POST['action_filter'] ?? '';
    $module_filter = $_POST['module'] ?? '';
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
}

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

$selectSql = "SELECT al.user_id, al.action, $moduleSelect, $recordSelect, $previousSelect, $newSelect, al.created_at, u.full_name, u.username
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.user_id
    $where_sql
    ORDER BY al.created_at DESC";

if ($isExport) {
    $export_stmt = $pdo->prepare($selectSql);
    $export_stmt->execute($params);
    $export_logs = $export_stmt->fetchAll();
    $export_headers = ['Date & Time', 'User', 'Action', 'Module', 'Record ID', 'Previous Value', 'New Value'];
    $export_rows = array_map(static function (array $log): array {
        return [
            format_display_datetime($log['created_at']),
            $log['full_name'] ?: ($log['username'] ?: 'System / Unknown'),
            $log['action'],
            $log['module'] ?? '-',
            $log['record_id'] ?? '-',
            audit_display_value($log['previous_value'] ?? null),
            audit_display_value($log['new_value'] ?? null),
        ];
    }, $export_logs);
    audit_send_csv('audit_log_' . date('Ymd_His') . '.csv', $export_rows, $export_headers);
}

$sql = $selectSql . " LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;

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
    'embed' => $isEmbedded ? '1' : '',
    'user_id' => $user_filter,
    'action' => $action_filter,
    'module' => $module_filter,
    'date_from' => $date_from,
    'date_to' => $date_to,
], fn($value) => $value !== '');
$page_url = function (int $targetPage) use ($pagination_filters): string {
    return '?' . http_build_query(array_merge(['page' => $targetPage], $pagination_filters));
};

function audit_display_value($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        return (string)$value;
    }

    $parts = [];
    foreach ($decoded as $key => $item) {
        $label = ucwords(str_replace('_', ' ', (string)$key));
        if ($key === 'status' && isset($decoded['username'], $decoded['role'])) {
            $label = 'Action';
        }
        $displayItem = is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parts[] = $label . ': ' . $displayItem;
    }

    return implode(' | ', $parts);
}

function audit_action_class(string $action): string
{
    $normalized = strtolower($action);
    if (preg_match('/fail|error|denied|reject/', $normalized)) {
        return 'is-danger';
    }
    if (preg_match('/success|complete|created|updated|approved/', $normalized)) {
        return 'is-success';
    }
    return 'is-neutral';
}

function audit_send_csv(string $filename, array $rows, array $headers): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, array_map('audit_csv_value', $row));
    }
    fclose($output);
    exit;
}

function audit_csv_value($value): string
{
    $value = (string)$value;
    if (preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log Viewer</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/admin.css')) ?>">
</head>

<body class="audit-log-page<?= $isEmbedded ? ' audit-log-embedded' : '' ?>">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="<?= $isEmbedded ? 'audit-log-heading-copy' : '' ?>">
                    <?php if ($isEmbedded): ?>
                        <span class="audit-log-title-icon" aria-hidden="true"><i class="bi bi-clipboard2-check"></i></span>
                    <?php endif; ?>
                    <h1><?= $isEmbedded ? 'Audit Activity Log' : 'Audit Log Viewer' ?></h1>
                    <?php if ($isEmbedded): ?>
                        <p class="page-subtitle">Review critical system activity, account actions, and operational changes.</p>
                    <?php endif; ?>
                </div>
                <form method="POST" class="audit-log-export-form" target="_blank">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="export_csv">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($user_filter) ?>">
                    <input type="hidden" name="action_filter" value="<?= htmlspecialchars($action_filter) ?>">
                    <input type="hidden" name="module" value="<?= htmlspecialchars($module_filter) ?>">
                    <input type="hidden" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    <input type="hidden" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    <button type="submit" class="btn btn-quiet btn-icon" aria-label="Export audit log to CSV" title="Export audit log to CSV"><i class="bi bi-download" aria-hidden="true"></i><span>Export CSV</span></button>
                </form>
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
                <?php if ($isEmbedded): ?>
                    <input type="hidden" name="embed" value="1">
                <?php endif; ?>
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
                    <a href="<?= htmlspecialchars(app_url('components/modals/audit_log.php' . ($isEmbedded ? '?embed=1' : ''))) ?>" class="clear-btn" style="text-align: center; text-decoration: none;">Clear</a>
                </div>
            </form>

            <div class="audit-log-table-wrap">
                <table class="audit-log-table">
                    <colgroup>
                        <col class="audit-col-date">
                        <col class="audit-col-user">
                        <col class="audit-col-action">
                        <col class="audit-col-module">
                        <col class="audit-col-record-id">
                        <col class="audit-col-details">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Module</th>
                            <th>Record ID</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td class="u-text-center" colspan="6">No activity logs found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= htmlspecialchars(format_display_datetime($log['created_at'])) ?></td>
                                    <td>
                                        <?php if ($log['user_id']): ?>
                                            <strong><?= htmlspecialchars($log['full_name']) ?></strong><br>
                                            <small><?= htmlspecialchars($log['username']) ?></small>
                                        <?php else: ?>
                                            <em>System / Unknown</em>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="audit-action-badge <?= audit_action_class($log['action']) ?>"><?= htmlspecialchars($log['action']) ?></span></td>
                                    <td><?= htmlspecialchars($log['module'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($log['record_id'] ?? '-') ?></td>
                                    <?php $previous_value_display = audit_display_value($log['previous_value'] ?? null); ?>
                                    <?php $new_value_display = audit_display_value($log['new_value'] ?? null); ?>
                                    <td>
                                        <details class="audit-details">
                                            <summary>View details</summary>
                                            <div class="audit-details-content">
                                                <strong>Previous:</strong> <span class="<?= $previous_value_display === '-' ? 'is-empty' : '' ?>"><?= htmlspecialchars($previous_value_display) ?></span><br>
                                                <strong>New:</strong> <?= htmlspecialchars($new_value_display) ?>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $first_result = $total_records > 0 ? $offset + 1 : 0;
            $last_result = min($offset + $per_page, $total_records);
            ?>
            <div class="audit-log-pagination">
                <span class="audit-log-result-count">Showing <?= $first_result ?>-<?= $last_result ?> of <?= (int)$total_records ?> results</span>
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
    </div>
    <?php if ($isEmbedded): ?>
        <footer class="audit-log-footer"><span><?= (int)$total_records ?> Activity Records</span><button type="button" class="btn btn-secondary" data-embedded-close>Done</button></footer>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('[data-embedded-close]').forEach(function(button) {
                    button.addEventListener('click', function() {
                        window.parent.postMessage({
                            type: 'close-audit-log'
                        }, '*');
                    });
                });
            });
        </script>
    <?php endif; ?>
</body>

</html>