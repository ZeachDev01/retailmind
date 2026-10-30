<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_any_capability([
    \App\Authorization\RoleCapabilityPolicy::VIEW_STORE_AUDIT,
    \App\Authorization\RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT,
]);

$actorRole = (string)current_role();
$userFilter = trim((string)($_GET['user_id'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$moduleFilter = trim((string)($_GET['module'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$isTableRequest = ($_GET['table'] ?? '') === '1';
$tableRequest = $isTableRequest ? $_GET : [];
$isExport = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($_POST['action'] ?? '') === 'export_csv';

if ($isExport) {
    require_local_request('Bulk exports are not available during remote monitoring.');
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $userFilter = trim((string)($_POST['user_id'] ?? ''));
    $actionFilter = trim((string)($_POST['action_filter'] ?? ''));
    $moduleFilter = trim((string)($_POST['module'] ?? ''));
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo = trim((string)($_POST['date_to'] ?? ''));
    $tableRequest = json_decode((string)($_POST['table_request'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($tableRequest)) {
        throw new InvalidArgumentException('Reload the audit page and try the export again.');
    }
}

$protectedAuditRecords = new \App\Audit\ProtectedAuditRecordService($pdo, role_capability_policy());
$auditFilters = [
    'user_id' => $userFilter,
    'action' => $actionFilter,
    'module' => $moduleFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'search' => (string)($tableRequest['search']['value'] ?? ''),
    'columns' => $tableRequest['columns'] ?? [],
    'order' => $tableRequest['order'] ?? [],
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
    if (preg_match('/fail|error|denied|reject|delete|destroy|drop|revoke|danger/', $normalized)) {
        return 'is-danger';
    }
    if (preg_match('/warning|alert|revert|reset/', $normalized)) {
        return 'is-warning';
    }
    if (preg_match('/success|complete|created|updated|approved|restor|active|enable/', $normalized)) {
        return 'is-success';
    }

    return 'is-neutral';
}

function audit_log_table_cells(array $log): array
{
    $previousValue = audit_log_display_value($log['previous_value'] ?? null);
    $newValue = audit_log_display_value($log['new_value'] ?? null);
    $formattedTimestamp = format_display_datetime($log['created_at']);
    if ($log['user_id']) {
        $fullName = trim((string)($log['full_name'] ?? ''));
        $username = trim((string)($log['username'] ?? ''));
        if ($fullName !== '' && $fullName !== $username) {
            $user = '<strong>' . htmlspecialchars($fullName) . '</strong><small>@' . htmlspecialchars($username) . '</small>';
        } else {
            $user = '<strong>' . htmlspecialchars($username ?: 'User #' . $log['user_id']) . '</strong>';
        }
    } else {
        $user = '<em>System / Unknown</em>';
    }
    ob_start();
    ?>
    <details class="audit-details">
        <summary aria-label="<?= htmlspecialchars('View details for ' . $log['action'] . ' at ' . $formattedTimestamp) ?>">View details</summary>
        <dl class="audit-details-content">
            <dt>IP Address</dt>
            <dd class="audit-code"><?= htmlspecialchars((string)($log['ip_address'] ?? '-')) ?></dd>
            <dt>Previous Value</dt>
            <dd class="<?= $previousValue === '-' ? 'is-empty' : '' ?>"><?= htmlspecialchars($previousValue) ?></dd>
            <dt>New Value</dt>
            <dd class="<?= $newValue === '-' ? 'is-empty' : '' ?>"><?= htmlspecialchars($newValue) ?></dd>
            <?php if ($log['action'] === \App\Attention\FailedSignInReviewService::ACTION): ?>
                <dt>Reviewed Evidence</dt>
                <dd><?= htmlspecialchars(audit_log_display_value($log['metadata'] ?? null)) ?></dd>
            <?php endif; ?>
            <?php if ($log['action'] === \App\Attention\ApplicationLogReviewService::ACTION): ?>
                <dt>Reviewed Log Excerpt</dt>
                <dd><pre><?= htmlspecialchars((string)(json_decode((string)($log['metadata'] ?? ''), true)['excerpt'] ?? '-')) ?></pre></dd>
            <?php endif; ?>
        </dl>
    </details>
    <?php
    $details = (string)ob_get_clean();
    return [
        htmlspecialchars($formattedTimestamp),
        $user,
        '<span class="audit-action-badge ' . audit_log_action_class((string)$log['action']) . '">' . htmlspecialchars((string)$log['action']) . '</span>',
        htmlspecialchars(ucwords(str_replace('_', ' ', (string)$log['category']))),
        htmlspecialchars((string)($log['module'] ?? '-')),
        htmlspecialchars((string)($log['record_id'] ?? '-')),
        $details,
    ];
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

if ($isTableRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $baseFilters = array_diff_key($auditFilters, array_flip(['search', 'columns', 'order']));
        $total = $protectedAuditRecords->count($actorRole, $baseFilters);
        $filtered = $protectedAuditRecords->count($actorRole, $auditFilters);
        $logs = $protectedAuditRecords->records($actorRole, $auditFilters + [
            'limit' => max(10, min(100, (int)($_GET['length'] ?? 25))),
            'offset' => max(0, (int)($_GET['start'] ?? 0)),
        ]);
        echo json_encode([
            'draw' => max(0, (int)($_GET['draw'] ?? 0)),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => array_map('audit_log_table_cells', $logs),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $exception) {
        error_log('Audit table request failed: ' . $exception->getMessage());
        http_response_code($exception instanceof InvalidArgumentException ? 422 : 500);
        echo json_encode(['error' => \App\Support\OperatorAlert::message($exception, 'Records could not be loaded. Reload the page and try again.')]);
    }
    exit;
}

$logs = $isExport ? $protectedAuditRecords->exportRows($actorRole, $auditFilters) : [];

if ($isExport) {
    require_local_request('Bulk exports are not available during remote monitoring.');
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
        $_SESSION['sign_in_review_message'] = 'Review saved. Listed attempts cleared from active alerts; audit history is preserved.';
        header('Location: ' . app_url('components/system_administrator/audit_logs.php') . '#failed-sign-in-review');
        exit;
    } catch (Throwable $exception) {
        $reviewError = \App\Support\OperatorAlert::message($exception, 'The review could not be saved. Reload the page and try again.');
    }
}
$pendingSignIns = $actorRole === 'super_admin' ? $signInReviews->pendingAttempts($actorRole) : [];
$pendingSignInCount = $actorRole === 'super_admin' ? $signInReviews->pendingCount() : 0;

$matchingRecordCount = $protectedAuditRecords->count($actorRole, $auditFilters);
$activeUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$modules = $protectedAuditRecords->modules($actorRole);
$canViewPlatformAudit = has_capability(\App\Authorization\RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT);
$pageTitle = $canViewPlatformAudit ? 'Protected Audit Records' : 'Operational Audit';
$emptyTableCopy = $canViewPlatformAudit
    ? 'No Protected Audit Records match the selected filters.'
    : 'No Operational Audit records match the selected filters.';

$activeFilters = [];
if ($moduleFilter !== '') {
    $activeFilters[] = ['label' => 'Module', 'value' => $moduleFilter];
}
if ($actionFilter !== '') {
    $activeFilters[] = ['label' => 'Action', 'value' => $actionFilter];
}
if ($userFilter !== '') {
    $activeFilters[] = ['label' => 'User', 'value' => $userFilter];
}
if ($dateFrom !== '') {
    $activeFilters[] = ['label' => 'From', 'value' => $dateFrom];
}
if ($dateTo !== '') {
    $activeFilters[] = ['label' => 'To', 'value' => $dateTo];
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/datatables/datatables.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/datatables/columncontrol/columnControl.dataTables.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/datatables/datetime/dataTables.dateTime.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/audit-logs.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/audit-logs.css')) ?>">
</head>
<body class="audit-log-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <header class="page-heading topbar">
                <div>
                    <h1><?= htmlspecialchars($pageTitle) ?></h1>
                    <p class="page-subtitle u-text-muted"><?= $canViewPlatformAudit ? 'System security, authentication, and platform configuration audit trail' : 'Store activity, user operations, and inventory event log' ?></p>
                </div>
                <form method="POST" class="audit-log-export-form" id="auditLogExportForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="export_csv">
                    <input type="hidden" name="user_id" id="exportUserFilter" value="<?= htmlspecialchars($userFilter) ?>">
                    <input type="hidden" name="action_filter" id="exportActionFilter" value="<?= htmlspecialchars($actionFilter) ?>">
                    <input type="hidden" name="module" id="exportModuleFilter" value="<?= htmlspecialchars($moduleFilter) ?>">
                    <input type="hidden" name="date_from" id="exportDateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="date_to" id="exportDateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <input type="hidden" name="table_request" id="exportTableRequest" value="{}">
                    <button type="submit" class="btn btn-quiet"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</button>
                </form>
            </header>

            <?php if ($activeFilters !== []): ?>
                <aside class="active-filters-bar" aria-label="Applied filters">
                    <span class="active-filters-label"><i class="bi bi-funnel-fill" aria-hidden="true"></i> Filtered by:</span>
                    <?php foreach ($activeFilters as $filter): ?>
                        <span class="tag tag-warning"><?= htmlspecialchars($filter['label']) ?>: <strong><?= htmlspecialchars($filter['value']) ?></strong></span>
                    <?php endforeach; ?>
                    <a href="<?= htmlspecialchars(app_url('components/system_administrator/audit_logs.php')) ?>" class="btn-clear-filters"><i class="bi bi-x-circle" aria-hidden="true"></i> Clear filters</a>
                </aside>
            <?php endif; ?>

            <div class="audit-log-stats">
                <div class="stat-card with-icon">
                    <span class="stat-icon" aria-hidden="true"><i class="bi bi-card-checklist"></i></span>
                    <div class="value" id="matchingRecordsCount" aria-live="polite" aria-atomic="true"><?= $matchingRecordCount ?></div>
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
                            <h2 id="sign-in-review-title"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Review failed sign-ins</h2>
                        </div>
                    </div>
                    <?php if ($reviewMessage !== ''): ?>
                        <p class="alert tag-success" role="status"><?= htmlspecialchars($reviewMessage) ?></p>
                    <?php endif; ?>
                    <?php if ($reviewError !== ''): ?>
                        <p class="alert tag-danger" role="alert"><?= htmlspecialchars($reviewError) ?></p>
                    <?php endif; ?>
                    <?php if ($pendingSignIns === []): ?>
                        <p class="u-text-muted">No failed sign-ins need review.</p>
                    <?php else: ?>
                        <div class="table-wrap audit-log-table-wrap">
                            <table class="audit-log-table" data-no-smart-table>
                                <caption>Oldest <?= count($pendingSignIns) ?> unreviewed attempts</caption>
                                <thead><tr><th scope="col">Date &amp; Time</th><th scope="col">Account / Identifier</th><th scope="col">IP Address</th></tr></thead>
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
                        <form method="POST" class="sign-in-review-form" id="signInReviewForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="review_failed_sign_ins">
                            <input type="hidden" name="through_attempt_id" value="<?= (int)$pendingSignIns[array_key_last($pendingSignIns)]['attempt_id'] ?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="sign-in-review-result">Review result <span class="required-mark" aria-hidden="true">*</span></label>
                                    <select id="sign-in-review-result" name="review_result" required>
                                        <option value="">Choose a result</option>
                                        <?php foreach (\App\Attention\FailedSignInReviewService::RESULTS as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= ($_POST['review_result'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group full">
                                    <label for="sign-in-review-note">Review note <span class="required-mark" aria-hidden="true">*</span></label>
                                    <textarea id="sign-in-review-note" name="review_note" rows="3" maxlength="1000" placeholder="Summarize review findings and any security actions taken" required><?= htmlspecialchars((string)($_POST['review_note'] ?? '')) ?></textarea>
                                </div>
                            </div>
                            <label class="sign-in-review-confirm"><input type="checkbox" name="review_confirmed" value="1" required> <span>I confirm I reviewed the listed attempts. <span class="required-mark" aria-hidden="true">*</span></span></label>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check" aria-hidden="true"></i> Save review and clear listed attempts</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="dashboard-section audit-table-card" aria-labelledby="audit-table-title">
                <div class="section-header">
                    <div>
                        <h2 id="audit-table-title"><i class="bi bi-journal-text" aria-hidden="true"></i> Record history</h2>
                    </div>
                </div>
                <p id="audit-table-error" class="alert tag-danger" role="alert" hidden></p>
                <noscript><p>Enable JavaScript to search and browse record history. Export CSV remains available.</p></noscript>
                <div class="table-wrap audit-log-table-wrap audit-history-wrap">
                    <table id="auditLogsTable" class="audit-log-table display" data-no-smart-table>
                        <caption class="visually-hidden">System audit log history with event details and timestamps</caption>
                        <thead>
                            <tr>
                                <th scope="col">Date &amp; Time</th>
                                <th scope="col">User</th>
                                <th scope="col">Action</th>
                                <th scope="col">Category</th>
                                <th scope="col">Module</th>
                                <th scope="col">Record ID</th>
                                <th scope="col">Details</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script defer src="<?= htmlspecialchars(app_url('assets/vendor/datatables/datatables.min.js')) ?>"></script>
    <script defer src="<?= htmlspecialchars(app_url('assets/vendor/datatables/columncontrol/dataTables.columnControl.min.js')) ?>"></script>
    <script defer src="<?= htmlspecialchars(app_url('assets/vendor/datatables/datetime/dataTables.dateTime.min.js')) ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var reviewForm = document.getElementById('signInReviewForm');
            if (reviewForm) {
                reviewForm.addEventListener('submit', function() {
                    var submitBtn = reviewForm.querySelector('button[type="submit"]');
                    if (submitBtn && reviewForm.checkValidity()) {
                        submitBtn.classList.add('btn-loading');
                        submitBtn.setAttribute('aria-busy', 'true');
                    }
                });
            }

            if (typeof DataTable === 'undefined') {
                var unavailable = document.getElementById('audit-table-error');
                unavailable.textContent = 'Record history could not be loaded. Reload the page and try again. Export CSV remains available.';
                unavailable.hidden = false;
                return;
            }

            var matchingRecords = document.getElementById('matchingRecordsCount');
            var exportForm = document.getElementById('auditLogExportForm');
            var exportRequest = document.getElementById('exportTableRequest');
            var tableError = document.getElementById('audit-table-error');
            var exportButton = exportForm.querySelector('button');
            var lastRequest = {};
            var tableReady = false;
            exportButton.disabled = true;

            function showAuditLoadError() {
                table.processing(false);
                tableReady = false;
                exportButton.disabled = true;
                matchingRecords.textContent = '-';
                tableError.textContent = 'Records could not be loaded. Reload the page and try again.';
                tableError.hidden = false;
            }

            var table = new DataTable('#auditLogsTable', {
                serverSide: true,
                processing: true,
                ajax: {
                    url: <?= json_encode(app_url('components/system_administrator/audit_logs.php') . '?' . http_build_query(array_merge($_GET, ['table' => '1'])), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    // The bundled transport ignores network failures with status 0.
                    beforeSend: function(request) {
                        request.addEventListener('error', showAuditLoadError);
                        request.addEventListener('timeout', showAuditLoadError);
                        request.timeout = 30000;
                    },
                    error: showAuditLoadError
                },
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
                    { targets: [1, 2, 3, 4, 6], type: 'string' },
                    { targets: 5, type: 'num' },
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
                    emptyTable: <?= json_encode($emptyTableCopy, JSON_UNESCAPED_UNICODE) ?>,
                    search: 'Search:',
                    searchPlaceholder: 'Search records, users, actions...',
                    lengthMenu: 'Show _MENU_ records',
                    info: 'Showing _START_ to _END_ of _TOTAL_ records',
                    infoEmpty: 'Showing 0 records',
                    infoFiltered: '(filtered from _MAX_ total)'
                }
            });

            var tableViewport = document.querySelector('#auditLogsTable_wrapper .dt-layout-table');
            tableViewport.tabIndex = 0;
            tableViewport.setAttribute('role', 'region');
            tableViewport.setAttribute('aria-label', 'Record history table');

            document.querySelector('#auditLogsTable_wrapper .dt-search input').addEventListener('input', function() {
                tableReady = false;
                exportButton.disabled = true;
            });
            table.on('preXhr', function() {
                tableReady = false;
                exportButton.disabled = true;
                tableError.hidden = true;
            });
            table.on('draw', function() {
                var result = table.ajax.json();
                if (!result || result.error) return;
                matchingRecords.textContent = result.recordsFiltered;
                lastRequest = table.ajax.params();
                exportRequest.value = JSON.stringify(lastRequest);
                tableReady = true;
                exportButton.disabled = false;
            });
            exportForm.addEventListener('submit', function(event) {
                if (!tableReady || table.search() !== lastRequest.search.value) {
                    event.preventDefault();
                    tableError.textContent = 'Wait for the search results before exporting.';
                    tableError.hidden = false;
                }
            });
        });
    </script>
</body>
</html>
