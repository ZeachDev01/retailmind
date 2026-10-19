<?php
if (PHP_SAPI !== 'cli' || getenv('RUN_DB_TESTS') !== '1') { exit("GCash integration: skipped (RUN_DB_TESTS=1 required)\n"); }
require_once __DIR__ . '/../bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
require_once __DIR__ . '/../includes/functions.php';

use App\Services\GCashTransactionService;

$database = $argv[1] ?? '';
if (!preg_match('/\Aretailmind_theme_test_[a-f0-9]{12}\z/', $database)) { throw new RuntimeException('Disposable database required.'); }
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$refuse = static function (callable $work) use ($check): void {
    try { $work(); } catch (DomainException $e) { return; }
    $check(false, 'Expected request to be refused.');
};
$service = new GCashTransactionService($pdo, ['min_length' => 13, 'max_length' => 13]);
if (in_array($argv[2] ?? '', ['--save-race', '--review-race', '--open-race'], true)) {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            if ($argv[2] === '--save-race') { $service->save(4, '0000000000004', '1', 'Payment'); }
            elseif ($argv[2] === '--open-race') { $service->openSession((int)$argv[3], 1000.0, 5000.0); }
            else { $service->review(4, (int)$argv[3], 'Verified', true, 'Matched authorized records during concurrent review'); }
            echo 'accepted';
            break;
        } catch (DomainException $e) {
            if ($e->getCode() !== 409) { throw $e; }
            echo 'duplicate';
            break;
        } catch (PDOException $e) {
            // The existing backup gate refuses simultaneous writes immediately; retry that refusal only in this test.
            if ((int)($e->errorInfo[1] ?? 0) !== 1205 || $attempt === 4) { throw $e; }
            usleep(50000);
        }
    }
    exit;
}
$snapshot = static fn() => $pdo->query('SELECT (SELECT COUNT(*) FROM sales), (SELECT COALESCE(SUM(total_amount),0) FROM sales), (SELECT COUNT(*) FROM cash_drawer_movements), (SELECT SUM(opening_cash) FROM cashier_shifts)')->fetch(PDO::FETCH_NUM);
$before = $snapshot();
$id = $service->save(4, '0000 0000 00001', '0.01', 'Payment');
$row = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
$check($row['reference_number'] === '0000000000001' && $row['verification_status'] === 'Pending' && $row['session_id'] === null, 'Save must preserve zeroes, stay Pending, and keep null session_id for legacy.');
$check($service->exists('0000-0000-00001'), 'Normalized duplicate lookup.');
$refuse(fn() => $service->save(4, '0000000000001', '0.01', 'Payment'));
foreach (['0', '-1', '1.001', '1e3', '100000000', 'NaN'] as $amount) { $refuse(fn() => $service->save(4, '0000000000002', $amount, 'Payment')); }
$refuse(fn() => $service->save(4, 'reference 0000000000002', '1', 'Payment'));
$refuse(fn() => $service->save(4, '0000000000002', '1', 'Invalid'));
$refuse(fn() => $service->review(4, $id, 'Verified', false, 'Screenshot only'));
$refuse(fn() => $service->review(1, $id, 'Verified', true, 'Wrong cashier'));
$refuse(fn() => $service->review(4, $id, 'Verified', true, ''));
$check($service->history(1, 1)['transactions'] === [], 'History must be scoped to the cashier.');
$service->review(4, $id, 'Verified', true, 'Matched reference and amount in receiving account');
$refuse(fn() => $service->review(4, $id, 'Rejected', false, 'Cannot reverse finalized review'));
$id2 = $service->save(4, '0000000000002', '123.45', 'Cash-In');
$service->review(4, $id2, 'Rejected', false, 'Transaction not found in receiving account');
$refuse(fn() => $service->save(4, '0000000000002', '123.45', 'Cash-In'));
$pdo->exec("UPDATE cashier_shifts SET locked_at=NOW() WHERE cashier_id=4 AND status='open'");
try { $refuse(fn() => $service->save(4, '0000000000003', '10', 'Cash-Out')); }
finally { $pdo->exec("UPDATE cashier_shifts SET locked_at=NULL WHERE cashier_id=4 AND status='open'"); }
$check($before === $snapshot(), 'Saving and verification must not change sales or cash balances.');

