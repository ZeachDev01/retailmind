<?php
// components/administrator/store_calendar.php — Store calendar administration.
//
// Calendar status provides Store and forecast context for operational planning.
// It does not gate checkout or close shifts.
// In demand forecasts, Holiday represents a closed trading day; choose Open if trading on a holiday.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin', 'super_admin']);

use App\Store\StoreCalendar;
use App\Services\PhilippineTime;
use App\Support\OperatorAlert;

$calendar = new StoreCalendar();
$message = '';
$messageClass = '';

// Current Philippine Date
$today = substr(PhilippineTime::dayStart(date('Y-m-d')), 0, 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    try {
        $action = trim($_POST['action'] ?? 'save_date');

        if ($action === 'save_weekly_schedule') {
            $effectiveDate = trim($_POST['effective_date'] ?? '');
            try {
                $formattedDate = PhilippineTime::dayStart($effectiveDate);
                $effectiveDate = substr($formattedDate, 0, 10);
            } catch (Throwable) {
                throw new RuntimeException("Invalid effective date for weekly schedule.");
            }

            $daysOpen = [
                'monday' => !empty($_POST['monday_open']),
                'tuesday' => !empty($_POST['tuesday_open']),
                'wednesday' => !empty($_POST['wednesday_open']),
                'thursday' => !empty($_POST['thursday_open']),
                'friday' => !empty($_POST['friday_open']),
                'saturday' => !empty($_POST['saturday_open']),
                'sunday' => !empty($_POST['sunday_open']),
            ];

            $scheduleId = $calendar->recordWeeklySchedule(
                $pdo,
                $effectiveDate,
                $daysOpen,
                (int)$_SESSION['user_id'],
                'manual'
            );

            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Recorded Store weekly schedule',
                'Store Settings',
                null,
                null,
                'effective_' . $effectiveDate,
                null,
                null,
                ['schedule_id' => $scheduleId, 'effective_date' => $effectiveDate]
            );

            $message = "Weekly store schedule recorded effective {$effectiveDate}.";
            $messageClass = 'tag-success';
        } elseif ($action === 'schedule_staff_shift') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $shiftDate = trim((string)($_POST['shift_date'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $newShiftId = $calendar->scheduleStaffShift(
                $pdo,
                $userId,
                $shiftDate,
                $startTime,
                $endTime,
                $notes ?: null,
                (int)$_SESSION['user_id']
            );

            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Scheduled staff shift',
                'Store Settings',
                $newShiftId,
                null,
                null,
                null,
                null,
                ['user_id' => $userId, 'shift_date' => $shiftDate, 'start_time' => $startTime, 'end_time' => $endTime]
            );

            $message = "Staff shift scheduled for {$shiftDate}.";
            $messageClass = 'tag-success';
        } elseif ($action === 'delete_staff_shift') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $calendar->deleteStaffShift($pdo, $shiftId);

            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Removed staff shift',
                'Store Settings',
                $shiftId
            );

            $message = "Staff shift removed.";
            $messageClass = 'tag-success';
        } else {
            $date = trim($_POST['calendar_date'] ?? '');
            $throughDate = trim($_POST['through_date'] ?? '');
            $status = trim($_POST['status'] ?? '');

            // Validate date
            try {
                $formattedDate = PhilippineTime::dayStart($date);
                $date = substr($formattedDate, 0, 10);
            } catch (Throwable) {
                throw new RuntimeException("Invalid calendar date.");
            }

            if ($throughDate !== '') {
                try {
                    $formattedThrough = PhilippineTime::dayStart($throughDate);
                    $throughDate = substr($formattedThrough, 0, 10);
                } catch (Throwable) {
                    throw new RuntimeException("Invalid through date.");
                }
            }

            $status = StoreCalendar::normalizeStatus($status);
            if (!in_array($status, ['open', 'closed', 'holiday', 'exceptional_closure'], true)) {
                throw new RuntimeException("Invalid status provided.");
            }

            $pdo->beginTransaction();
            try {
                if ($throughDate !== '' && $throughDate !== $date) {
                    // Range save
                    $changed = $calendar->recordCalendarRange($pdo, $date, $throughDate, $status, (int)$_SESSION['user_id']);
                    foreach ($changed as $item) {
                        log_activity(
                            $pdo,
                            (int)$_SESSION['user_id'],
                            'Recorded Store calendar date',
                            'Store Settings',
                            null,
                            $item['previous_status'],
                            $item['new_status'],
                            null,
                            null,
                            ['calendar_date' => $item['date']]
                        );
                    }
                    $count = count($changed);
                    $message = "Store calendar status recorded for {$count} changed date(s) from {$date} through {$throughDate}.";
                } else {
                    // Single date save
                    $cutoff = date('Y-m-d H:i:s');
                    $previousStatus = $calendar->getCalendarDateStatusAtCutoff($pdo, $date, $cutoff);
                    if ($previousStatus !== $status) {
                        $calendar->recordCalendarDate($pdo, $date, $status, (int)$_SESSION['user_id']);
                        log_activity(
                            $pdo,
                            (int)$_SESSION['user_id'],
                            'Recorded Store calendar date',
                            'Store Settings',
                            null,
                            $previousStatus,
                            $status,
                            null,
                            null,
                            ['calendar_date' => $date]
                        );
                    }
                    $message = "Store calendar status recorded for {$date}.";
                }
                $pdo->commit();
                $messageClass = 'tag-success';
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } catch (Throwable $exception) {
        $message = OperatorAlert::message($exception, 'The calendar date could not be recorded. Check the date and try again.');
        $messageClass = 'tag-warning';
    }
}

