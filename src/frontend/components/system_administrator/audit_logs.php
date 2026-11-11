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
$categoryFilter = trim((string)($_GET['category'] ?? ''));
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
    $categoryFilter = trim((string)($_POST['category'] ?? ''));
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
    'category' => $categoryFilter,
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

function audit_log_category_filter_url(string $category): string
{
    $query = [];
    foreach (['user_id', 'action', 'module', 'date_from', 'date_to'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $query[$key] = trim((string)$_GET[$key]);
        }
    }
    $query['category'] = $category;

    return app_url('components/system_administrator/audit_logs.php') . '?' . http_build_query($query);
}

function audit_log_detail_value_html(string $value): string
{
    if ($value === '' || $value === '-') {
        return '<span class="audit-detail-empty">Not recorded</span>';
    }

    $segments = preg_split('/\s+\|\s+/', $value) ?: [$value];
    $pairs = [];
    $plain = [];
    foreach ($segments as $segment) {
        if (preg_match('/^([A-Za-z][A-Za-z0-9 _\/()-]{0,39}):\s*(.+)$/s', $segment, $matches)) {
            $pairs[] = [$matches[1], $matches[2]];
        } else {
            $plain[] = $segment;
        }
    }

    if ($pairs === []) {
        return '<span class="audit-detail-plain">' . htmlspecialchars($value) . '</span>';
    }

    $html = '<span class="audit-detail-pairs">';
    foreach ($pairs as [$label, $pairValue]) {
        $html .= '<span class="audit-detail-pair">'
            . '<span class="audit-detail-key">' . htmlspecialchars($label) . '</span>'
            . '<span class="audit-detail-value">' . htmlspecialchars($pairValue) . '</span>'
            . '</span>';
    }
    if ($plain !== []) {
        $html .= '<span class="audit-detail-plain">' . htmlspecialchars(implode(' | ', $plain)) . '</span>';
    }

    return $html . '</span>';
}