// Ticket #238: Verify preview calculations and ensure preview creates no DB records
$prePreviewSnapshot = $pdo->query('SELECT (SELECT COUNT(*) FROM gcash_transactions), (SELECT COUNT(*) FROM activity_log)')->fetch(PDO::FETCH_NUM);
$bCheck = [1 => 5.0, 350 => 5.0, 499 => 5.0, 500 => 10.0, 1000 => 10.0, 1001 => 15.0, 10000 => 100.0, 10001 => 100.01, 12345 => 123.45];
foreach ($bCheck as $p => $f) {
    $preview = $service->calculatePreview('Cash-In', $p);
    $check($preview['fee'] === $f, "Preview fee mismatch at {$p}");
}
// Three PHP 1,000 examples
$ci1k = $service->calculatePreview('Cash-In', 1000);
$check($ci1k['counter_collect'] === 1010.0 && $ci1k['wallet_transfer'] === 1000.0 && $ci1k['net_service_cash'] === 1010.0 && $ci1k['net_wallet'] === -1000.0 && $ci1k['fee_location'] === 'cash', 'Cash-In 1000 mismatch');
$coGk = $service->calculatePreview('Cash-Out', 1000, 'Fee via GCash');
$check($coGk['customer_transfer'] === 1010.0 && $coGk['cash_payout'] === 1000.0 && $coGk['net_service_cash'] === -1000.0 && $coGk['net_wallet'] === 1010.0 && $coGk['fee_location'] === 'wallet', 'Cash-Out GCash 1000 mismatch');
$coCk = $service->calculatePreview('Cash-Out', 1000, 'Fee in cash');
$check($coCk['customer_transfer'] === 1000.0 && $coCk['counter_collect'] === 10.0 && $coCk['cash_payout'] === 1000.0 && $coCk['net_service_cash'] === -990.0 && $coCk['net_wallet'] === 1000.0 && $coCk['fee_location'] === 'cash', 'Cash-Out Cash 1000 mismatch');
// 12345
$co12k = $service->calculatePreview('Cash-Out', 12345, 'Fee in cash');
$check($co12k['fee'] === 123.45 && $co12k['counter_collect'] === 123.45 && $co12k['net_service_cash'] === -12221.55, 'Cash-Out 12345 mismatch');
// Invalid inputs refused
foreach ([0, -1, '1.5', '10.50', 'NaN', '50001'] as $bad) {
    $refuse(fn() => $service->calculatePreview('Cash-In', $bad));
}
$refuse(fn() => $service->calculatePreview('Payment', 1000));
$check($prePreviewSnapshot === $pdo->query('SELECT (SELECT COUNT(*) FROM gcash_transactions), (SELECT COUNT(*) FROM activity_log)')->fetch(PDO::FETCH_NUM), 'Previewing must create no database records.');
$audit = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE module='GCash' AND record_id IN (?,?)");
$audit->execute([$id, $id2]);
$check((int)$audit->fetchColumn() === 4, 'Each creation and review must be audited exactly once.');
$pdo->exec("CREATE TRIGGER gcash_audit_failure BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected audit failure'");
try {
    $threw = false;
    try { $service->save(4, '0000000000003', '1', 'Cash-Out'); } catch (PDOException $e) { $threw = true; }
    $check($threw && !$service->exists('0000000000003'), 'Audit failure must roll back creation.');
} finally { $pdo->exec('DROP TRIGGER gcash_audit_failure'); }
$id3 = $service->save(4, '0000000000003', '1', 'Cash-Out');
$pdo->exec("CREATE TRIGGER gcash_audit_failure BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected audit failure'");
try {
    $threw = false;
    try { $service->review(4, $id3, 'Verified', true, 'Checked authorized account'); } catch (PDOException $e) { $threw = true; }
    $check($threw && $pdo->query('SELECT verification_status FROM gcash_transactions WHERE transaction_id=' . $id3)->fetchColumn() === 'Pending', 'Audit failure must roll back verification.');
} finally { $pdo->exec('DROP TRIGGER gcash_audit_failure'); }
foreach ([['--save-race'], ['--review-race', (string)$id3]] as $arguments) {
    $processes = [];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, $database, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $check(is_resource($process), 'Concurrent worker must start.');
        $processes[] = [$process, $pipes];
    }
    $outcomes = [];
    foreach ($processes as [$process, $pipes]) {
        $outcomes[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $check(proc_close($process) === 0, $error);
    }
    sort($outcomes);
    $check($outcomes === ['accepted', 'duplicate'], 'Concurrent writes must accept exactly one request.');
}

// Ticket #239: Open, reconcile, and hand over GCash responsibility

// 1. Shift requirement & register lock checks
$refuse(fn() => $service->openSession(999, 1000.0, 5000.0));
$refuse(fn() => $service->openSession(4, -10.0, 5000.0));
$refuse(fn() => $service->openSession(4, 1000.0, -50.0));

