<?php
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
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
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .calendar-header-day {
            text-align: center;
            font-weight: 600;
            padding: 0.5rem 0.25rem;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted, #64748b);
        }
        .calendar-day-cell {
            min-height: 85px;
            padding: 0.5rem;
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 6px;
            background: var(--card-bg, #ffffff);
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
            background: var(--bg-hover, #f8fafc);
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
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.45rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            line-height: 1.2;
            word-break: break-word;
        }
        .status-open { background: #dcfce7; color: #15803d; }
        .status-closed { background: #f1f5f9; color: #475569; }
        .status-holiday { background: #fef3c7; color: #b45309; }
        .status-exceptional_closure { background: #fee2e2; color: #b91c1c; }
        .status-unrecorded { background: #e2e8f0; color: #64748b; }
        .editor-container {
            max-width: 650px;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <p class="page-kicker">Administrator workspace</p>
                <h1>Store Calendar</h1>
                <p class="page-subtitle">Monthly trading status, holidays, and exceptional closures.</p>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div>
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

            <div class="calendar-grid" role="grid" aria-label="Store calendar for <?= htmlspecialchars($monthTitle) ?>">
                <?php
                $weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                foreach ($weekDays as $wDay): ?>
                    <div class="calendar-header-day" role="columnheader"><?= $wDay ?></div>
                <?php endforeach; ?>

                <?php
                // Empty padding cells for start of month
                for ($pad = 0; $pad < $startDayOfWeek; $pad++): ?>
                    <div class="calendar-day-cell is-empty" aria-hidden="true"></div>
                <?php endfor; ?>

                <?php
                // Month day cells
                for ($day = 1; $day <= $daysInMonth; $day++):
                    $dateStr = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day);
                    $rawStatus = $monthStatuses[$dateStr] ?? null;
                    $statusKey = $rawStatus ? StoreCalendar::normalizeStatus($rawStatus) : 'unrecorded';
                    $isToday = ($dateStr === $today);

                    $statusLabels = [
                        'open' => 'Open',
                        'closed' => 'Closed',
                        'holiday' => 'Holiday',
                        'exceptional_closure' => 'Exceptional closure',
                        'unrecorded' => 'Not recorded'
                    ];
                    $label = $statusLabels[$statusKey] ?? 'Not recorded';
                ?>
                    <div class="calendar-day-cell <?= $isToday ? 'is-today' : '' ?>" 
                         data-date="<?= htmlspecialchars($dateStr) ?>" 
                         data-status="<?= htmlspecialchars($statusKey) ?>" 
                         tabindex="0" 
                         role="gridcell" 
                         aria-label="<?= htmlspecialchars($dateStr) ?>: <?= htmlspecialchars($label) ?>">
                        <span class="day-number"><?= $day ?></span>
                        <span class="day-status">
                            <span class="status-badge status-<?= htmlspecialchars($statusKey) ?>"><?= htmlspecialchars($label) ?></span>
                        </span>
                    </div>
                <?php endfor; ?>
            </div>
        </section>

        <section class="dashboard-section editor-container">
            <h2>Record Date Status</h2>
            <form method="post" id="calendar-form">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="calendar_date">Selected Date (Start)</label>
                        <input id="calendar_date" type="date" name="calendar_date" required value="<?= htmlspecialchars($_POST['calendar_date'] ?? $today) ?>">
                    </div>
                    <div class="form-group">
                        <label for="through_date">Through Date <span style="font-weight:normal; color:var(--text-muted, #64748b);">(Optional Range)</span></label>
                        <input id="through_date" type="date" name="through_date" value="<?= htmlspecialchars($_POST['through_date'] ?? '') ?>">
                        <small class="form-hint" id="range-preview-hint" style="display:block; margin-top:0.25rem; font-size:0.8rem; color:var(--text-muted, #64748b);">Leave empty to update only the selected date.</small>
                    </div>
                    <div class="form-group">
                        <label for="status">Trading Status</label>
                        <select id="status" name="status" required>
                            <option value="open">Open</option>
                            <option value="closed">Closed</option>
                            <option value="holiday">Holiday</option>
                            <option value="exceptional_closure">Exceptional closure</option>
                        </select>
                    </div>
                </div>
                <button class="btn" type="submit" id="save-status-btn">Save Status</button>
            </form>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('calendar_date');
    const throughDateInput = document.getElementById('through_date');
    const statusSelect = document.getElementById('status');
    const hint = document.getElementById('range-preview-hint');
    const dayCells = document.querySelectorAll('.calendar-day-cell:not(.is-empty)');

    function updateRangeHint() {
        const start = dateInput.value;
        const end = throughDateInput.value;
        const statusText = statusSelect.options[statusSelect.selectedIndex]?.text || '';

        if (!end || end === start) {
            hint.textContent = "Updating single date: " + start + " to " + statusText + ".";
            hint.style.color = "var(--text-muted, #64748b)";
            return;
        }

        const d1 = new Date(start);
        const d2 = new Date(end);

        if (d2 < d1) {
            hint.textContent = "Warning: Through date cannot be before start date.";
            hint.style.color = "#dc2626";
            return;
        }

        const diffTime = Math.abs(d2 - d1);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

        if (diffDays > 366) {
            hint.textContent = "Warning: Range exceeds maximum limit of 366 days (" + diffDays + " days requested).";
            hint.style.color = "#dc2626";
            return;
        }

        hint.textContent = "Applying '" + statusText + "' across " + diffDays + " inclusive days (from " + start + " through " + end + ").";
        hint.style.color = "#15803d";
    }

    function selectDateCell(cell) {
        dayCells.forEach(c => c.classList.remove('selected-day'));
        cell.classList.add('selected-day');

        const cellDate = cell.getAttribute('data-date');
        const cellStatus = cell.getAttribute('data-status');

        if (cellDate) {
            dateInput.value = cellDate;
        }

        if (cellStatus && cellStatus !== 'unrecorded') {
            statusSelect.value = cellStatus;
        } else {
            statusSelect.value = 'open';
        }
        updateRangeHint();
    }

    dayCells.forEach(cell => {
        cell.addEventListener('click', function() {
            selectDateCell(cell);
        });

        cell.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                selectDateCell(cell);
            }
        });

        if (cell.getAttribute('data-date') === dateInput.value) {
            cell.classList.add('selected-day');
        }
    });

    dateInput.addEventListener('change', updateRangeHint);
    throughDateInput.addEventListener('change', updateRangeHint);
    statusSelect.addEventListener('change', updateRangeHint);
    updateRangeHint();
});
</script>
</body>
</html>
