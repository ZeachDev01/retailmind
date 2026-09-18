<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_any_capability([
    \App\Authorization\RoleCapabilityPolicy::VIEW_STORE_AUDIT,
    \App\Authorization\RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT,
]);

$userFilter = trim((string)($_GET['user_id'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$moduleFilter = trim((string)($_GET['module'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$tableSearch = '';
$isExport = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($_POST['action'] ?? '') === 'export_csv';

if ($isExport) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $userFilter = trim((string)($_POST['user_id'] ?? ''));
    $actionFilter = trim((string)($_POST['action_filter'] ?? ''));
    $moduleFilter = trim((string)($_POST['module'] ?? ''));
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo = trim((string)($_POST['date_to'] ?? ''));
    $tableSearch = trim((string)($_POST['table_search'] ?? ''));
}

$whereClauses = [];
$params = [];
$logColumns = activity_log_columns($pdo);

if ($userFilter !== '') {
    $whereClauses[] = 'al.user_id = ?';
    $params[] = (int)$userFilter;
}
if ($actionFilter !== '') {
    $whereClauses[] = 'al.action LIKE ?';
    $params[] = '%' . $actionFilter . '%';
}
if ($moduleFilter !== '' && isset($logColumns['module'])) {
    $whereClauses[] = 'al.module = ?';
    $params[] = $moduleFilter;
}
if ($dateFrom !== '') {
    $whereClauses[] = 'DATE(al.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $whereClauses[] = 'DATE(al.created_at) <= ?';
    $params[] = $dateTo;
}
if ($tableSearch !== '') {
    $searchClauses = ['al.action LIKE ?', 'u.full_name LIKE ?', 'u.username LIKE ?'];
    $searchParams = array_fill(0, 3, '%' . $tableSearch . '%');
    if (isset($logColumns['module'])) {
        $searchClauses[] = 'al.module LIKE ?';
        $searchParams[] = '%' . $tableSearch . '%';
    }
    if (isset($logColumns['record_id'])) {
        $searchClauses[] = 'CAST(al.record_id AS CHAR) LIKE ?';
        $searchParams[] = '%' . $tableSearch . '%';
    }
    $whereClauses[] = '(' . implode(' OR ', $searchClauses) . ')';
    array_push($params, ...$searchParams);
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
$moduleSelect = isset($logColumns['module']) ? 'al.module' : 'NULL AS module';
$recordSelect = isset($logColumns['record_id']) ? 'al.record_id' : 'NULL AS record_id';
$previousSelect = isset($logColumns['previous_value']) ? 'al.previous_value' : 'NULL AS previous_value';
$newSelect = isset($logColumns['new_value']) ? 'al.new_value' : 'NULL AS new_value';
$selectSql = "SELECT al.user_id, al.action, {$moduleSelect}, {$recordSelect}, {$previousSelect}, {$newSelect},
        al.created_at, u.full_name, u.username
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.user_id
    {$whereSql}
    ORDER BY al.created_at DESC";

function audit_log_display_value($value): string
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
        $displayItem = is_scalar($item)
            ? (string)$item
            : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parts[] = $label . ': ' . $displayItem;
    }

    return implode(' | ', $parts);
}

function audit_log_action_class(string $action): string
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

function audit_log_csv_value($value): string
{
    $value = (string)$value;
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

function audit_log_send_csv(string $filename, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date & Time', 'User', 'Action', 'Module', 'Record ID', 'Previous Value', 'New Value']);
    foreach ($rows as $row) {
        fputcsv($output, array_map('audit_log_csv_value', $row));
    }
    fclose($output);
    exit;
}

$logsStatement = $pdo->prepare($selectSql);
$logsStatement->execute($params);
$logs = $logsStatement->fetchAll();

if ($isExport) {
    $rows = array_map(static function (array $log): array {
        return [
            format_display_datetime($log['created_at']),
            $log['full_name'] ?: ($log['username'] ?: 'System / Unknown'),
            $log['action'],
            $log['module'] ?? '-',
            $log['record_id'] ?? '-',
            audit_log_display_value($log['previous_value'] ?? null),
            audit_log_display_value($log['new_value'] ?? null),
        ];
    }, $logs);
    audit_log_send_csv('audit_log_' . date('Ymd_His') . '.csv', $rows);
}

$activeUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$modules = isset($logColumns['module'])
    ? $pdo->query("SELECT DISTINCT module FROM activity_log WHERE module IS NOT NULL AND module <> '' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN)
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="https://cdn.datatables.net/v/dt/dt-3.0.4/datatables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/columncontrol/2.0.2/css/columnControl.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/datetime/2.0.0/css/dataTables.dateTime.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/audit-logs.css')) ?>">
</head>
<body class="audit-log-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Audit Logs</h1>
                    <p class="page-subtitle">Review account activity, system events, and operational changes.</p>
                </div>
                <form method="POST" class="audit-log-export-form" id="auditLogExportForm" target="_blank">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="export_csv">
                    <input type="hidden" name="user_id" id="exportUserFilter" value="<?= htmlspecialchars($userFilter) ?>">
                    <input type="hidden" name="action_filter" id="exportActionFilter" value="<?= htmlspecialchars($actionFilter) ?>">
                    <input type="hidden" name="module" id="exportModuleFilter" value="<?= htmlspecialchars($moduleFilter) ?>">
                    <input type="hidden" name="date_from" id="exportDateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="date_to" id="exportDateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <input type="hidden" name="table_search" id="exportTableSearch" value="">
                    <button type="submit" class="btn btn-quiet"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</button>
                </form>
            </div>

            <div class="card-grid audit-log-stats">
                <div class="stat-card with-icon">
                    <span class="stat-icon" aria-hidden="true"><i class="bi bi-card-checklist"></i></span>
                    <div class="value" id="matchingRecordsCount"><?= count($logs) ?></div>
                    <div class="label">Matching Records</div>
                </div>
                <div class="stat-card with-icon success">
                    <span class="stat-icon" aria-hidden="true"><i class="bi bi-person-check-fill"></i></span>
                    <div class="value"><?= $activeUserCount ?></div>
                    <div class="label">Active Users</div>
                </div>
                <div class="stat-card with-icon">
                    <span class="stat-icon" aria-hidden="true"><i class="bi bi-boxes"></i></span>
                    <div class="value"><?= count($modules) ?></div>
                    <div class="label">Logged Modules</div>
                </div>
            </div>

            <section class="dashboard-section audit-table-card" aria-labelledby="audit-table-title">
                <div class="section-header">
                    <div>
                        <h3 id="audit-table-title">Activity history</h3>
                        <p class="section-description">Use the table search for quick matching across the filtered records.</p>
                    </div>
                </div>
                <div class="table-wrap audit-log-table-wrap">
                    <table id="auditLogsTable" class="audit-log-table display" data-no-smart-table>
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
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $previousValue = audit_log_display_value($log['previous_value'] ?? null);
                                $newValue = audit_log_display_value($log['new_value'] ?? null);
                                $timestamp = strtotime((string)$log['created_at']) ?: 0;
                                ?>
                                <tr>
                                    <td data-order="<?= htmlspecialchars(date('Y-m-d\TH:i:s', $timestamp)) ?>" data-search="<?= htmlspecialchars(date('Y-m-d', $timestamp)) ?>"><?= htmlspecialchars(format_display_datetime($log['created_at'])) ?></td>
                                    <td data-search="<?= htmlspecialchars(trim((string)($log['full_name'] ?? '') . ' ' . (string)($log['username'] ?? ''))) ?>">
                                        <?php if ($log['user_id']): ?>
                                            <strong><?= htmlspecialchars((string)($log['full_name'] ?: $log['username'])) ?></strong>
                                            <small><?= htmlspecialchars((string)$log['username']) ?></small>
                                        <?php else: ?>
                                            <em>System / Unknown</em>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="audit-action-badge <?= audit_log_action_class((string)$log['action']) ?>"><?= htmlspecialchars($log['action']) ?></span></td>
                                    <td><?= htmlspecialchars((string)($log['module'] ?? '-')) ?></td>
                                    <td><?= htmlspecialchars((string)($log['record_id'] ?? '-')) ?></td>
                                    <td>
                                        <details class="audit-details">
                                            <summary>View details</summary>
                                            <div class="audit-details-content">
                                                <strong>Previous:</strong> <span class="<?= $previousValue === '-' ? 'is-empty' : '' ?>"><?= htmlspecialchars($previousValue) ?></span>
                                                <strong>New:</strong> <span class="<?= $newValue === '-' ? 'is-empty' : '' ?>"><?= htmlspecialchars($newValue) ?></span>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.datatables.net/v/dt/dt-3.0.4/datatables.min.js"></script>
    <script src="https://cdn.datatables.net/columncontrol/2.0.2/js/dataTables.columnControl.min.js"></script>
    <script src="https://cdn.datatables.net/datetime/2.0.0/js/dataTables.dateTime.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof DataTable === 'undefined') {
                return;
            }

            var matchingRecords = document.getElementById('matchingRecordsCount');
            var exportForm = document.getElementById('auditLogExportForm');

            var table = new DataTable('#auditLogsTable', {
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                order: [[0, 'desc']],
                deferRender: true,
                ordering: {
                    indicators: false,
                    handler: false
                },
                columnControl: ['order', ['search', 'orderAsc', 'orderDesc', 'orderClear']],
                columnDefs: [
                    {
                        targets: 0,
                        type: 'date',
                        columnControl: [
                            'order',
                            [
                                { extend: 'search', mask: 'YYYY-MM-DD', format: 'YYYY-MM-DD' },
                                'orderAsc',
                                'orderDesc',
                                'orderClear'
                            ]
                        ]
                    },
                    {
                        targets: 5,
                        orderable: false,
                        columnControl: [['search']]
                    }
                ],
                layout: {
                    topStart: null,
                    topEnd: {
                        search: {
                            placeholder: 'Search all audit activity...'
                        }
                    },
                    bottom: [
                        'info',
                        {
                            pageLength: {
                                menu: [10, 25, 50, 100]
                            }
                        },
                        {
                            paging: {
                                numbers: 5
                            }
                        }
                    ],
                    bottomStart: null,
                    bottomEnd: null
                },
                language: {
                    emptyTable: 'No audit activity matches the selected filters.',
                    search: 'Search:'
                }
            });

            function syncExportFilters() {
                document.getElementById('exportTableSearch').value = table.search();
            }
            table.on('draw', function() {
                matchingRecords.textContent = table.rows({ search: 'applied' }).count();
                syncExportFilters();
            });
            exportForm.addEventListener('submit', syncExportFilters);
        });
    </script>
</body>
</html>
