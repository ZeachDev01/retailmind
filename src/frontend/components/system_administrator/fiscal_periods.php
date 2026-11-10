<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../../backend/app/Attention/AttentionSettingsService.php';
require_once __DIR__ . '/../../../backend/app/Attention/SystemClock.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::STORE_OPERATIONS);

$message = '';
$error = '';
$actorId = (int)($_SESSION['user_id'] ?? 0);
$guard = new FiscalPeriodGuardService($pdo);

// Lazy automatic rollover: the page that opens after the period end date is
// enough to close the due period and open its scheduled successor.
try {
    $rollover = $guard->rolloverIfDue(null, $actorId);
    if (!empty($rollover['rolled'])) {
        $message = sprintf(
            'Fiscal period "%s" closed automatically. %s',
            (string)$rollover['period_name'],
            $rollover['opened_period_id'] !== null
                ? 'The next scheduled period is now open.'
                : 'Add the next fiscal period to resume trading coverage.'
        );
    } elseif (!empty($rollover['blockers'])) {
        $error = sprintf(
            'Automatic rollover is waiting on %s before "%s" can close.',
            implode('; ', $rollover['blockers']),
            (string)$rollover['period_name']
        );
    }
} catch (Throwable $exception) {
    error_log('Fiscal period automatic rollover skipped: ' . $exception->getMessage());
}