// Month Navigation Parameters
$viewYear = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)substr($today, 0, 4);
$viewMonth = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)substr($today, 5, 2);

if ($viewMonth < 1) {
    $viewMonth = 1;
} elseif ($viewMonth > 12) {
    $viewMonth = 12;
}

$firstOfMonth = sprintf('%04d-%02d-01', $viewYear, $viewMonth);
$daysInMonth = (int)date('t', strtotime($firstOfMonth));
$lastOfMonth = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $daysInMonth);
$startDayOfWeek = (int)date('w', strtotime($firstOfMonth)); // 0 = Sun, 6 = Sat

$prevMonth = $viewMonth - 1;
$prevYear = $viewYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $viewMonth + 1;
$nextYear = $viewYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Active weekly schedule for form defaults
$activeWeeklySchedule = $calendar->getWeeklyScheduleAtCutoff($pdo, $today, date('Y-m-d H:i:s'));
$schedMon = $activeWeeklySchedule ? (int)$activeWeeklySchedule['monday_open'] : 1;
$schedTue = $activeWeeklySchedule ? (int)$activeWeeklySchedule['tuesday_open'] : 1;
$schedWed = $activeWeeklySchedule ? (int)$activeWeeklySchedule['wednesday_open'] : 1;
$schedThu = $activeWeeklySchedule ? (int)$activeWeeklySchedule['thursday_open'] : 1;
$schedFri = $activeWeeklySchedule ? (int)$activeWeeklySchedule['friday_open'] : 1;
$schedSat = $activeWeeklySchedule ? (int)$activeWeeklySchedule['saturday_open'] : 0;
$schedSun = $activeWeeklySchedule ? (int)$activeWeeklySchedule['sunday_open'] : 0;
$schedEffective = $activeWeeklySchedule ? $activeWeeklySchedule['effective_date'] : $today;