// Register lock blocks opening
$pdo->exec("UPDATE cashier_shifts SET locked_at=NOW() WHERE cashier_id=4 AND status='open'");
try { $refuse(fn() => $service->openSession(4, 1000.0, 5000.0)); }
finally { $pdo->exec("UPDATE cashier_shifts SET locked_at=NULL WHERE cashier_id=4 AND status='open'"); }

// Setup a second cashier (user 5) with an open unlocked shift
$pdo->exec("INSERT INTO registers (register_id, name) VALUES (2, 'Register 2') ON DUPLICATE KEY UPDATE name=VALUES(name)");
$pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (5, 'Second Cashier', 'cashier2', 'hash', 4, 1) ON DUPLICATE KEY UPDATE status='active'");
$pdo->exec("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (5, 4, 1) ON DUPLICATE KEY UPDATE is_primary=1");
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status) VALUES (2, 5, 2, 200.00, 'open') ON DUPLICATE KEY UPDATE status='open', locked_at=NULL");

// 2. Open session for Cashier 4
$check($service->getCurrentSession() === null, 'No session open initially');
$sess1 = $service->openSession(4, 1000.00, 5000.00);
$current = $service->getCurrentSession();
$check($current !== null && (int)$current['session_id'] === $sess1 && (int)$current['cashier_id'] === 4, 'Session 1 open by cashier 4');
$expectedInit = $service->calculateSessionExpected($sess1);
$check($expectedInit['expected_service_cash'] === 1000.00 && $expectedInit['expected_wallet_balance'] === 5000.00, 'Opening expected balances match');

// 3. Second cashier cannot open session concurrently (409)
$secondOpenBlocked = false;
try {
    $service->openSession(5, 500.00, 2000.00);
} catch (DomainException $e) {
    if ($e->getCode() === 409 && str_contains($e->getMessage(), 'Only one Cashier may control GCash at a time')) {
        $secondOpenBlocked = true;
    }
}
$check($secondOpenBlocked, 'Concurrent session open must be rejected with 409');

// 4. Second cashier can still perform POS operations (product checkout)
$pdo->exec("INSERT INTO sales (cashier_id, shift_id, total_amount, payment_method) VALUES (5, 2, 50.00, 'cash')");
$saleId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO cash_drawer_movements (shift_id, movement_type, amount, reason, cashier_id, recorded_by) VALUES (2, 'pay_in', 50.00, 'Sale {$saleId}', 5, 5)");
$check((int)$pdo->query("SELECT COUNT(*) FROM sales WHERE cashier_id = 5")->fetchColumn() === 1, 'Cashier 2 can checkout products in POS');

// Second cashier cannot save GCash transactions under Cashier 4's responsibility
$secondSaveBlocked = false;
try {
    $service->save(5, '0000000000020', '100', 'Cash-In');
} catch (DomainException $e) {
    if ($e->getCode() === 409) { $secondSaveBlocked = true; }
}
$check($secondSaveBlocked, 'Non-controlling cashier cannot save GCash transactions (409)');

// 5. Controlling cashier saves transactions with session_id
$tx1 = $service->save(4, '0000000000021', '1000', 'Cash-In');
$rowTx1 = $pdo->query('SELECT session_id, verification_status FROM gcash_transactions WHERE transaction_id=' . $tx1)->fetch(PDO::FETCH_ASSOC);
$check((int)$rowTx1['session_id'] === $sess1 && $rowTx1['verification_status'] === 'Pending', 'Tx1 must link to session 1');

// Unverified transaction does not alter expected balances
$expPreVerify = $service->calculateSessionExpected($sess1);
$check($expPreVerify['expected_service_cash'] === 1000.00 && $expPreVerify['expected_wallet_balance'] === 5000.00, 'Pending tx does not alter expected balances');

// Verify Tx1: Cash-In 1000 -> net cash +1010, net wallet -1000
$service->review(4, $tx1, 'Verified', true, 'Checked actual account records');
$expPostVerify = $service->calculateSessionExpected($sess1);
$check($expPostVerify['expected_service_cash'] === 2010.00 && $expPostVerify['expected_wallet_balance'] === 4000.00, 'Verified Cash-In 1000 updates expected cash to 2010 and wallet to 4000');

// Save & verify Tx2: Cash-Out 500 (Fee via GCash) -> net cash -500, net wallet +510
$tx2 = $service->save(4, '0000000000022', '500', 'Cash-Out');
$service->review(4, $tx2, 'Verified', true, 'Checked actual account records');
$expPostTx2 = $service->calculateSessionExpected($sess1);
$check($expPostTx2['expected_service_cash'] === 1510.00 && $expPostTx2['expected_wallet_balance'] === 4510.00, 'Verified Cash-Out 500 updates expected cash to 1510 and wallet to 4510');