function audit_log_table_cells(array $log): array
{
    $previousValue = audit_log_display_value($log['previous_value'] ?? null);
    $newValue = audit_log_display_value($log['new_value'] ?? null);
    $formattedTimestamp = format_display_datetime($log['created_at']);
    if ($log['user_id']) {
        $fullName = trim((string)($log['display_name'] ?? ''));
        $username = trim((string)($log['username'] ?? ''));
        if ($fullName !== '' && $fullName !== $username) {
            $user = '<div class="audit-user-cell"><strong class="audit-user-name">' . htmlspecialchars($fullName) . '</strong><span class="audit-user-handle">@' . htmlspecialchars($username) . '</span></div>';
        } else {
            $user = '<div class="audit-user-cell"><strong class="audit-user-name">' . htmlspecialchars($username ?: 'User #' . $log['user_id']) . '</strong></div>';
        }
    } else {
        $user = '<div class="audit-user-cell"><span class="audit-user-system">System</span></div>';
    }
    ob_start();
    ?>
    <button type="button" class="audit-details-trigger btn btn-quiet btn-small"
        aria-label="<?= htmlspecialchars('View details for ' . $log['action'] . ' at ' . $formattedTimestamp) ?>"><i class="bi bi-layout-sidebar-reverse" aria-hidden="true"></i> Details</button>
    <template class="audit-details-template">
        <dl class="audit-details-content">
            <div class="audit-detail-field">
                <dt>IP Address</dt>
                <dd class="audit-code"><?= htmlspecialchars((string)($log['ip_address'] ?? '-')) ?></dd>
            </div>
            <div class="audit-detail-field">
                <dt>Previous Value</dt>
                <dd><?= audit_log_detail_value_html($previousValue) ?></dd>
            </div>
            <div class="audit-detail-field">
                <dt>New Value</dt>
                <dd><?= audit_log_detail_value_html($newValue) ?></dd>
            </div>
            <?php if ($log['action'] === \App\Attention\FailedSignInReviewService::ACTION): ?>
                <div class="audit-detail-field">
                    <dt>Reviewed Evidence</dt>
                    <dd><?= audit_log_detail_value_html(audit_log_display_value($log['metadata'] ?? null)) ?></dd>
                </div>
            <?php endif; ?>
            <?php if ($log['action'] === \App\Attention\ApplicationLogReviewService::ACTION): ?>
                <div class="audit-detail-field">
                    <dt>Reviewed Log Excerpt</dt>
                    <dd><pre><?= htmlspecialchars((string)(json_decode((string)($log['metadata'] ?? ''), true)['excerpt'] ?? '-')) ?></pre></dd>
                </div>
            <?php endif; ?>
        </dl>
    </template>
    <?php
    $details = (string)ob_get_clean();
    return [
        '<span class="audit-date-cell">' . htmlspecialchars($formattedTimestamp) . '</span>',
        $user,
        '<span class="audit-action-badge ' . audit_log_action_class((string)$log['action']) . '">' . htmlspecialchars((string)$log['action']) . '</span>',
        '<a class="audit-category-filter" href="' . htmlspecialchars(audit_log_category_filter_url((string)$log['category'])) . '" aria-label="' .
            htmlspecialchars('Select category ' . $log['category']) . '">' .
            htmlspecialchars(ucwords(str_replace('_', ' ', (string)$log['category']))) . '</a>',
        '<span class="audit-module-cell">' . htmlspecialchars((string)($log['module'] ?? '-')) . '</span>',
        '<span class="audit-code">' . htmlspecialchars((string)($log['record_id'] ?? '-')) . '</span>',
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
            $log['display_name'] ?: ($log['username'] ?: 'System / Unknown'),
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
$modules = $protectedAuditRecords->modules($actorRole, ['category' => $categoryFilter]);
$actions = $protectedAuditRecords->actions($actorRole, ['category' => $categoryFilter, 'module' => $moduleFilter]);
if ($actionFilter !== '' && !in_array($actionFilter, $actions, true)) {
    array_unshift($actions, $actionFilter);
}
$canViewPlatformAudit = has_capability(\App\Authorization\RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT);
$pageTitle = $canViewPlatformAudit ? 'Protected Audit Records' : 'Operational Audit';
$emptyTableCopy = $canViewPlatformAudit
    ? 'No Protected Audit Records match the selected filters.'
    : 'No Operational Audit records match the selected filters.';

$activeFilters = [];
if ($moduleFilter !== '') {
    $activeFilters[] = ['label' => 'Module', 'value' => $moduleFilter];
}
if ($categoryFilter !== '') {
    $activeFilters[] = ['label' => 'Category', 'value' => ucwords(str_replace('_', ' ', $categoryFilter))];
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
                <div class="page-heading-title">
                    <h1><?= htmlspecialchars($pageTitle) ?></h1>
                </div>
                <form method="POST" class="audit-log-export-form" id="auditLogExportForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="export_csv">
                    <input type="hidden" name="user_id" id="exportUserFilter" value="<?= htmlspecialchars($userFilter) ?>">
                    <input type="hidden" name="action_filter" id="exportActionFilter" value="<?= htmlspecialchars($actionFilter) ?>">
                    <input type="hidden" name="category" id="exportCategoryFilter" value="<?= htmlspecialchars($categoryFilter) ?>">
                    <input type="hidden" name="module" id="exportModuleFilter" value="<?= htmlspecialchars($moduleFilter) ?>">
                    <input type="hidden" name="date_from" id="exportDateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="date_to" id="exportDateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <input type="hidden" name="table_request" id="exportTableRequest" value="{}">
                    <button type="submit" class="btn btn-quiet"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</button>
                </form>
            </header>

            <?php if ($activeFilters !== []): ?>
                <aside class="active-filters-bar" aria-label="Applied filters">
                    <span class="active-filters-label"><i class="bi bi-funnel" aria-hidden="true"></i> Filters</span>
                    <div class="active-filters-list">
                        <?php foreach ($activeFilters as $filter): ?>
                            <span class="filter-chip"><span class="filter-chip-key"><?= htmlspecialchars($filter['label']) ?></span><strong class="filter-chip-val"><?= htmlspecialchars($filter['value']) ?></strong></span>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?= htmlspecialchars(app_url('components/system_administrator/audit_logs.php')) ?>" class="btn-clear-filters" aria-label="Clear active filters"><i class="bi bi-x-circle" aria-hidden="true"></i> Clear</a>
                </aside>
            <?php endif; ?>

            <div class="audit-log-stats">
                <div class="stat-card with-icon">
                    <div class="stat-meta">
                        <span class="stat-label">Matching Records</span>
                        <span class="stat-icon" aria-hidden="true"><i class="bi bi-card-checklist"></i></span>
                    </div>
                    <div class="stat-value" id="matchingRecordsCount" aria-live="polite" aria-atomic="true"><?= $matchingRecordCount ?></div>
                </div>
                <div class="stat-card with-icon success">
                    <div class="stat-meta">
                        <span class="stat-label">Active Users</span>
                        <span class="stat-icon" aria-hidden="true"><i class="bi bi-person-check-fill"></i></span>
                    </div>
                    <div class="stat-value"><?= $activeUserCount ?></div>
                </div>
                <div class="stat-card with-icon">
                    <div class="stat-meta">
                        <span class="stat-label">Logged Modules</span>
                        <span class="stat-icon" aria-hidden="true"><i class="bi bi-boxes"></i></span>
                    </div>
                    <div class="stat-value"><?= count($modules) ?></div>
                </div>
            </div>

            <?php if ($actorRole === 'super_admin'): ?>
                <section class="dashboard-section audit-table-card" id="failed-sign-in-review" aria-labelledby="sign-in-review-title">
                    <div class="section-header">
                        <h2 id="sign-in-review-title"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Failed Sign-ins<?php if ($pendingSignInCount > 0): ?><span class="section-count-badge"><?= $pendingSignInCount ?></span><?php endif; ?></h2>
                    </div>
                    <?php if ($reviewMessage !== ''): ?>
                        <p class="alert tag-success" role="status"><?= htmlspecialchars($reviewMessage) ?></p>
                    <?php endif; ?>
                    <?php if ($reviewError !== ''): ?>
                        <p class="alert tag-danger" role="alert"><?= htmlspecialchars($reviewError) ?></p>
                    <?php endif; ?>
                    <?php if ($pendingSignIns === []): ?>
                        <div class="audit-empty-state"><i class="bi bi-shield-check" aria-hidden="true"></i> No failed sign-ins need review</div>
                    <?php else: ?>
                        <div class="table-wrap audit-log-table-wrap">
                            <table class="audit-log-table" data-no-smart-table data-no-ghost-rows aria-label="Audit log entries">
                                <caption class="visually-hidden">Oldest <?= count($pendingSignIns) ?> unreviewed attempts</caption>
                                <thead><tr><th scope="col">Date &amp; Time</th><th scope="col">Account</th><th scope="col">IP Address</th></tr></thead>
                                <tbody>
                                    <?php foreach ($pendingSignIns as $attempt): ?>
                                        <tr>
                                            <td class="audit-date-cell"><?= htmlspecialchars(format_display_datetime($attempt['attempted_at'])) ?></td>
                                            <td><?= htmlspecialchars($attempt['username']) ?></td>
                                            <td class="audit-code"><?= htmlspecialchars($attempt['ip_address']) ?></td>
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
                                        <option value="">Choose result</option>
                                        <?php foreach (\App\Attention\FailedSignInReviewService::RESULTS as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= ($_POST['review_result'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="sign-in-review-note">Review note <span class="required-mark" aria-hidden="true">*</span></label>
                                    <textarea id="sign-in-review-note" name="review_note" rows="1" maxlength="1000" placeholder="Add review notes…" required><?= htmlspecialchars((string)($_POST['review_note'] ?? '')) ?></textarea>
                                </div>
                            </div>
                            <div class="sign-in-review-actions">
                                <label class="sign-in-review-confirm"><input type="checkbox" name="review_confirmed" value="1" required> <span>I confirm I reviewed the listed attempts. <span class="required-mark" aria-hidden="true">*</span></span></label>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check" aria-hidden="true"></i> Save Review &amp; Clear</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="dashboard-section audit-table-card" aria-labelledby="audit-table-title">
                <div class="section-header">
                    <h2 id="audit-table-title"><i class="bi bi-journal-text" aria-hidden="true"></i> Record History</h2>
                </div>
                <form method="GET" class="audit-history-filters" id="auditHistoryFilters" aria-label="Filter record history">
                    <?php foreach (['user_id' => $userFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo] as $name => $value): ?>
                        <?php if ($value !== ''): ?><input type="hidden" name="<?= $name ?>" value="<?= htmlspecialchars($value) ?>"><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($canViewPlatformAudit): ?>
                    <div class="form-group">
                        <label for="audit-history-category">Category</label>
                        <select id="audit-history-category" name="category">
                            <option value="">All categories</option>
                            <?php foreach (\App\Audit\AuditRecordCategory::ALL as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $category))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="audit-history-module">Module</label>
                        <select id="audit-history-module" name="module">
                            <option value="">All modules</option>
                            <?php foreach ($modules as $module): ?>
                            <option value="<?= htmlspecialchars($module) ?>" <?= $moduleFilter === $module ? 'selected' : '' ?>><?= htmlspecialchars($module) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="audit-history-action">Action</label>
                        <select id="audit-history-action" name="action">
                            <option value="">All actions</option>
                            <?php foreach ($actions as $action): ?>
                            <option value="<?= htmlspecialchars($action) ?>" <?= $actionFilter === $action ? 'selected' : '' ?>><?= htmlspecialchars($action) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-quiet">Show records</button>
                    <a class="btn btn-quiet" href="<?= htmlspecialchars(app_url('components/system_administrator/audit_logs.php')) ?>">Clear</a>
                </form>
                <p id="audit-table-error" class="alert tag-danger" role="alert" hidden></p>
                <noscript><p>Enable JavaScript to search and browse record history. Export CSV remains available.</p></noscript>
                <div class="table-wrap audit-log-table-wrap audit-history-wrap">
                    <table id="auditLogsTable" class="audit-log-table display" data-no-smart-table aria-label="Audit logs">
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
    <div class="rm-drawer-overlay" id="auditDetailsDrawer" aria-hidden="true">
        <aside class="rm-drawer" role="dialog" aria-modal="true" aria-labelledby="auditDetailsTitle">
            <header class="rm-drawer-header">
                <div>
                    <h2 id="auditDetailsTitle">Record Details</h2>
                    <p id="auditDetailsMeta"></p>
                </div>
                <button type="button" class="rm-close" data-close-drawer aria-label="Close record details"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </header>
            <div class="rm-drawer-body" id="auditDetailsBody"></div>
        </aside>
    </div>

    <script defer src="<?= htmlspecialchars(app_url('assets/vendor/datatables/datatables.min.js')) ?>"></script>
    <script defer src="<?= htmlspecialchars(app_url('assets/vendor/datatables/columncontrol/dataTables.columnControl.min.js')) ?>"></script>
    <script defer src="<?= htmlspecialchars(app_url('assets/vendor/datatables/datetime/dataTables.dateTime.min.js')) ?>"></script>
    <script defer src="<?= htmlspecialchars(app_url('assets/js/audit-log-drawer.js')) ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var historyFilters = document.getElementById('auditHistoryFilters');
            historyFilters.addEventListener('change', function(event) {
                if (event.target.name === 'category') historyFilters.elements.module.value = '';
                if (event.target.name !== 'action') historyFilters.elements.action.value = '';
                historyFilters.requestSubmit();
            });
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
                            placeholder: 'Search audit records...'
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
                    search: '',
                    searchPlaceholder: 'Search audit records...',
                    lengthMenu: '_MENU_ per page',
                    info: 'Showing _START_–_END_ of _TOTAL_ records',
                    infoEmpty: '0 records',
                    infoFiltered: '(filtered from _MAX_)'
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