// Query resolved calendar evidence across the month
$monthEvidence = $calendar->getCalendarDateEvidenceRangeAtCutoff($pdo, $firstOfMonth, $lastOfMonth, date('Y-m-d H:i:s'));
$monthStatuses = [];
foreach ($monthEvidence as $d => $ev) {
    if ($ev['status'] !== null) {
        $monthStatuses[$d] = $ev['status'];
    }
}
$monthTitle = date('F Y', strtotime($firstOfMonth));
$monthStaffShifts = $calendar->getStaffShiftsForRange($pdo, $firstOfMonth, $lastOfMonth);
$shiftsByDate = [];
foreach ($monthStaffShifts as $sh) {
    $shiftsByDate[$sh['shift_date']][] = $sh;
}
$allStaffMembers = [];
try {
    $allStaffMembers = $pdo->query(
        "SELECT u.user_id, u.full_name, r.role_name
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         WHERE u.status = 'active' AND (r.role_name IN ('cashier', 'inventory_manager', 'admin') OR r.role_name IS NULL)
         ORDER BY u.full_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Store Calendar</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <style>
        .calendar-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            gap: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .calendar-nav-cluster {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .calendar-nav-btn-group {
            display: inline-flex;
            align-items: center;
            background: var(--theme-surface-soft, #f1f5f9);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 3px;
            gap: 2px;
        }
        .calendar-nav-btn-group .btn-nav {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 0.65rem;
            border: none;
            background: transparent;
            color: var(--text);
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.15s, color 0.15s, box-shadow 0.15s;
        }
        .calendar-nav-btn-group .btn-nav:hover {
            background: var(--theme-surface, #ffffff);
            color: var(--primary-color, #2563eb);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .calendar-nav-btn-group .btn-nav:focus-visible {
            outline: 2px solid var(--primary-color, #2563eb);
            outline-offset: 1px;
        }
        .calendar-nav-btn-group .btn-today {
            font-weight: 600;
            padding: 0 0.85rem;
        }
        .calendar-current-title {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .calendar-jump-form {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .calendar-jump-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--muted);
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .calendar-select-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .calendar-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            min-height: 38px;
            padding: 0.4rem 2.2rem 0.4rem 0.8rem;
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text);
            background-color: var(--theme-surface, #ffffff);
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
        }
        .calendar-select:hover {
            border-color: var(--primary-color, #2563eb);
        }
        .calendar-select:focus-visible {
            outline: 2px solid var(--primary-color, #2563eb);
            outline-offset: 1px;
            border-color: var(--primary-color, #2563eb);
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            min-width: 680px;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .calendar-header-day {
            text-align: center;
            font-weight: 700;
            padding: 0.5rem 0.25rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }
        .calendar-day-cell {
            min-width: 44px;
            font: inherit;
            color: var(--text);
            text-align: left;
            min-height: 88px;
            padding: 0.45rem 0.5rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--theme-surface, #ffffff);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s, transform 0.1s;
        }
        .calendar-day-cell:hover, .calendar-day-cell:focus-visible {
            border-color: var(--primary-color, #2563eb);
            outline: 2px solid var(--primary-color, #2563eb);
            outline-offset: 1px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12);
            transform: translateY(-1px);
        }
        .calendar-day-cell.selected-day {
            border-color: var(--primary-color, #2563eb);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.35);
            background: var(--theme-surface-soft, #eff6ff);
        }
        .calendar-day-cell.is-today {
            border-color: var(--primary-color, #2563eb);
            background: color-mix(in srgb, var(--primary-color, #2563eb) 4%, var(--theme-surface, #ffffff));
        }
        .calendar-day-cell.is-empty {
            background: transparent;
            border: none;
            cursor: default;
        }
        .day-cell-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }
        .day-number {
            font-size: 0.95rem;
            font-weight: 600;
            line-height: 1;
        }
        .day-today-tag {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--primary-color, #2563eb);
            background: rgba(37, 99, 235, 0.12);
            padding: 0.1rem 0.35rem;
            border-radius: 4px;
            line-height: 1.2;
        }
        .day-status {
            font-size: 0.75rem;
            margin-top: 0.35rem;
        }
        .calendar-status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.22rem 0.45rem;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
        .status-open { background: var(--theme-success-surface, #dcfce7); color: var(--theme-success-text, #166534); }
        .status-closed, .status-unrecorded { background: var(--theme-surface-soft, #f1f5f9); color: var(--muted); }
        .status-holiday { background: var(--theme-warning-surface, #fef3c7); color: var(--theme-warning-text, #92400e); }
        .status-exceptional_closure { background: var(--theme-danger-surface, #fee2e2); color: var(--theme-danger-text, #991b1b); }
        .status-sale { background: #bbf7d0; color: #14532d; font-weight: 600; }
        .calendar-status-badge::before {
            content: '';
            display: inline-block;
            width: .45rem;
            height: .45rem;
            margin-right: .3rem;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }
        .status-unrecorded::before { background: transparent; border: 1.5px solid currentColor; }
        .calendar-legend {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
            padding: 0.65rem 0.9rem;
            background: var(--theme-surface-soft, #f8fafc);
            border: 1px solid var(--border);
            border-radius: 8px;
        }
        .calendar-legend-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-right: 0.25rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .calendar-scroll { overflow-x: auto; max-width: 100%; }
        .calendar-context-note {
            margin-top: 1rem;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .calendar-context-note a {
            color: var(--primary-color, #2563eb);
            font-weight: 500;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .schedule-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .schedule-checkbox-card {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--theme-surface, #ffffff);
            cursor: pointer;
            font-size: 0.85rem;
        }
        .schedule-checkbox-card:hover {
            border-color: var(--primary-color, #2563eb);
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <h1>Store Calendar</h1>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <button type="button" class="btn btn-secondary" id="weekly-schedule-btn"><i class="bi bi-calendar-week" aria-hidden="true"></i> Weekly Schedule</button>
                <button type="button" class="btn btn-secondary" id="staff-shifts-btn"><i class="bi bi-person-clock" aria-hidden="true"></i> Staff Shifts &amp; Availability</button>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="dashboard-section">
            <div class="calendar-controls">
                <div class="calendar-nav-cluster">
                    <div class="calendar-nav-btn-group" role="group" aria-label="Month navigation">
                        <a class="btn-nav" href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" aria-label="Previous month" title="Previous month">
                            <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        </a>
                        <a class="btn-nav btn-today" href="?year=<?= substr($today, 0, 4) ?>&month=<?= (int)substr($today, 5, 2) ?>" title="Go to current month">Today</a>
                        <a class="btn-nav" href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" aria-label="Next month" title="Next month">
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <h2 class="calendar-current-title"><?= htmlspecialchars($monthTitle) ?></h2>
                </div>

                <form class="calendar-jump-form" method="get" aria-label="Jump to specific month and year">
                    <span class="calendar-jump-label">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span>Jump to</span>
                    </span>
                    <div class="calendar-select-wrapper">
                        <label class="sr-only" for="calendar-month">Month</label>
                        <select id="calendar-month" name="month" class="calendar-select" aria-label="Select month" onchange="this.form.submit()">
                            <?php for ($month = 1; $month <= 12; $month++): ?>
                                <option value="<?= $month ?>" <?= $month === $viewMonth ? 'selected' : '' ?>><?= htmlspecialchars(date('F', mktime(0, 0, 0, $month, 1))) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="calendar-select-wrapper">
                        <label class="sr-only" for="calendar-year">Year</label>
                        <select id="calendar-year" name="year" class="calendar-select" aria-label="Select year" onchange="this.form.submit()">
                            <?php for ($year = $viewYear - 5; $year <= $viewYear + 5; $year++): ?>
                                <option value="<?= $year ?>" <?= $year === $viewYear ? 'selected' : '' ?>><?= $year ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <noscript>
                        <button type="submit" class="btn btn-secondary btn-small">Go</button>
                    </noscript>
                </form>
            </div>

            <div class="calendar-legend" aria-label="Calendar status legend">
                <span class="calendar-legend-title"><i class="bi bi-info-circle" aria-hidden="true"></i> Legend:</span>
                <span class="calendar-status-badge status-open">Open</span>
                <span class="calendar-status-badge status-closed">Closed</span>
                <span class="calendar-status-badge status-holiday">Holiday</span>
                <span class="calendar-status-badge status-exceptional_closure">Closure</span>
                <span class="calendar-status-badge status-unrecorded">Unrecorded</span>
                <span class="calendar-status-badge status-sale" title="Completed product sale automatically established open">Sale Provenance</span>
            </div>
            <div class="calendar-scroll" tabindex="0" role="region" aria-label="Store calendar for <?= htmlspecialchars($monthTitle) ?>">
            <div class="calendar-grid">
                <?php
                $weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                foreach ($weekDays as $wDay): ?>
                    <div class="calendar-header-day" aria-hidden="true"><?= $wDay ?></div>
                <?php endforeach; ?>

                <?php
                // Empty padding cells for start of month
                for ($pad = 0; $pad < $startDayOfWeek; $pad++): ?>
                    <div class="calendar-day-cell is-empty" aria-hidden="true"></div>
                <?php endfor; ?>

                <?php
                $statusLabels = [
                    'open' => 'Open',
                    'closed' => 'Closed',
                    'holiday' => 'Holiday',
                    'exceptional_closure' => 'Closure',
                    'unrecorded' => 'Unrecorded'
                ];
                $statusFullNames = [
                    'open' => 'Open',
                    'closed' => 'Closed',
                    'holiday' => 'Holiday',
                    'exceptional_closure' => 'Exceptional closure',
                    'unrecorded' => 'Unrecorded'
                ];
                $activeSelectedDate = $_POST['calendar_date'] ?? $today;

                // Month day cells
                for ($day = 1; $day <= $daysInMonth; $day++):
                    $dateStr = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day);
                    $ev = $monthEvidence[$dateStr] ?? null;
                    $rawStatus = $ev['status'] ?? ($monthStatuses[$dateStr] ?? null);
                    $statusKey = $rawStatus ? StoreCalendar::normalizeStatus($rawStatus) : 'unrecorded';
                    $source = $ev['source'] ?? 'unrecorded';
                    $hasSaleOverride = !empty($ev['has_sale_override']);
                    $isException = !empty($ev['is_exception']);

                    $isToday = ($dateStr === $today);
                    $isSelected = ($dateStr === $activeSelectedDate);
                    $label = $statusLabels[$statusKey] ?? 'Unrecorded';
                    $fullLabel = $statusFullNames[$statusKey] ?? 'Unrecorded';

                    $badgeClass = 'status-' . htmlspecialchars($statusKey);
                    if ($source === 'sale') {
                        $label = 'Open (Sale)';
                        $fullLabel .= ' (Completed Sales Provenance)';
                        $badgeClass .= ' status-sale';
                    } elseif ($isException) {
                        $label .= ' (Ex)';
                    }
                ?>
                    <button type="button" class="calendar-day-cell <?= $isToday ? 'is-today' : '' ?> <?= $isSelected ? 'selected-day' : '' ?>"
                         data-date="<?= htmlspecialchars($dateStr) ?>"
                         data-status="<?= htmlspecialchars($statusKey) ?>"
                         aria-haspopup="dialog" aria-controls="calendar-editor"
                         aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"
                         aria-label="<?= htmlspecialchars($dateStr) ?>: <?= htmlspecialchars($fullLabel) ?>">
                        <div class="day-cell-top">
                            <span class="day-number"><?= $day ?></span>
                            <?php if ($isToday): ?>
                                <span class="day-today-tag">Today</span>
                            <?php endif; ?>
                        </div>
                        <span class="day-status">
                            <span class="calendar-status-badge <?= $badgeClass ?>" title="<?= htmlspecialchars($fullLabel) ?>"><?= htmlspecialchars($label) ?></span>
                        </span>
                        <?php if (!empty($shiftsByDate[$dateStr])): ?>
                            <span class="calendar-status-badge status-unrecorded" style="margin-top: 0.2rem; font-size: 0.68rem;" title="<?= count($shiftsByDate[$dateStr]) ?> shift(s) scheduled">
                                <i class="bi bi-person-fill" aria-hidden="true"></i> <?= count($shiftsByDate[$dateStr]) ?> shift<?= count($shiftsByDate[$dateStr]) === 1 ? '' : 's' ?>
                            </span>
                        <?php endif; ?>
                    </button>
                <?php endfor; ?>
            </div>
            </div>
            <p class="calendar-context-note muted">
                <i class="bi bi-info-circle" aria-hidden="true"></i> Calendar dates and weekly operating schedules provide trading availability context for store operations and <a href="<?= htmlspecialchars(theme_url('components/report/predictions.php')) ?>">Demand Forecast</a>.
            </p>
        </section>

    </main>
</div>

<!-- Modal 1: Date Exception Editor -->
<div class="rm-modal-overlay" id="calendar-editor" aria-hidden="true">
        <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="calendar-editor-title">
            <header class="rm-modal-header">
                <h2 id="calendar-editor-title">Record Date Status</h2>
                <button type="button" class="rm-close" data-close-modal aria-label="Close date editor"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </header>
            <form method="post" id="calendar-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_date">
                <div class="rm-modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="calendar_date">Start date</label>
                        <input id="calendar_date" type="date" name="calendar_date" autocomplete="off" required value="<?= htmlspecialchars($_POST['calendar_date'] ?? $today) ?>">
                    </div>
                    <div class="form-group">
                        <label for="through_date">End date <span class="muted">(optional)</span></label>
                        <input id="through_date" type="date" name="through_date" autocomplete="off" aria-describedby="range-preview-hint" value="<?= htmlspecialchars($_POST['through_date'] ?? '') ?>">
                        <small class="form-hint" id="range-preview-hint" role="status" aria-live="polite">Leave blank for single date.</small>
                    </div>
                    <div class="form-group">
                        <label for="status">Trading status</label>
                        <select id="status" name="status" required>
                            <?php foreach (['open' => 'Open', 'closed' => 'Closed', 'holiday' => 'Holiday', 'exceptional_closure' => 'Exceptional Closure'] as $value => $text): ?>
                                <option value="<?= $value ?>" <?= ($_POST['status'] ?? 'open') === $value ? 'selected' : '' ?>><?= $text ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                </div>
                <footer class="rm-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
                    <button class="btn btn-primary" type="submit" id="save-status-btn">Save Status</button>
                </footer>
            </form>
        </section>
</div>

<!-- Modal 2: Weekly Schedule Editor -->
<div class="rm-modal-overlay" id="weekly-schedule-modal" aria-hidden="true">
        <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="weekly-schedule-title">
            <header class="rm-modal-header">
                <h2 id="weekly-schedule-title">Weekly Store Schedule</h2>
                <button type="button" class="rm-close" data-close-modal aria-label="Close weekly schedule"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </header>
            <form method="post" id="weekly-schedule-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_weekly_schedule">
                <div class="rm-modal-body">
                    <p class="muted" style="margin-bottom: 1rem; font-size: 0.85rem;">
                        Configure regular open/closed weekdays. Date exceptions and completed product sales automatically override this schedule without rewriting past history.
                    </p>
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label for="schedule_effective_date">Effective Date</label>
                        <input id="schedule_effective_date" type="date" name="effective_date" autocomplete="off" required value="<?= htmlspecialchars($schedEffective) ?>">
                        <small class="form-hint">Date from which this weekly schedule takes effect.</small>
                    </div>
                    <fieldset class="form-group" style="border: none; padding: 0; margin: 0;">
                        <legend style="font-weight: 600; font-size: 0.9rem; margin-bottom: 0.5rem;">Regular Trading Days</legend>
                        <div class="schedule-grid">
                            <label class="schedule-checkbox-card" for="monday_open">
                                <input type="checkbox" id="monday_open" name="monday_open" value="1" <?= $schedMon ? 'checked' : '' ?>> Monday
                            </label>
                            <label class="schedule-checkbox-card" for="tuesday_open">
                                <input type="checkbox" id="tuesday_open" name="tuesday_open" value="1" <?= $schedTue ? 'checked' : '' ?>> Tuesday
                            </label>
                            <label class="schedule-checkbox-card" for="wednesday_open">
                                <input type="checkbox" id="wednesday_open" name="wednesday_open" value="1" <?= $schedWed ? 'checked' : '' ?>> Wednesday
                            </label>
                            <label class="schedule-checkbox-card" for="thursday_open">
                                <input type="checkbox" id="thursday_open" name="thursday_open" value="1" <?= $schedThu ? 'checked' : '' ?>> Thursday
                            </label>
                            <label class="schedule-checkbox-card" for="friday_open">
                                <input type="checkbox" id="friday_open" name="friday_open" value="1" <?= $schedFri ? 'checked' : '' ?>> Friday
                            </label>
                            <label class="schedule-checkbox-card" for="saturday_open">
                                <input type="checkbox" id="saturday_open" name="saturday_open" value="1" <?= $schedSat ? 'checked' : '' ?>> Saturday
                            </label>
                            <label class="schedule-checkbox-card" for="sunday_open">
                                <input type="checkbox" id="sunday_open" name="sunday_open" value="1" <?= $schedSun ? 'checked' : '' ?>> Sunday
                            </label>
                        </div>
                    </fieldset>
                </div>
                <footer class="rm-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
                    <button class="btn btn-primary" type="submit" id="save-weekly-btn">Save Schedule</button>
                </footer>
            </form>
        </section>
</div>

<!-- Modal 3: Staff Shifts & Availability -->
<div class="rm-modal-overlay" id="staff-shifts-modal" aria-hidden="true">
        <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="staff-shifts-title" style="max-width: 650px;">
            <header class="rm-modal-header">
                <h2 id="staff-shifts-title">Staff Shifts &amp; Availability</h2>
                <button type="button" class="rm-close" data-close-modal aria-label="Close staff shifts modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </header>
            <div class="rm-modal-body">
                <p class="muted" style="margin-bottom: 1rem; font-size: 0.85rem;">
                    Schedule staff shifts and verify staff availability against store trading days and operating hours (08:00 – 18:00).
                </p>
                <form method="post" id="staff-shift-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="schedule_staff_shift">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="shift_staff_user">Staff Member</label>
                            <select id="shift_staff_user" name="user_id" required>
                                <option value="">Select staff member...</option>
                                <?php foreach ($allStaffMembers as $staff): ?>
                                    <option value="<?= (int)$staff['user_id'] ?>"><?= htmlspecialchars($staff['full_name']) ?> (<?= htmlspecialchars($staff['role_name'] ?? 'Staff') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shift_date_input">Shift Date</label>
                            <input id="shift_date_input" type="date" name="shift_date" required value="<?= htmlspecialchars($today) ?>">
                        </div>
                        <div class="form-group">
                            <label for="shift_start_time">Start Time</label>
                            <input id="shift_start_time" type="time" name="start_time" required value="08:00">
                        </div>
                        <div class="form-group">
                            <label for="shift_end_time">End Time</label>
                            <input id="shift_end_time" type="time" name="end_time" required value="17:00">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="shift_notes">Shift Notes / Register Assignment</label>
                            <input id="shift_notes" type="text" name="notes" maxlength="255" placeholder="e.g. Front Till / Morning Shift">
                        </div>
                    </div>
                    <div style="margin-top: 1rem; display: flex; justify-content: flex-end;">
                        <button class="btn btn-primary" type="submit" id="schedule-shift-submit-btn"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Schedule Shift</button>
                    </div>
                </form>

                <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border);">

                <div class="section-title-row" style="margin-bottom: 0.75rem;">
                    <h3 style="font-size: 1rem; margin: 0;"><i class="bi bi-clock-history" aria-hidden="true"></i> Scheduled Shifts (<?= htmlspecialchars($monthTitle) ?>)</h3>
                </div>
                <?php if (empty($monthStaffShifts)): ?>
                    <p class="section-description" style="margin: 0.5rem 0;">No staff shifts scheduled for this month.</p>
                <?php else: ?>
                    <div class="data-table-shell" style="max-height: 240px; overflow-y: auto;">
                        <table class="data-table" style="font-size: 0.85rem;">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Staff</th>
                                    <th scope="col">Hours</th>
                                    <th scope="col">Notes</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthStaffShifts as $s): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($s['shift_date']) ?></td>
                                        <td><strong><?= htmlspecialchars($s['full_name']) ?></strong></td>
                                        <td><?= htmlspecialchars(substr($s['start_time'], 0, 5)) ?> – <?= htmlspecialchars(substr($s['end_time'], 0, 5)) ?></td>
                                        <td><?= htmlspecialchars($s['notes'] ?? '-') ?></td>
                                        <td>
                                            <form method="post" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_staff_shift">
                                                <input type="hidden" name="shift_id" value="<?= (int)$s['shift_id'] ?>">
                                                <button class="btn btn-quiet" type="submit" aria-label="Remove shift for <?= htmlspecialchars($s['full_name']) ?> on <?= htmlspecialchars($s['shift_date']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="rm-modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal>Close</button>
            </footer>
        </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('calendar_date');
    const throughDateInput = document.getElementById('through_date');
    const statusSelect = document.getElementById('status');
    const hint = document.getElementById('range-preview-hint');
    const dayCells = document.querySelectorAll('.calendar-day-cell:not(.is-empty)');
    const cellArray = Array.from(dayCells);
    const editor = document.getElementById('calendar-editor');
    const weeklyBtn = document.getElementById('weekly-schedule-btn');
    const weeklyModal = document.getElementById('weekly-schedule-modal');
    const staffShiftsBtn = document.getElementById('staff-shifts-btn');
    const staffShiftsModal = document.getElementById('staff-shifts-modal');

    if (weeklyBtn && weeklyModal) {
        weeklyBtn.addEventListener('click', function() {
            RetailMindUI.openOverlay(weeklyModal);
        });
    }

    if (staffShiftsBtn && staffShiftsModal) {
        staffShiftsBtn.addEventListener('click', function() {
            RetailMindUI.openOverlay(staffShiftsModal);
        });
    }

    function updateRangeHint() {
        const start = dateInput.value;
        const end = throughDateInput.value;
        const statusText = statusSelect.options[statusSelect.selectedIndex]?.text || '';

        if (!start) {
            hint.textContent = "Select a start date.";
            hint.style.color = "var(--muted)";
            return;
        }

        if (!end || end === start) {
            hint.textContent = "Single date: " + start + " (" + statusText + ")";
            hint.style.color = "var(--muted)";
            return;
        }

        const d1 = new Date(start);
        const d2 = new Date(end);

        if (d2 < d1) {
            hint.textContent = "End date cannot be before start date.";
            hint.style.color = "var(--theme-danger-text, #991b1b)";
            return;
        }

        const diffTime = Math.abs(d2 - d1);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

        if (diffDays > 366) {
            hint.textContent = "Range exceeds 366-day limit (" + diffDays + " days selected).";
            hint.style.color = "var(--theme-danger-text, #991b1b)";
            return;
        }

        hint.textContent = diffDays + " inclusive days: " + start + " to " + end + " (" + statusText + ")";
        hint.style.color = "var(--theme-success-text, #166534)";
    }

    function selectDateCell(cell) {
        dayCells.forEach(c => { c.classList.remove('selected-day'); c.setAttribute('aria-pressed', 'false'); });
        cell.classList.add('selected-day');
        cell.setAttribute('aria-pressed', 'true');

        const cellDate = cell.getAttribute('data-date');
        const cellStatus = cell.getAttribute('data-status');

        if (cellDate) {
            dateInput.value = cellDate;
            const shiftDateInput = document.getElementById('shift_date_input');
            if (shiftDateInput) {
                shiftDateInput.value = cellDate;
            }
        }
        throughDateInput.value = '';

        if (cellStatus && cellStatus !== 'unrecorded') {
            statusSelect.value = cellStatus;
        } else {
            statusSelect.value = 'open';
        }
        updateRangeHint();
        RetailMindUI.openOverlay(editor);
    }

    cellArray.forEach((cell, index) => {
        cell.addEventListener('click', function() {
            selectDateCell(cell);
        });

        cell.addEventListener('keydown', function(e) {
            let targetIndex = null;
            if (e.key === 'ArrowRight') targetIndex = index + 1;
            else if (e.key === 'ArrowLeft') targetIndex = index - 1;
            else if (e.key === 'ArrowDown') targetIndex = index + 7;
            else if (e.key === 'ArrowUp') targetIndex = index - 7;
            else if (e.key === 'Home') targetIndex = 0;
            else if (e.key === 'End') targetIndex = cellArray.length - 1;

            if (targetIndex !== null && targetIndex >= 0 && targetIndex < cellArray.length) {
                e.preventDefault();
                cellArray[targetIndex].focus();
            }
        });
    });

    dateInput.addEventListener('change', updateRangeHint);
    throughDateInput.addEventListener('change', updateRangeHint);
    statusSelect.addEventListener('change', updateRangeHint);
    updateRangeHint();
    <?php if ($messageClass === 'tag-warning'): ?>
    RetailMindUI.openOverlay(editor);
    <?php endif; ?>
});
</script>
</body>
</html>