$flash = $_SESSION['fiscal_period_flash'] ?? null;
unset($_SESSION['fiscal_period_flash']);
if (is_array($flash)) {
    $message = (string)($flash['message'] ?? '');
    $error = (string)($flash['error'] ?? '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $periodId = (int)($_POST['period_id'] ?? 0);

    if ($action === 'create') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $periodName = trim((string)($_POST['period_name'] ?? ''));
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));

        if ($periodName === '' || $startDate === '' || $endDate === '') {
            $error = 'All fields are required';
        } else {
            try {
                $createdId = $guard->createPeriod($periodName, $startDate, $endDate, $actorId);
                $statusStatement = $pdo->prepare('SELECT status FROM fiscal_periods WHERE period_id = ?');
                $statusStatement->execute([$createdId]);
                $createdStatus = (string)$statusStatement->fetchColumn();
                $message = $createdStatus === 'open'
                    ? "Fiscal period \"{$periodName}\" created and opened."
                    : "Fiscal period \"{$periodName}\" scheduled. It opens on " . date('M j, Y', strtotime($startDate)) . '.';
                log_activity(
                    $pdo,
                    $actorId,
                    'Fiscal period creation',
                    'Fiscal Periods',
                    $createdId,
                    null,
                    [
                        'period_id' => $createdId,
                        'period_name' => $periodName,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'status' => $createdStatus,
                    ]
                );
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'open') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        if ($periodId <= 0) {
            $error = 'Invalid period';
        } else {
            try {
                $guard->openScheduledPeriod($periodId, $actorId);
                $message = 'Fiscal period opened successfully';
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'close') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        if ($periodId <= 0) {
            $error = 'Invalid period';
        } else {
            $beforeStatement = $pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?');
            $beforeStatement->execute([$periodId]);
            $before = $beforeStatement->fetch();
            try {
                $guard->closePeriod($periodId, $actorId);
                $afterStatement = $pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?');
                $afterStatement->execute([$periodId]);
                $message = 'Fiscal period closed successfully';
                log_activity(
                    $pdo,
                    $actorId,
                    'Fiscal-period closing',
                    'Fiscal Periods',
                    $periodId,
                    $before,
                    $afterStatement->fetch()
                );
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'lock') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        if ($periodId <= 0) {
            $error = 'Invalid period';
        } else {
            $beforeStatement = $pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?');
            $beforeStatement->execute([$periodId]);
            $before = $beforeStatement->fetch();
            try {
                $guard->lockPeriod($periodId);
                $afterStatement = $pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?');
                $afterStatement->execute([$periodId]);
                $message = 'Fiscal period locked successfully';
                log_activity(
                    $pdo,
                    $actorId,
                    'Fiscal period locked',
                    'Fiscal Periods',
                    $periodId,
                    $before,
                    $afterStatement->fetch()
                );
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'amend_end') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        if ($periodId <= 0) {
            $error = 'Invalid period';
        } else {
            try {
                $amended = $guard->amendScheduledEndDate($periodId, trim((string)($_POST['end_date'] ?? '')));
                $message = 'Scheduled fiscal period end date updated';
                log_activity(
                    $pdo,
                    $actorId,
                    'Scheduled fiscal period end date amended',
                    'Fiscal Periods',
                    $periodId,
                    $amended['before'] ?? null,
                    ['end_date' => $amended['end_date'] ?? null]
                );
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        if ($periodId <= 0) {
            $error = 'Invalid period';
        } else {
            try {
                $deleted = $guard->deleteScheduledPeriod($periodId, $actorId);
                $message = sprintf('Scheduled fiscal period "%s" deleted', (string)($deleted['period_name'] ?? ''));
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'generate') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        try {
            $generated = $guard->generatePeriodSchedule(
                (string)($_POST['cadence'] ?? ''),
                (int)($_POST['count'] ?? 0),
                (string)($_POST['prefix'] ?? ''),
                $actorId
            );
            $periodCount = count($generated['periods']);
            $message = sprintf(
                'Generated %d %s fiscal period%s: %s to %s.%s',
                $periodCount,
                (string)($_POST['cadence'] ?? ''),
                $periodCount === 1 ? '' : 's',
                date('M j, Y', strtotime((string)$generated['start_date'])),
                date('M j, Y', strtotime((string)$generated['end_date'])),
                $generated['auto_rollover_enabled'] ? ' Automatic rollover is now on.' : ''
            );
            $lastPeriod = $generated['periods'][$periodCount - 1];
            log_activity(
                $pdo,
                $actorId,
                'Fiscal period schedule generated',
                'Fiscal Periods',
                (int)$lastPeriod['period_id'],
                null,
                $generated['periods']
            );
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'set_rollover') {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        if (current_role() !== 'admin') {
            $error = 'Only the Administrator may change automatic rollover.';
        } else {
            $value = ((string)($_POST['auto_rollover'] ?? '0')) === '1' ? 1.0 : 0.0;
            try {
                $settings = new App\Attention\AttentionSettingsService($pdo, new App\Attention\SystemClock());
                $settings->updateStore('admin', [FiscalPeriodGuardService::AUTO_ROLLOVER_SETTING => $value], $actorId);
                $message = $value > 0
                    ? 'Automatic rollover is now on. Due periods close and the next scheduled period opens.'
                    : 'Automatic rollover is now off. Periods close only when you close them.';
                log_activity(
                    $pdo,
                    $actorId,
                    'Fiscal period automatic rollover setting changed',
                    'Fiscal Periods',
                    null,
                    null,
                    ['fiscal_period_auto_rollover' => $value]
                );
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }

    $_SESSION['fiscal_period_flash'] = ['message' => $message, 'error' => $error];
    header('Location: ' . app_url('components/system_administrator/fiscal_periods.php'), true, 303);
    exit;
}

$periodsStatement = $pdo->query("SELECT fp.*, u.display_name AS created_by_name, cu.display_name AS closed_by_name
                                 FROM fiscal_periods fp
                                 LEFT JOIN users u ON fp.created_by = u.user_id
                                 LEFT JOIN users cu ON fp.closed_by = cu.user_id
                                 ORDER BY fp.start_date DESC");
$periods = $periodsStatement->fetchAll();
$statusCounts = ['scheduled' => 0, 'open' => 0, 'closed' => 0, 'locked' => 0];
foreach ($periods as $period) {
    $status = strtolower((string)$period['status']);
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

$today = date('Y-m-d');
$scheduledQueue = array_values(array_filter(
    $periods,
    static fn(array $period): bool => strtolower((string)$period['status']) === FiscalPeriodGuardService::STATUS_SCHEDULED
));
usort($scheduledQueue, static fn(array $a, array $b): int => strcmp((string)$a['start_date'], (string)$b['start_date'])
    ?: ((int)$a['period_id'] <=> (int)$b['period_id']));
$nextScheduled = $scheduledQueue[0] ?? null;
$openableId = ($statusCounts['open'] === 0 && $nextScheduled && $nextScheduled['start_date'] <= $today)
    ? (int)$nextScheduled['period_id']
    : null;
$suggestedStart = $periods
    ? date('Y-m-d', strtotime((string)$periods[0]['end_date'] . ' +1 day'))
    : $today;

$periods = FiscalPeriodGuardService::orderForDisplay($periods);

$autoRollover = false;
try {
    $attentionSettings = new App\Attention\AttentionSettingsService($pdo, new App\Attention\SystemClock());
    $autoRollover = (float)($attentionSettings->storeSettings()[FiscalPeriodGuardService::AUTO_ROLLOVER_SETTING] ?? 0) > 0;
} catch (Throwable $exception) {
    $autoRollover = false;
}

$scheduledHint = static function (array $period) use ($statusCounts, $today, $openableId): string {
    if ($openableId === (int)$period['period_id']) {
        return '';
    }
    if ($statusCounts['open'] > 0) {
        return 'Waiting for the current period to close';
    }
    if ((string)$period['start_date'] > $today) {
        return 'Opens ' . date('M j, Y', strtotime((string)$period['start_date']));
    }
    return 'An earlier scheduled period opens first';
};
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiscal Periods</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/fiscal-periods.css')) ?>">
</head>
<body class="fiscal-period-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <header class="topbar fiscal-topbar">
                <div>
                    <h1>Fiscal Periods</h1>
                </div>
                <div class="fiscal-rollover-control">
                    <span class="fiscal-rollover-label">Auto rollover</span>
                    <span class="info-tooltip">
                        <button class="info-tooltip-trigger" type="button" aria-label="About automatic rollover" aria-describedby="auto-rollover-help"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
                        <span class="tooltip-box" id="auto-rollover-help" role="tooltip">When on, a due period closes by itself and the next scheduled period opens. Blockers still keep a period open.</span>
                    </span>
                    <?php if (current_role() === 'admin'): ?>
                        <form method="POST" class="fiscal-rollover-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_rollover">
                            <input type="hidden" name="auto_rollover" value="<?= $autoRollover ? '0' : '1' ?>">
                            <button type="submit" class="fiscal-switch" role="switch" aria-checked="<?= $autoRollover ? 'true' : 'false' ?>" aria-label="Automatic rollover, currently <?= $autoRollover ? 'on' : 'off' ?>">
                                <span class="fiscal-switch-track" aria-hidden="true"><span class="fiscal-switch-thumb"></span></span>
                                <span class="fiscal-switch-label"><?= $autoRollover ? 'On' : 'Off' ?></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="fiscal-rollover-state<?= $autoRollover ? ' is-on' : '' ?>"><?= $autoRollover ? 'On' : 'Off' ?></span>
                        <a class="fiscal-rollover-link" href="<?= htmlspecialchars(app_url('components/administrator/store_settings.php')) ?>">Settings</a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($message !== ''): ?>
                <div class="message success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><span><?= htmlspecialchars($message) ?></span></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="message error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <section class="fiscal-summary" aria-label="Fiscal period summary">
                <div class="fiscal-summary-item is-open">
                    <span class="fiscal-summary-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></span>
                    <span class="fiscal-summary-copy"><strong><?= $statusCounts['open'] ?></strong><span>Open</span></span>
                </div>
                <div class="fiscal-summary-item is-scheduled">
                    <span class="fiscal-summary-icon"><i class="bi bi-calendar2-week" aria-hidden="true"></i></span>
                    <span class="fiscal-summary-copy"><strong><?= $statusCounts['scheduled'] ?></strong><span>Scheduled</span></span>
                </div>
                <div class="fiscal-summary-item is-closed">
                    <span class="fiscal-summary-icon"><i class="bi bi-calendar2-minus" aria-hidden="true"></i></span>
                    <span class="fiscal-summary-copy"><strong><?= $statusCounts['closed'] ?></strong><span>Closed</span></span>
                </div>
                <div class="fiscal-summary-item is-locked">
                    <span class="fiscal-summary-icon"><i class="bi bi-lock" aria-hidden="true"></i></span>
                    <span class="fiscal-summary-copy"><strong><?= $statusCounts['locked'] ?></strong><span>Locked</span></span>
                </div>
            </section>

            <section class="dashboard-section fiscal-period-create" aria-labelledby="create-period-title">
                <div class="fiscal-section-header">
                    <h2 id="create-period-title"><i class="bi bi-plus-circle" aria-hidden="true"></i> New period
                        <span class="info-tooltip">
                            <button class="info-tooltip-trigger" type="button" aria-label="About fiscal period dates" aria-describedby="create-period-help"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
                            <span class="tooltip-box" id="create-period-help" role="tooltip">A future range stays scheduled until its start date, then opens manually or automatically. Only one period can be open at a time.</span>
                        </span>
                    </h2>
                </div>
                <form method="POST" class="fiscal-period-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="fiscal-period-form-grid">
                        <div class="form-group">
                            <label for="period_name">Period name</label>
                            <input type="text" id="period_name" name="period_name" placeholder="e.g. Q1 2026" autocomplete="off" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="start_date">Start date</label>
                            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($suggestedStart) ?>" autocomplete="off" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="end_date">End date</label>
                            <input type="date" id="end_date" name="end_date" autocomplete="off" required aria-required="true">
                        </div>
                        <button type="submit" class="btn btn-primary fiscal-create-button"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create period</button>
                    </div>
                </form>

                <div class="fiscal-generate">
                    <div class="fiscal-subheading">
                        <h3><i class="bi bi-calendar-plus" aria-hidden="true"></i> Generate a schedule</h3>
                        <span class="info-tooltip">
                            <button class="info-tooltip-trigger" type="button" aria-label="About schedule generation" aria-describedby="generate-period-help"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
                            <span class="tooltip-box" id="generate-period-help" role="tooltip">Creates a continuous run of calendar-aligned periods starting the day after the latest period ends. The whole set is created together, or nothing is created if a name already exists.</span>
                        </span>
                    </div>
                    <form method="POST" class="fiscal-period-form fiscal-generate-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <input type="hidden" name="action" value="generate">
                        <div class="fiscal-period-form-grid">
                            <div class="form-group">
                                <label for="cadence">Cadence</label>
                                <select id="cadence" name="cadence">
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="count">Periods to generate</label>
                                <input type="number" id="count" name="count" min="1" max="24" value="12" required aria-required="true">
                            </div>
                            <div class="form-group">
                                <label for="prefix">Name prefix (optional)</label>
                                <input type="text" id="prefix" name="prefix" maxlength="20" placeholder="e.g. FY" autocomplete="off">
                            </div>
                            <button type="submit" class="btn btn-secondary fiscal-create-button"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Generate periods</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="fiscal-period-list" aria-labelledby="period-list-title">
                <div class="fiscal-period-list-heading">
                    <h2 id="period-list-title"><i class="bi bi-calendar-range" aria-hidden="true"></i> Fiscal periods</h2>
                    <span class="fiscal-period-count"><?= count($periods) ?> total</span>
                </div>

                <?php if (empty($periods)): ?>
                    <div class="dashboard-section fiscal-period-empty" role="status">
                        <i class="bi bi-calendar2-plus" aria-hidden="true"></i>
                        <strong>No fiscal periods yet</strong>
                        <span>Create the first period using the form above.</span>
                    </div>
                <?php else: ?>
                    <div class="fiscal-period-card-grid">
                        <?php foreach ($periods as $period): ?>
                            <?php
                            $periodStatus = strtolower((string)$period['status']);
                            $cardTitleId = 'period-heading-' . (int)$period['period_id'];
                            $endDateId = 'period-end-' . (int)$period['period_id'];
                            $hintId = 'period-hint-' . (int)$period['period_id'];
                            $startTimestamp = strtotime((string)$period['start_date']);
                            $endTimestamp = strtotime((string)$period['end_date']);
                            $sameYear = date('Y', $startTimestamp) === date('Y', $endTimestamp);
                            $startLabel = date($sameYear ? 'M j' : 'M j, Y', $startTimestamp);
                            $endLabel = date('M j, Y', $endTimestamp);
                            $scheduledNote = $periodStatus === 'scheduled' && $openableId !== (int)$period['period_id']
                                ? $scheduledHint($period)
                                : '';
                            ?>
                            <article class="fiscal-period-card<?= $periodStatus === 'open' ? ' is-open' : '' ?>" aria-labelledby="<?= $cardTitleId ?>">
                                <div class="fiscal-period-card-header">
                                    <div class="fiscal-period-title">
                                        <span class="fiscal-period-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
                                        <div>
                                            <h3 id="<?= $cardTitleId ?>"><?= htmlspecialchars($period['period_name']) ?></h3>
                                            <span class="fiscal-period-range"><time datetime="<?= htmlspecialchars((string)$period['start_date']) ?>"><?= htmlspecialchars($startLabel) ?></time> – <time datetime="<?= htmlspecialchars((string)$period['end_date']) ?>"><?= htmlspecialchars($endLabel) ?></time></span>
                                        </div>
                                    </div>
                                    <div class="fiscal-period-badges">
                                        <span class="fiscal-status-badge <?= htmlspecialchars($periodStatus) ?>"><?= htmlspecialchars(ucfirst($periodStatus)) ?></span>
                                        <?php if ($scheduledNote !== ''): ?>
                                            <span class="info-tooltip">
                                                <button class="info-tooltip-trigger" type="button" aria-label="Why this period is waiting" aria-describedby="<?= $hintId ?>"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
                                                <span class="tooltip-box" id="<?= $hintId ?>" role="tooltip"><?= htmlspecialchars($scheduledNote) ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <details class="fiscal-period-more">
                                    <summary><i class="bi bi-chevron-right fiscal-caret" aria-hidden="true"></i> Details</summary>
                                    <dl class="fiscal-period-details">
                                        <div>
                                            <dt>Created by</dt>
                                            <dd><?= htmlspecialchars($period['created_by_name'] ?? 'Unknown') ?></dd>
                                        </div>
                                        <div>
                                            <dt>Created at</dt>
                                            <dd><?= htmlspecialchars(format_display_datetime($period['created_at'])) ?></dd>
                                        </div>
                                        <?php if (in_array($periodStatus, ['closed', 'locked'], true)): ?>
                                            <div>
                                                <dt>Closed by</dt>
                                                <dd><?= htmlspecialchars($period['closed_by_name'] ?? ($period['closed_by'] === null && $period['closed_at'] ? 'Automatic rollover' : 'Unknown')) ?></dd>
                                            </div>
                                            <div>
                                                <dt>Closed at</dt>
                                                <dd><?= htmlspecialchars($period['closed_at'] ? format_display_datetime($period['closed_at']) : 'N/A') ?></dd>
                                            </div>
                                        <?php endif; ?>
                                    </dl>
                                </details>

                                <?php if ($periodStatus === 'open'): ?>
                                    <div class="fiscal-period-actions">
                                        <form method="POST" data-confirm="Closing prevents new entries but keeps records viewable." data-confirm-title="Close fiscal period" data-confirm-button="Close period">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="close">
                                            <input type="hidden" name="period_id" value="<?= (int)$period['period_id'] ?>">
                                            <button type="submit" class="btn btn-quiet fiscal-action-close" aria-label="Close fiscal period <?= htmlspecialchars($period['period_name']) ?>"><i class="bi bi-calendar2-minus" aria-hidden="true"></i> Close period</button>
                                        </form>
                                    </div>
                                <?php elseif ($periodStatus === 'closed'): ?>
                                    <div class="fiscal-period-actions">
                                        <form method="POST" data-confirm="Locking makes all period records read-only for compliance." data-confirm-title="Lock fiscal period" data-confirm-button="Lock period" data-confirm-danger="1">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="lock">
                                            <input type="hidden" name="period_id" value="<?= (int)$period['period_id'] ?>">
                                            <button type="submit" class="btn btn-quiet fiscal-action-lock" aria-label="Lock fiscal period <?= htmlspecialchars($period['period_name']) ?>"><i class="bi bi-lock" aria-hidden="true"></i> Lock period</button>
                                        </form>
                                    </div>
                                <?php elseif ($periodStatus === 'scheduled'): ?>
                                    <div class="fiscal-period-actions">
                                        <form method="POST" class="fiscal-delete-form" data-confirm="Deleting removes this reserved range. Later periods must be deleted first." data-confirm-title="Delete scheduled period" data-confirm-button="Delete period" data-confirm-danger="1">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="period_id" value="<?= (int)$period['period_id'] ?>">
                                            <button type="submit" class="fiscal-icon-button" aria-label="Delete scheduled fiscal period <?= htmlspecialchars($period['period_name']) ?>" title="Delete"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                        </form>
                                        <details class="fiscal-period-amend">
                                            <summary><i class="bi bi-chevron-right fiscal-caret" aria-hidden="true"></i> Edit end date</summary>
                                            <form method="POST" class="fiscal-period-amend-form">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="amend_end">
                                                <input type="hidden" name="period_id" value="<?= (int)$period['period_id'] ?>">
                                                <label for="<?= $endDateId ?>">End date</label>
                                                <input type="date" id="<?= $endDateId ?>" name="end_date" value="<?= htmlspecialchars((string)$period['end_date']) ?>" min="<?= htmlspecialchars((string)$period['start_date']) ?>" required aria-required="true">
                                                <button type="submit" class="btn btn-primary">Save</button>
                                            </form>
                                        </details>
                                        <?php if ($openableId === (int)$period['period_id']): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="open">
                                                <input type="hidden" name="period_id" value="<?= (int)$period['period_id'] ?>">
                                                <button type="submit" class="btn btn-primary" aria-label="Open scheduled fiscal period <?= htmlspecialchars($period['period_name']) ?>"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Open period</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="<?= htmlspecialchars(app_url('assets/js/info-tooltip.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/info-tooltip.js')) ?>" defer></script>
</body>
</html>