// 6. Close session with variances (separate cash and wallet shortage/excess)
$refuse(fn() => $service->closeSession(5, 1500.00, 4520.00, 'Unauthorized close'));

// Actual cash 1500.00 (-10.00 shortage), actual wallet 4520.00 (+10.00 excess), 1 carryover
$closeData = $service->closeSession(4, 1500.00, 4520.00, 'Counted at closing with variance', 1);
$check($closeData['status'] === 'closed', 'Session closed');
$check($closeData['cash_variance'] === -10.00, 'Cash shortage of -10.00 recorded');
$check($closeData['wallet_variance'] === 10.00, 'Wallet excess of +10.00 recorded');
$check($closeData['unresolved_carryover_count'] === 1, 'Unresolved carryover recorded');

$sessRow = $pdo->query('SELECT * FROM gcash_service_sessions WHERE session_id=' . $sess1)->fetch(PDO::FETCH_ASSOC);
$check($sessRow['status'] === 'closed' && $sessRow['closed_by'] == 4 && $sessRow['is_open'] === null, 'Session row sealed and is_open cleared');
$check($service->getCurrentSession() === null, 'No session open after closing');

// 7. Handover transfers responsibility and preserves continuity
$sess2 = $service->openSession(4, 1500.00, 4520.00);
$handover = $service->handoverSession(4, 5, 1500.00, 4520.00, 'Shift change handover');
$check($handover['outgoing_session_id'] === $sess2, 'Outgoing session closed');
$sess3 = $handover['incoming_session_id'];
$curr3 = $service->getCurrentSession();
$check($curr3 !== null && (int)$curr3['session_id'] === $sess3 && (int)$curr3['cashier_id'] === 5, 'Session 3 active under incoming cashier 5');
$check((float)$curr3['opening_service_cash'] === 1500.00 && (float)$curr3['opening_wallet_balance'] === 4520.00, 'Handover opening balances preserved');

// 8. Emergency handover by Administrator
$refuse(fn() => $service->emergencyHandover(4, 4, 1500.00, 4520.00, 'Cashier cannot do emergency handover'));
$emergency = $service->emergencyHandover(1, 4, 1500.00, 4520.00, 'Cashier 5 abruptly unavailable');
$sess4 = $emergency['incoming_session_id'];
$curr4 = $service->getCurrentSession();
$check($curr4 !== null && (int)$curr4['session_id'] === $sess4 && (int)$curr4['cashier_id'] === 4, 'Session 4 active under cashier 4 after emergency handover');
$s3Row = $pdo->query('SELECT * FROM gcash_service_sessions WHERE session_id=' . $sess3)->fetch(PDO::FETCH_ASSOC);
$check($s3Row['status'] === 'closed' && $s3Row['closed_by'] == 1 && str_contains($s3Row['closing_notes'], 'Emergency Handover:'), 'Predecessor closed by admin with emergency reason');
$service->closeSession(4, 1500.00, 4520.00, 'Close clean');

// 9. Audit failure rollbacks
$pdo->exec("CREATE TRIGGER gcash_session_audit_fail BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit injection fail'");
try {
    $auditThrew = false;
    try { $service->openSession(4, 1000.0, 5000.0); } catch (PDOException $e) { $auditThrew = true; }
    $check($auditThrew && $service->getCurrentSession() === null, 'Audit failure must roll back openSession');
} finally { $pdo->exec('DROP TRIGGER gcash_session_audit_fail'); }

// 10. Multi-process concurrent open-session race
$pdo->exec("UPDATE gcash_service_sessions SET status='closed', closed_at=NOW() WHERE status='open'");
$raceProcesses = [];
foreach ([4, 5] as $cashierNum) {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, $database, '--open-race', (string)$cashierNum], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $check(is_resource($proc), 'Concurrent open worker must start.');
    $raceProcesses[] = [$proc, $pipes];
}
$raceOutcomes = [];
foreach ($raceProcesses as [$proc, $pipes]) {
    $raceOutcomes[] = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $check(proc_close($proc) === 0, $err);
}
sort($raceOutcomes);
$check($raceOutcomes === ['accepted', 'duplicate'], 'Concurrent open must accept exactly one session');

$pdo->exec("UPDATE gcash_service_sessions SET status='closed', closed_at=NOW() WHERE status='open'");

echo "GCash MySQL integration: passed\n";

