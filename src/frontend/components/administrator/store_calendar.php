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

$monthStatuses = $calendar->getCalendarDateRangeAtCutoff($pdo, $firstOfMonth, $lastOfMonth, date('Y-m-d H:i:s'));
$monthTitle = date('F Y', strtotime($firstOfMonth));
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
            gap: 0.75rem;
        }
        .calendar-nav {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            min-width: 640px;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .calendar-header-day {
            text-align: center;
            font-weight: 700;
            padding: 0.5rem 0.25rem;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--muted);
        }
        .calendar-day-cell {
            min-width: 44px;
            font: inherit;
            color: var(--text);
            text-align: left;
            min-height: 85px;
            padding: 0.35rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--theme-surface, #ffffff);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s, transform 0.1s;
        }
        .calendar-day-cell:hover, .calendar-day-cell:focus {
            border-color: var(--primary-color, #2563eb);
            outline: none;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
        }
        .calendar-day-cell.selected-day {
            border-color: var(--primary-color, #2563eb);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.35);
            background: var(--theme-surface-soft, #eff6ff);
        }
        .calendar-day-cell.is-today {
            font-weight: 700;
        }
        .calendar-day-cell.is-empty {
            background: transparent;
            border: none;
            cursor: default;
        }
        .day-number {
            font-size: 0.95rem;
            font-weight: 600;
        }
        .day-status {
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        .calendar-status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.35rem;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 500;
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
        .status-unrecorded::before { background: transparent; border: 1px solid currentColor; }
        .calendar-legend { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
        .calendar-scroll { overflow-x: auto; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <h1>Store Calendar</h1>
            <button type="button" class="btn btn-primary" id="record-date-btn"><i class="bi bi-calendar-event" aria-hidden="true"></i> Record Status</button>
        </header>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="dashboard-section">
            <div class="calendar-controls">
                <div class="calendar-nav">
                    <a class="btn btn-secondary" href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" aria-label="Previous month">&laquo; Previous</a>
                    <a class="btn btn-secondary" href="?year=<?= substr($today, 0, 4) ?>&month=<?= (int)substr($today, 5, 2) ?>">Today</a>
                    <a class="btn btn-secondary" href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" aria-label="Next month">Next &raquo;</a>
                </div>
                <h2><?= htmlspecialchars($monthTitle) ?></h2>
            </div>

            <div class="calendar-legend" aria-label="Calendar status legend">
                <span class="calendar-status-badge status-open">Open</span>
                <span class="calendar-status-badge status-closed">Closed</span>
                <span class="calendar-status-badge status-holiday">Holiday</span>
                <span class="calendar-status-badge status-exceptional_closure">Closure</span>
                <span class="calendar-status-badge status-unrecorded">Unrecorded</span>
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
                    $rawStatus = $monthStatuses[$dateStr] ?? null;
                    $statusKey = $rawStatus ? StoreCalendar::normalizeStatus($rawStatus) : 'unrecorded';
                    $isToday = ($dateStr === $today);
                    $isSelected = ($dateStr === $activeSelectedDate);
                    $label = $statusLabels[$statusKey] ?? 'Unrecorded';
                    $fullLabel = $statusFullNames[$statusKey] ?? 'Unrecorded';
                ?>
                    <button type="button" class="calendar-day-cell <?= $isToday ? 'is-today' : '' ?> <?= $isSelected ? 'selected-day' : '' ?>"
                         data-date="<?= htmlspecialchars($dateStr) ?>"
                         data-status="<?= htmlspecialchars($statusKey) ?>"
                         aria-haspopup="dialog" aria-controls="calendar-editor"
                         aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"
                         aria-label="<?= htmlspecialchars($dateStr) ?>: <?= htmlspecialchars($fullLabel) ?>">
                        <span class="day-number"><?= $day ?></span>
                        <span class="day-status">
                            <span class="calendar-status-badge status-<?= htmlspecialchars($statusKey) ?>" title="<?= htmlspecialchars($fullLabel) ?>"><?= htmlspecialchars($label) ?></span>
                        </span>
                    </button>
                <?php endfor; ?>
            </div>
            </div>
        </section>

    </main>
</div>

<div class="rm-modal-overlay" id="calendar-editor" aria-hidden="true">
        <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="calendar-editor-title">
            <header class="rm-modal-header">
                <h2 id="calendar-editor-title">Record Date Status</h2>
                <button type="button" class="rm-close" data-close-modal aria-label="Close date editor"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </header>
            <form method="post" id="calendar-form">
                <?= csrf_field() ?>
                <div class="rm-modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="calendar_date">Start date</label>
                        <input id="calendar_date" type="date" name="calendar_date" required value="<?= htmlspecialchars($_POST['calendar_date'] ?? $today) ?>">
                    </div>
                    <div class="form-group">
                        <label for="through_date">End date <span class="muted">(optional)</span></label>
                        <input id="through_date" type="date" name="through_date" aria-describedby="range-preview-hint" value="<?= htmlspecialchars($_POST['through_date'] ?? '') ?>">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('calendar_date');
    const throughDateInput = document.getElementById('through_date');
    const statusSelect = document.getElementById('status');
    const hint = document.getElementById('range-preview-hint');
    const dayCells = document.querySelectorAll('.calendar-day-cell:not(.is-empty)');
    const cellArray = Array.from(dayCells);
    const editor = document.getElementById('calendar-editor');

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
            hint.textContent = "End date must be on or after start date.";
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

        hint.textContent = diffDays + " days: " + start + " to " + end + " (" + statusText + ")";
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

    document.getElementById('record-date-btn').addEventListener('click', function() {
        RetailMindUI.openOverlay(editor);
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
