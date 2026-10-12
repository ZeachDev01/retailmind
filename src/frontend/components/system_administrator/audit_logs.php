<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_any_capability([
    \App\Authorization\RoleCapabilityPolicy::VIEW_STORE_AUDIT,
    \App\Authorization\RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT,
]);

$actorRole = (string)current_role();
$signInReviews = new \App\Attention\FailedSignInReviewService($pdo, new \App\Attention\SystemClock());
$reviewMessage = (string)($_SESSION['sign_in_review_message'] ?? '');
unset($_SESSION['sign_in_review_message']);
$reviewError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'review_failed_sign_ins') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    try {
        if (($_POST['review_confirmed'] ?? '') !== '1') {
            throw new InvalidArgumentException('Confirm that you reviewed the listed attempts before clearing.');
        }
        $signInReviews->clearReviewed(
            $actorRole, (int)$_SESSION['user_id'], (int)($_POST['through_attempt_id'] ?? 0),
            (string)($_POST['review_result'] ?? ''), (string)($_POST['review_note'] ?? ''), get_client_ip_address()
        );
        $_SESSION['sign_in_review_message'] = 'Review saved. The listed attempts were cleared from the warning; their history is preserved. New or remaining attempts can still trigger a warning.';
        header('Location: ' . app_url('components/system_administrator/audit_logs.php') . '#failed-sign-in-review');
        exit;
    } catch (Throwable $exception) {
        $reviewError = \App\Support\OperatorAlert::message($exception, 'The review could not be saved. Reload the page and try again.');
    }
}
$pendingSignIns = $actorRole === 'super_admin' ? $signInReviews->pendingAttempts($actorRole) : [];
$pendingSignInCount = $actorRole === 'super_admin' ? $signInReviews->pendingCount() : 0;

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

$protectedAuditRecords = new \App\Audit\ProtectedAuditRecordService($pdo, role_capability_policy());
$auditFilters = [
    'user_id' => $userFilter,
    'action' => $actionFilter,
    'module' => $moduleFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'search' => $tableSearch,
];

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
        if ($key === 'attempts' && is_array($item)) {
            $displayItem = implode('; ', array_map(static fn(array $attempt): string =>
                format_display_datetime($attempt['attempted_at']) . ' — ' . $attempt['username'] . ' — ' . $attempt['ip_address'], $item));
        } else {
            $displayItem = is_scalar($item)
                ? (string)$item
                : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
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
    return preg_match('/^\s*[=+\-@]/', $value) ? "'" . $value : $value;
}

function audit_log_send_csv(string $filename, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date & Time', 'User', 'Action', 'Category', 'Module', 'Record ID', 'Previous Value', 'New Value', 'IP Address', 'Reviewed Attempts', 'Reviewed Log Excerpt']);
    foreach ($rows as $row) {
        fputcsv($output, array_map('audit_log_csv_value', $row));
    }
    fclose($output);
    exit;
}

$logs = $isExport
    ? $protectedAuditRecords->exportRows($actorRole, $auditFilters)
    : $protectedAuditRecords->records($actorRole, $auditFilters);

if ($isExport) {
    $rows = array_map(static function (array $log): array {
        return [
            format_display_datetime($log['created_at']),
            $log['full_name'] ?: ($log['username'] ?: 'System / Unknown'),
            $log['action'],
            $log['category'],
            $log['module'] ?? '-',
            $log['record_id'] ?? '-',
            audit_log_display_value($log['previous_value'] ?? null),
            audit_log_display_value($log['new_value'] ?? null),
            $log['ip_address'] ?? '-',
            $log['action'] === \App\Attention\FailedSignInReviewService::ACTION ? audit_log_display_value($log['metadata'] ?? null) : '-',
            $log['action'] === \App\Attention\ApplicationLogReviewService::ACTION ? (json_decode((string)($log['metadata'] ?? ''), true)['excerpt'] ?? '-') : '-',
        ];
    }, $logs);
    audit_log_send_csv('audit_log_' . date('Ymd_His') . '.csv', $rows);
}

$activeUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$modules = $protectedAuditRecords->modules($actorRole);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Protected Audit Records</title>
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
                    <h1>Protected Audit Records</h1>
                    <p class="page-subtitle">Review immutable account, platform, recovery, and Store-operational records.</p>
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

            <?php if ($actorRole === 'super_admin'): ?>
                <section class="dashboard-section audit-table-card" id="failed-sign-in-review" aria-labelledby="sign-in-review-title">
                    <div class="section-header">
                        <div>
                            <h2 id="sign-in-review-title">Review failed sign-ins</h2>
                            <p class="section-description"><?= $pendingSignInCount ?> unreviewed failures in the last 24 hours. Clearing records your review; it does not block further attempts.</p>
                        </div>
                    </div>
                    <?php if ($reviewMessage !== ''): ?>
                        <p class="alert tag-success" role="status"><?= htmlspecialchars($reviewMessage) ?></p>
                    <?php endif; ?>
                    <?php if ($reviewError !== ''): ?>
                        <p class="alert tag-warning" role="alert"><?= htmlspecialchars($reviewError) ?></p>
                    <?php endif; ?>
                    <?php if ($pendingSignIns === []): ?>
                        <p>No failed sign-ins need review.</p>
                    <?php else: ?>
                        <p>Check the account and time with its owner. Compare IP addresses and look for unfamiliar accounts or repeated attempts. A shared IP can belong to several people; logs cannot prove intent. Search <strong>Login failure</strong> in Record history below and open <strong>View details</strong> for the failure reason.</p>
                        <p>If attempts look unauthorized, secure the account through <a href="<?= htmlspecialchars(app_url('components/user_manager/user_manager.php')) ?>">User Manager</a> before clearing. Record what you checked and any action taken.</p>
                        <div class="table-wrap audit-log-table-wrap">
                            <table class="audit-log-table" data-no-smart-table>
                                <caption>Oldest <?= count($pendingSignIns) ?> unreviewed attempts. Only these listed attempts will be cleared.</caption>
                                <thead><tr><th>Date &amp; Time</th><th>Account / Identifier</th><th>IP Address</th></tr></thead>
                                <tbody>
                                    <?php foreach ($pendingSignIns as $attempt): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(format_display_datetime($attempt['attempted_at'])) ?></td>
                                            <td><?= htmlspecialchars($attempt['username']) ?></td>
                                            <td><?= htmlspecialchars($attempt['ip_address']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <form method="POST" class="sign-in-review-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="review_failed_sign_ins">
                            <input type="hidden" name="through_attempt_id" value="<?= (int)$pendingSignIns[array_key_last($pendingSignIns)]['attempt_id'] ?>">
                            <div class="form-group">
                                <label for="sign-in-review-result">Review result</label>
                                <select id="sign-in-review-result" name="review_result" required>
                                    <option value="">Choose a result</option>
                                    <?php foreach (\App\Attention\FailedSignInReviewService::RESULTS as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= ($_POST['review_result'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="sign-in-review-note">Review note</label>
                                <textarea id="sign-in-review-note" name="review_note" rows="3" maxlength="1000" required aria-describedby="sign-in-note-help"><?= htmlspecialchars((string)($_POST['review_note'] ?? '')) ?></textarea>
                                <p id="sign-in-note-help" class="section-description">Briefly record who confirmed the attempts, what you checked, and any account-security action taken.</p>
                            </div>
                            <label class="sign-in-review-confirm"><input type="checkbox" name="review_confirmed" value="1" required> I reviewed the listed attempts.</label>
                            <button type="submit" class="btn btn-primary">Save review and clear listed attempts</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="dashboard-section audit-table-card" aria-labelledby="audit-table-title">
                <div class="section-header">
                    <div>
                        <h3 id="audit-table-title">Record history</h3>
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
                                <th>Category</th>
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
                                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$log['category']))) ?></td>
                                    <td><?= htmlspecialchars((string)($log['module'] ?? '-')) ?></td>
                                    <td><?= htmlspecialchars((string)($log['record_id'] ?? '-')) ?></td>
                                    <td>
                                        <details class="audit-details">
                                            <summary>View details</summary>
                                            <div class="audit-details-content">
                                                <strong>IP address:</strong> <span><?= htmlspecialchars((string)($log['ip_address'] ?? '-')) ?></span>
                                                <strong>Previous:</strong> <span class="<?= $previousValue === '-' ? 'is-empty' : '' ?>"><?= htmlspecialchars($previousValue) ?></span>
                                                <strong>New:</strong> <span class="<?= $newValue === '-' ? 'is-empty' : '' ?>"><?= htmlspecialchars($newValue) ?></span>
                                                <?php if ($log['action'] === \App\Attention\FailedSignInReviewService::ACTION): ?>
                                                    <strong>Reviewed evidence:</strong> <span><?= htmlspecialchars(audit_log_display_value($log['metadata'] ?? null)) ?></span>
                                                <?php endif; ?>
                                                <?php if ($log['action'] === \App\Attention\ApplicationLogReviewService::ACTION): ?>
                                                    <strong>Reviewed log excerpt:</strong> <pre><?= htmlspecialchars((string)(json_decode((string)($log['metadata'] ?? ''), true)['excerpt'] ?? '-')) ?></pre>
                                                <?php endif; ?>
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
                        targets: 6,
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
                    emptyTable: 'No Protected Audit Records match the selected filters.',
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
