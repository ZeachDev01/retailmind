<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

$pdo = require __DIR__ . '/../../../backend/includes/db_connect.php'; // Not exactly, auth.php provides $pdo ? Wait, auth.php requires config/db.php which exposes $pdo.

// Let's just use $GLOBALS['pdo'] or assume $pdo is available after auth.php.

use App\Store\StoreCalendar;
use App\Services\PhilippineTime;
use App\Support\OperatorAlert;

$calendar = new StoreCalendar();
$message = '';
$messageClass = '';

// Get branch ID
$stmt = $pdo->query("SELECT branch_id FROM branches LIMIT 1");
$branchId = (int)$stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    try {
        $date = $_POST['calendar_date'] ?? '';
        $status = $_POST['status'] ?? '';
        
        // Validate date
        try {
            $formattedDate = PhilippineTime::dayStart($date); // Returns 'Y-m-d 00:00:00'
            $date = substr($formattedDate, 0, 10);
        } catch (InvalidArgumentException) {
            throw new RuntimeException("Invalid calendar date.");
        }
        
        if (!in_array($status, ['open', 'closed', 'holiday', 'exceptional-closure'], true)) {
            throw new RuntimeException("Invalid status provided.");
        }
        
        $cutoff = date('Y-m-d H:i:s');
        $previousStatus = $calendar->getCalendarDateStatusAtCutoff($pdo, $branchId, $date, $cutoff);
        
        if ($previousStatus !== $status) {
            $calendar->recordCalendarDate($pdo, $branchId, $date, $status, (int)$_SESSION['user_id']);
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
        
        $message = "Store calendar recorded for $date.";
        $messageClass = 'tag-success';
    } catch (Throwable $exception) {
        $message = OperatorAlert::message($exception, 'The calendar date could not be recorded. Check the date and try again.');
        $messageClass = 'tag-warning';
    }
}

// Display logic (fetch last 30 days for example)
$today = substr(PhilippineTime::dayStart(date('Y-m-d')), 0, 10);
$startDate = date('Y-m-d', strtotime('-30 days', strtotime($today)));
$range = $calendar->getCalendarDateRangeAtCutoff($pdo, $branchId, $startDate, $today, date('Y-m-d H:i:s'));

?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Store Calendar</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div><p class="page-kicker">Administrator workspace</p><h1>Store Calendar</h1><p class="page-subtitle">Record open days, holidays, and exceptional closures.</p></div>
        </header>
        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        
        <section class="dashboard-section">
            <h2>Record Date Status</h2>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="calendar_date">Date</label>
                        <input id="calendar_date" type="date" name="calendar_date" required value="<?= htmlspecialchars($today) ?>">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <option value="open">Open</option>
                            <option value="closed">Closed</option>
                            <option value="holiday">Holiday</option>
                            <option value="exceptional-closure">Exceptional Closure</option>
                        </select>
                    </div>
                </div>
                <button class="btn" type="submit">Record Calendar</button>
            </form>
        </section>
        
        <section class="dashboard-section">
            <h2>Recent Calendar (Last 30 Days)</h2>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $curr = $today;
                        for ($i = 0; $i <= 30; $i++) {
                            $st = $range[$curr] ?? 'unrecorded';
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($curr) . '</td>';
                            echo '<td>' . htmlspecialchars(ucfirst($st)) . '</td>';
                            echo '</tr>';
                            $curr = date('Y-m-d', strtotime('-1 day', strtotime($curr)));
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
