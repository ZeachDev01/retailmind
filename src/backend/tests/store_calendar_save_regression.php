<?php

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Store\StoreCalendar;

if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Calendar save regression skipped (set RUN_DB_TESTS=1).\n";
    exit(0);
}

$pdo = Database::connection();
$calendar = new StoreCalendar();
$failed = false;
$pdo->beginTransaction();
try {
    // Keep every fixture change inside the transaction, preserving Store history.
    $pdo->exec("DELETE FROM store_calendar WHERE calendar_date BETWEEN '2099-12-29' AND '2099-12-31'");
    $calendar->recordCalendarDate($pdo, '2099-12-29', 'open', null);
    $calendar->recordCalendarDate($pdo, '2099-12-29', 'exceptional_closure', null);
    $changed = $calendar->recordCalendarRange($pdo, '2099-12-30', '2099-12-31', 'exceptional_closure');
    if (count($changed) !== 2) {
        throw new RuntimeException('Range must save both dates.');
    }
    $statuses = $calendar->getCalendarDateRangeAtCutoff($pdo, '2099-12-29', '2099-12-31', date('Y-m-d H:i:s', time() + 5));
    ksort($statuses);
    if ($statuses !== array_fill_keys(['2099-12-29', '2099-12-30', '2099-12-31'], 'exceptional_closure')) {
        throw new RuntimeException('Single and range saves must return normalized closure statuses.');
    }
    $before = (int)$pdo->query("SELECT COUNT(*) FROM store_calendar WHERE calendar_date BETWEEN '2099-12-29' AND '2099-12-31'")->fetchColumn();
    $calendar->recordCalendarDate($pdo, '2099-12-29', 'exceptional-closure', null);
    if ($calendar->recordCalendarRange($pdo, '2099-12-30', '2099-12-31', 'exceptional_closure') !== []) {
        throw new RuntimeException('Unchanged range must not add history.');
    }
    $after = (int)$pdo->query("SELECT COUNT(*) FROM store_calendar WHERE calendar_date BETWEEN '2099-12-29' AND '2099-12-31'")->fetchColumn();
    if ($before !== $after) {
        throw new RuntimeException('Unchanged single date must not add history.');
    }
    echo "Calendar save regression passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    $failed = true;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failed ? 1 : 0);
