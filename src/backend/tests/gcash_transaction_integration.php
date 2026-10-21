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
if (in_array($argv[2] ?? '', ['--save-race', '--review-race', '--open-race', '--cash-in-race', '--cash-out-race', '--funding-race'], true)) {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            if ($argv[2] === '--save-race') { $service->save(4, '0000000000004', '1', 'Payment'); }
            elseif ($argv[2] === '--open-race') { $service->openSession((int)$argv[3], 1000.0, 5000.0); }
            elseif ($argv[2] === '--cash-in-race') {
                $service->recordCashIn((int)$argv[3], [
                    'principal' => 100,
                    'reference' => '0000000000999',
                    'confirmed_wallet' => true,
                    'confirmed_cash' => true,
                ]);
            }
            elseif ($argv[2] === '--cash-out-race') {
                $service->recordCashOut((int)$argv[3], [
                    'principal' => 100,
                    'fee_method' => 'Fee via GCash',
                    'reference' => '0000000000888',
                    'confirmed_wallet' => true,
                    'confirmed_cash' => true,
                ]);
            }
            elseif ($argv[2] === '--funding-race') {
                $service->recordFundingMovement(1, 'admin', [
                    'movement_type' => 'replenishment',
                    'fund_type' => 'cash',
                    'direction' => 'in',
                    'amount' => 100.0,
                    'reason' => 'Concurrent funding worker replenishment',
                ]);
            }
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

// 11. Ticket #240: Accountable Cash-In movements
$sess5 = $service->openSession(4, 1000.00, 5000.00);

// Unlocked register check / wrong cashier
$refuse(fn() => (new GCashTransactionService($pdo, ['min_length' => 13, 'max_length' => 13]))->recordCashIn(5, [
    'principal' => 1000,
    'reference' => '0000000000101',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]));

// Stale preview totals rejected
$refuse(fn() => $service->recordCashIn(4, [
    'principal' => 1000,
    'reference' => '0000000000101',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
    'preview_fee' => 5.00,
]));

// Incomplete confirmations rejected
$refuse(fn() => $service->recordCashIn(4, [
    'principal' => 1000,
    'reference' => '0000000000101',
    'confirmed_wallet' => false,
    'confirmed_cash' => true,
]));

// Wallet balance sufficiency check (attempt Cash-In greater than available wallet)
$refuse(fn() => $service->recordCashIn(4, [
    'principal' => 6000,
    'reference' => '0000000000101',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]));

// Completed PHP 1,000 Cash-In: adds PHP 1,010 service cash, subtracts PHP 1,000 wallet, earns PHP 10 cash fee
$posSnapshotBefore = $snapshot();
$cashInTxId = $service->recordCashIn(4, [
    'principal' => 1000,
    'reference' => '0000000000101',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
    'preview_fee' => 10.00,
    'preview_counter_collect' => 1010.00,
    'preview_wallet_transfer' => 1000.00,
]);
$check($cashInTxId > 0, 'Cash-In recorded successfully');

$posSnapshotAfter = $snapshot();
$check($posSnapshotBefore === $posSnapshotAfter, 'Product sales, inventory, and POS cash drawer totals must remain unchanged');

$txRow = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id=' . $cashInTxId)->fetch(PDO::FETCH_ASSOC);
$check((float)$txRow['principal_amount'] === 1000.00, 'Principal preserved');
$check((float)$txRow['fee_amount'] === 10.00, 'Fee amount preserved');
$check($txRow['fee_method'] === 'cash', 'Fee method preserved');
$check((float)$txRow['service_cash_delta'] === 1010.00, 'Service cash delta +1010');
$check((float)$txRow['wallet_delta'] === -1000.00, 'Wallet delta -1000');
$check((float)$txRow['gross_fee'] === 10.00, 'Gross fee 10');
$check((int)$txRow['shift_id'] === 1, 'Shift ID recorded');
$check((int)$txRow['confirmed_wallet'] === 1 && (int)$txRow['confirmed_cash'] === 1, 'Confirmations recorded');
$check($txRow['verification_status'] === 'Completed', 'Status is Completed');
$check(!empty($txRow['completed_at']), 'completed_at timestamp recorded');

// Completed record cannot be reviewed or modified
$refuse(fn() => $service->review(4, $cashInTxId, 'Verified', true, 'Cannot re-review Completed'));

// Expected balances reflect completed Cash-In
$expAfterCompleted = $service->calculateSessionExpected($sess5);
$check($expAfterCompleted['expected_service_cash'] === 2010.00, 'Expected service cash is 2010.00');
$check($expAfterCompleted['expected_wallet_balance'] === 4000.00, 'Expected wallet balance is 4000.00');

// Duplicate reference rejection
$refuse(fn() => $service->recordCashIn(4, [
    'principal' => 500,
    'reference' => '0000000000101',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]));

// Needs checking test: partial movements (cash collected at counter, wallet not yet sent), no reference
$refuse(fn() => $service->recordCashIn(4, [
    'principal' => 500,
    'outcome' => 'Needs checking',
    'notes' => 'bad',
]));

$ncTxId = $service->recordCashIn(4, [
    'principal' => 500,
    'outcome' => 'Needs checking',
    'confirmed_cash' => true,
    'confirmed_wallet' => false,
    'notes' => 'Customer paid cash at counter, wallet transfer app connection timed out',
]);
$ncRow = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id=' . $ncTxId)->fetch(PDO::FETCH_ASSOC);
$check($ncRow['reference_number'] === null, 'Needs checking preserves NULL reference when none available');
$check($ncRow['verification_status'] === 'Needs checking', 'Status is Needs checking');
$check((float)$ncRow['service_cash_delta'] === 510.00, 'Service cash delta +510 recorded');
$check((float)$ncRow['wallet_delta'] === 0.00, 'Wallet delta 0 for unsent wallet transfer');
$check((float)$ncRow['gross_fee'] === 0.00, 'Gross fee 0 when transfer incomplete');

$expAfterNC = $service->calculateSessionExpected($sess5);
$check($expAfterNC['expected_service_cash'] === 2520.00, 'Expected service cash reflects partial cash collection (2520.00)');
$check($expAfterNC['expected_wallet_balance'] === 4000.00, 'Expected wallet balance unaffected by unsent wallet (4000.00)');

// Audit failure rollback for Cash-In
$pdo->exec("CREATE TRIGGER gcash_cash_in_audit_fail BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit injection fail'");
try {
    $auditFailThrew = false;
    try {
        $service->recordCashIn(4, [
            'principal' => 200,
            'reference' => '0000000000102',
            'confirmed_wallet' => true,
            'confirmed_cash' => true,
        ]);
    } catch (PDOException $e) { $auditFailThrew = true; }
    $check($auditFailThrew, 'Cash-In must throw on audit failure');
    $check((int)$pdo->query("SELECT COUNT(*) FROM gcash_transactions WHERE reference_number='0000000000102'")->fetchColumn() === 0, 'Audit failure must roll back cash in record');
} finally { $pdo->exec('DROP TRIGGER gcash_cash_in_audit_fail'); }

// Concurrent Cash-In race on same reference
$cashInRaceProcesses = [];
foreach ([1, 2] as $worker) {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, $database, '--cash-in-race', '4'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $check(is_resource($proc), 'Concurrent cash-in worker must start.');
    $cashInRaceProcesses[] = [$proc, $pipes];
}
$cashInRaceOutcomes = [];
foreach ($cashInRaceProcesses as [$proc, $pipes]) {
    $cashInRaceOutcomes[] = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $check(proc_close($proc) === 0, $err);
}
sort($cashInRaceOutcomes);
$check($cashInRaceOutcomes === ['accepted', 'duplicate'], 'Concurrent cash in must accept exactly one reference');

// Legacy rows preserved and isolated
$pdo->exec("INSERT INTO gcash_transactions (session_id, transaction_type, reference_number, amount, cashier_id, verification_status) VALUES (NULL, 'Payment', '0000000000199', 50.00, 4, 'Verified')");
$legacyRow = $pdo->query("SELECT * FROM gcash_transactions WHERE reference_number='0000000000199'")->fetch(PDO::FETCH_ASSOC);
$check($legacyRow['session_id'] === null && $legacyRow['verification_status'] === 'Verified', 'Legacy row preserved');

$service->closeSession(4, 2520.00, 3900.00, 'Closing session 5', 1);

// 12. Ticket #241: Accountable Cash-Out movements with either fee payment method
$sess6 = $service->openSession(4, 3000.00, 5000.00);

// Unlocked register check / wrong cashier
$refuse(fn() => (new GCashTransactionService($pdo, ['min_length' => 13, 'max_length' => 13]))->recordCashOut(5, [
    'principal' => 1000,
    'fee_method' => 'Fee via GCash',
    'reference' => '0000000000201',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]));

$pdo->exec("UPDATE cashier_shifts SET locked_at=NOW() WHERE cashier_id=4 AND status='open'");
try {
    $refuse(fn() => $service->recordCashOut(4, [
        'principal' => 1000,
        'fee_method' => 'Fee via GCash',
        'reference' => '0000000000201',
        'confirmed_wallet' => true,
        'confirmed_cash' => true,
    ]));
} finally {
    $pdo->exec("UPDATE cashier_shifts SET locked_at=NULL WHERE cashier_id=4 AND status='open'");
}

// Invalid fee method rejected
$refuse(fn() => $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'invalid',
    'reference' => '0000000000201',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]));

// Stale preview totals rejected
$refuse(fn() => $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'Fee via GCash',
    'reference' => '0000000000201',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
    'preview_fee' => 5.00,
]));

$refuse(fn() => $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'Fee in cash',
    'reference' => '0000000000201',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
    'preview_counter_collect' => 0.00,
]));

// Incomplete confirmations rejected for Completed
$refuse(fn() => $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'Fee via GCash',
    'reference' => '0000000000201',
    'confirmed_wallet' => true,
    'confirmed_cash' => false,
]));

$refuse(fn() => $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'Fee via GCash',
    'reference' => '0000000000201',
    'confirmed_wallet' => false,
    'confirmed_cash' => true,
]));

// Insufficient service cash rejection (attempt payout of 3,500 when available service cash is 3,000)
$refuse(fn() => $service->recordCashOut(4, [
    'principal' => 3500,
    'fee_method' => 'Fee via GCash',
    'reference' => '0000000000201',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]));

// Completed PHP 1,000 Cash-Out Fee via GCash: service cash -1,000, wallet +1,010, fee 10 in wallet
$posSnapshotBefore = $snapshot();
$coGkId = $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'Fee via GCash',
    'reference' => '0000000000201',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
    'preview_fee' => 10.00,
    'preview_customer_transfer' => 1010.00,
    'preview_cash_payout' => 1000.00,
    'net_service_cash' => -1000.00,
    'net_wallet' => 1010.00,
]);
$check($coGkId > 0, 'Cash-Out Fee via GCash recorded successfully');

$posSnapshotAfter = $snapshot();
$check($posSnapshotBefore === $posSnapshotAfter, 'Product sales, inventory, and POS cash drawer totals must remain unchanged');

$coGkRow = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id=' . $coGkId)->fetch(PDO::FETCH_ASSOC);
$check((float)$coGkRow['principal_amount'] === 1000.00, 'Principal preserved');
$check((float)$coGkRow['fee_amount'] === 10.00, 'Fee amount preserved');
$check($coGkRow['fee_method'] === 'wallet', 'Fee method wallet preserved');
$check((float)$coGkRow['service_cash_delta'] === -1000.00, 'Service cash delta -1000');
$check((float)$coGkRow['wallet_delta'] === 1010.00, 'Wallet delta +1010');
$check((float)$coGkRow['gross_fee'] === 10.00, 'Gross fee 10');
$check((int)$coGkRow['shift_id'] === 1, 'Shift ID recorded');
$check((int)$coGkRow['confirmed_wallet'] === 1 && (int)$coGkRow['confirmed_cash'] === 1, 'Confirmations recorded');
$check($coGkRow['verification_status'] === 'Completed', 'Status is Completed');
$check(!empty($coGkRow['completed_at']), 'completed_at timestamp recorded');

// Completed record cannot be reviewed or modified
$refuse(fn() => $service->review(4, $coGkId, 'Verified', true, 'Cannot re-review Completed'));

// Expected balances reflect completed Cash-Out via GCash
$expSess6AfterGk = $service->calculateSessionExpected($sess6);
$check($expSess6AfterGk['expected_service_cash'] === 2000.00, 'Expected service cash after GCash fee: 2000.00');
$check($expSess6AfterGk['expected_wallet_balance'] === 6010.00, 'Expected wallet balance after GCash fee: 6010.00');

// Completed PHP 1,000 Cash-Out Fee in cash: service cash -990, wallet +1,000, fee 10 in cash
$coCkId = $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'Fee in cash',
    'reference' => '0000000000202',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
    'preview_fee' => 10.00,
    'preview_customer_transfer' => 1000.00,
    'preview_counter_collect' => 10.00,
    'preview_cash_payout' => 1000.00,
    'net_service_cash' => -990.00,
    'net_wallet' => 1000.00,
]);
$check($coCkId > 0, 'Cash-Out Fee in cash recorded successfully');

$coCkRow = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id=' . $coCkId)->fetch(PDO::FETCH_ASSOC);
$check((float)$coCkRow['principal_amount'] === 1000.00, 'Principal preserved');
$check((float)$coCkRow['fee_amount'] === 10.00, 'Fee amount preserved');
$check($coCkRow['fee_method'] === 'cash', 'Fee method cash preserved');
$check((float)$coCkRow['service_cash_delta'] === -990.00, 'Service cash delta -990');
$check((float)$coCkRow['wallet_delta'] === 1000.00, 'Wallet delta +1000');
$check((float)$coCkRow['gross_fee'] === 10.00, 'Gross fee 10');
$check($coCkRow['verification_status'] === 'Completed', 'Status is Completed');

// Expected balances reflect completed Cash-Out with cash fee
$expSess6AfterCk = $service->calculateSessionExpected($sess6);
$check($expSess6AfterCk['expected_service_cash'] === 1010.00, 'Expected service cash after cash fee: 1010.00');
$check($expSess6AfterCk['expected_wallet_balance'] === 7010.00, 'Expected wallet balance after cash fee: 7010.00');

// Duplicate reference rejection
$refuse(fn() => $service->recordCashOut(4, [
    'principal' => 500,
    'fee_method' => 'Fee via GCash',
    'reference' => '0000000000201',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]));

$service->closeSession(4, 1010.00, 7010.00, 'Closing session 6');

// Session 7 for Centavo fees and Needs checking
$sess7 = $service->openSession(4, 20000.00, 10000.00);

// Centavo fee: PHP 12,345 principal with PHP 123.45 fee in cash
$co12kId = $service->recordCashOut(4, [
    'principal' => 12345,
    'fee_method' => 'Fee in cash',
    'reference' => '0000000000203',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
    'preview_fee' => 123.45,
    'preview_customer_transfer' => 12345.00,
    'preview_counter_collect' => 123.45,
    'preview_cash_payout' => 12345.00,
    'net_service_cash' => -12221.55,
    'net_wallet' => 12345.00,
]);
$co12kRow = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id=' . $co12kId)->fetch(PDO::FETCH_ASSOC);
$check((float)$co12kRow['principal_amount'] === 12345.00, '12345 principal');
$check((float)$co12kRow['fee_amount'] === 123.45, '123.45 centavo fee');
$check((float)$co12kRow['service_cash_delta'] === -12221.55, 'Service cash delta -12221.55');
$check((float)$co12kRow['wallet_delta'] === 12345.00, 'Wallet delta +12345.00');
$check((float)$co12kRow['gross_fee'] === 123.45, 'Gross fee 123.45');

$expSess7 = $service->calculateSessionExpected($sess7);
$check($expSess7['expected_service_cash'] === 7778.45, 'Expected service cash: 7778.45');
$check($expSess7['expected_wallet_balance'] === 22345.00, 'Expected wallet balance: 22345.00');

// Partial / Needs checking Cash-Out test:
// Case A: Customer transferred wallet funds, cashier could not pay out cash
$refuse(fn() => $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'Fee via GCash',
    'outcome' => 'Needs checking',
    'notes' => 'bad',
]));

$ncTxId1 = $service->recordCashOut(4, [
    'principal' => 1000,
    'fee_method' => 'Fee via GCash',
    'outcome' => 'Needs checking',
    'confirmed_wallet' => true,
    'confirmed_cash' => false,
    'notes' => 'Customer transferred 1010 wallet funds, cash payout delayed pending register change',
]);
$ncRow1 = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id=' . $ncTxId1)->fetch(PDO::FETCH_ASSOC);
$check($ncRow1['reference_number'] === null, 'Needs checking preserves NULL reference when blank');
$check($ncRow1['verification_status'] === 'Needs checking', 'Status is Needs checking');
$check((float)$ncRow1['service_cash_delta'] === 0.00, 'Service cash delta 0 when cash not paid');
$check((float)$ncRow1['wallet_delta'] === 1010.00, 'Wallet delta +1010 for received transfer');
$check((float)$ncRow1['gross_fee'] === 0.00, 'Gross fee 0 for incomplete exchange');

$expAfterNC1 = $service->calculateSessionExpected($sess7);
$check($expAfterNC1['expected_service_cash'] === 7778.45, 'Expected service cash unchanged at 7778.45');
$check($expAfterNC1['expected_wallet_balance'] === 23355.00, 'Expected wallet balance includes received funds (23355.00)');

$refuse(fn() => $service->review(4, $ncTxId1, 'Verified', true, 'Cannot review Needs checking'));

// Case B: Cash handed over and fee collected, wallet transfer not received
$ncTxId2 = $service->recordCashOut(4, [
    'principal' => 500,
    'fee_method' => 'Fee in cash',
    'outcome' => 'Needs checking',
    'reference' => '0000000000204',
    'confirmed_wallet' => false,
    'confirmed_cash' => true,
    'notes' => 'Cash payout 500 given less 10 fee collected, customer GCash transfer unconfirmed',
]);
$ncRow2 = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id=' . $ncTxId2)->fetch(PDO::FETCH_ASSOC);
$check($ncRow2['reference_number'] === '0000000000204', 'Optional reference preserved');
$check((float)$ncRow2['service_cash_delta'] === -490.00, 'Service cash delta -490 for cash side done');
$check((float)$ncRow2['wallet_delta'] === 0.00, 'Wallet delta 0 when wallet not received');
$check((float)$ncRow2['gross_fee'] === 0.00, 'Gross fee 0 for incomplete exchange');

$expAfterNC2 = $service->calculateSessionExpected($sess7);
$check($expAfterNC2['expected_service_cash'] === 7288.45, 'Expected service cash: 7288.45');
$check($expAfterNC2['expected_wallet_balance'] === 23355.00, 'Expected wallet: 23355.00');

// Audit failure rollback for Cash-Out
$pdo->exec("CREATE TRIGGER gcash_cash_out_audit_fail BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit injection fail'");
try {
    $auditFailThrew = false;
    try {
        $service->recordCashOut(4, [
            'principal' => 200,
            'fee_method' => 'Fee via GCash',
            'reference' => '0000000000887',
            'confirmed_wallet' => true,
            'confirmed_cash' => true,
        ]);
    } catch (PDOException $e) { $auditFailThrew = true; }
    $check($auditFailThrew, 'Cash-Out must throw on audit failure');
    $check((int)$pdo->query("SELECT COUNT(*) FROM gcash_transactions WHERE reference_number='0000000000887'")->fetchColumn() === 0, 'Audit failure must roll back cash out record');
} finally {
    $pdo->exec('DROP TRIGGER gcash_cash_out_audit_fail');
}

// Concurrent Cash-Out race on same reference
$cashOutRaceProcesses = [];
foreach ([1, 2] as $worker) {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, $database, '--cash-out-race', '4'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $check(is_resource($proc), 'Concurrent cash-out worker must start.');
    $cashOutRaceProcesses[] = [$proc, $pipes];
}
$cashOutRaceOutcomes = [];
foreach ($cashOutRaceProcesses as [$proc, $pipes]) {
    $cashOutRaceOutcomes[] = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $check(proc_close($proc) === 0, $err);
}
sort($cashOutRaceOutcomes);
$check($cashOutRaceOutcomes === ['accepted', 'duplicate'], 'Concurrent cash out must accept exactly one reference');

$service->closeSession(4, 7288.45, 23355.00, 'Closing session 7', 2);

// Ticket #242: Session 8 - Funding movements, linked corrections, resolving needs checking, discrepancy waivers, emergency handover
$sess8 = $service->openSession(4, 2000.00, 5000.00);

// Funding movements: permissions, evidence, validation, balance updates
$refuse(fn() => $service->recordFundingMovement(4, 'cashier', [
    'movement_type' => 'replenishment',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 1000,
    'reason' => 'Unauthorized cashier replenishment without admin credentials',
]));
$refuse(fn() => $service->recordFundingMovement(4, 'cashier', [
    'movement_type' => 'replenishment',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 1000,
    'reason' => 'Bad admin password',
    'approver_username' => 'theme_admin',
    'approver_password' => 'WrongPass',
]));
$refuse(fn() => $service->recordFundingMovement(1, 'admin', [
    'movement_type' => 'invalid_type',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 1000,
    'reason' => 'Invalid movement type',
]));
$refuse(fn() => $service->recordFundingMovement(1, 'admin', [
    'movement_type' => 'replenishment',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => -50,
    'reason' => 'Negative amount refused',
]));
$refuse(fn() => $service->recordFundingMovement(1, 'admin', [
    'movement_type' => 'withdrawal',
    'fund_type' => 'cash',
    'direction' => 'out',
    'amount' => 999999,
    'reason' => 'Withdraw more cash than exists',
]));
$refuse(fn() => $service->recordFundingMovement(1, 'admin', [
    'movement_type' => 'owner_capital',
    'fund_type' => 'wallet',
    'direction' => 'in',
    'amount' => 5000,
    'reason' => 'Missing evidence on owner capital',
    'evidence' => '',
]));

// Valid funding movement: Replenishment (Cash in 1000)
$fundId1 = $service->recordFundingMovement(4, 'cashier', [
    'movement_type' => 'replenishment',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 1000,
    'reason' => 'Replenish change fund for morning rush',
    'approver_username' => 'theme_admin',
    'approver_password' => 'ThemeTest@2026',
]);
$check($fundId1 > 0, 'Funding replenishment recorded');
$fRow1 = $pdo->query('SELECT * FROM gcash_funding_movements WHERE movement_id = ' . $fundId1)->fetch(PDO::FETCH_ASSOC);
$check((int)$fRow1['authorized_by'] === 1 && (int)$fRow1['recorded_by'] === 4, 'Authorized by admin 1, recorded by cashier 4');
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 3000.00, 'Expected service cash: 3000.00');
$check($exp8['expected_wallet_balance'] === 5000.00, 'Expected wallet: 5000.00');

// Product drawer transfer: Cash 500 into GCash cash fund
$drawerMovementsBefore = (int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn();
$fundId2 = $service->recordFundingMovement(4, 'cashier', [
    'movement_type' => 'product_drawer_transfer',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 500,
    'evidence' => 'Slip-PD-01',
    'reason' => 'Transfer from main product drawer',
    'approver_username' => 'theme_admin',
    'approver_password' => 'ThemeTest@2026',
]);
$drawerMovementsAfter = (int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn();
$check($drawerMovementsAfter === $drawerMovementsBefore + 1, 'Product drawer movement logged for cash drawer transfer');
$lastDrawerMove = $pdo->query('SELECT * FROM cash_drawer_movements ORDER BY drawer_movement_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$check($lastDrawerMove['movement_type'] === 'cash_out' && (float)$lastDrawerMove['amount'] === 500.00, 'Drawer movement is cash_out of 500');
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 3500.00, 'Expected service cash: 3500.00');

// Owner capital: Wallet in 10000
$fundId3 = $service->recordFundingMovement(1, 'admin', [
    'movement_type' => 'owner_capital',
    'fund_type' => 'wallet',
    'direction' => 'in',
    'amount' => 10000,
    'evidence' => 'BankRef-99201',
    'reason' => 'Owner capital infusion to wallet',
]);
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_wallet_balance'] === 15000.00, 'Expected wallet: 15000.00');

// Withdrawal: Cash out 200 to store safe
$fundId4 = $service->recordFundingMovement(1, 'admin', [
    'movement_type' => 'withdrawal',
    'fund_type' => 'cash',
    'direction' => 'out',
    'amount' => 200,
    'reason' => 'Transfer excess cash to store safe',
]);
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 3300.00, 'Expected service cash: 3300.00');

// Concurrent funding race
$fundingRaceProcesses = [];
foreach ([1, 2] as $worker) {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, $database, '--funding-race', '1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $check(is_resource($proc), 'Concurrent funding worker must start.');
    $fundingRaceProcesses[] = [$proc, $pipes];
}
$fundingRaceOutcomes = [];
foreach ($fundingRaceProcesses as [$proc, $pipes]) {
    $fundingRaceOutcomes[] = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $check(proc_close($proc) === 0, $err);
}
$check(count(array_filter($fundingRaceOutcomes, fn($o) => $o === 'accepted')) === 2, 'Concurrent funding accepted');
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 3500.00, 'Expected service cash after concurrent funding (+200): 3500.00');

// Linked corrections on immutable Completed exchanges
$ciTxId = $service->recordCashIn(4, [
    'principal' => 1000,
    'reference' => '0000000000301',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]);
// After Cash-In 1000: fee is 10 (cash), counter_collect 1010, wallet 1000
// Expected cash = 3500 + 1010 = 4510.00, wallet = 15000 - 1000 = 14000.00
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 4510.00, 'Expected cash before correction: 4510.00');
$check($exp8['expected_wallet_balance'] === 14000.00, 'Expected wallet before correction: 14000.00');

// Correction refusals
$refuse(fn() => $service->recordLinkedCorrection(4, 'cashier', $ciTxId, -10.00, 0.00, 'Non-admin correction refused'));
$refuse(fn() => $service->recordLinkedCorrection(1, 'admin', $ciTxId, 0.00, 0.00, 'Zero delta correction refused'));
$refuse(fn() => $service->recordLinkedCorrection(1, 'admin', $ciTxId, -10.00, 0.00, 'Tiny'));

// Valid linked correction: -10 cash delta (fee refund)
$adjId1 = $service->recordLinkedCorrection(1, 'admin', $ciTxId, -10.00, 0.00, 'Customer refunded 10 pesos fee overcharge');
$check($adjId1 > 0, 'Linked correction recorded');

// Verify original transaction row is strictly immutable
$ciRow = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id = ' . $ciTxId)->fetch(PDO::FETCH_ASSOC);
$check($ciRow['verification_status'] === 'Completed', 'Original transaction remains Completed');
$check($ciRow['reference_number'] === '0000000000301', 'Reference unchanged');
$check((float)$ciRow['service_cash_delta'] === 1010.00, 'Original service_cash_delta unchanged');
$check((float)$ciRow['wallet_delta'] === -1000.00, 'Original wallet_delta unchanged');

// Verify expected totals incorporate adjustment
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 4500.00, 'Expected cash after -10 correction: 4500.00');
$check($exp8['expected_wallet_balance'] === 14000.00, 'Expected wallet unchanged: 14000.00');

// Verify history includes adjustments attached to transaction
$cashierHist = $service->history(4, 1);
$foundTx = null;
foreach ($cashierHist['transactions'] as $t) {
    if ((int)$t['transaction_id'] === $ciTxId) { $foundTx = $t; break; }
}
$check($foundTx !== null && count($foundTx['adjustments']) === 1, 'History attaches linked adjustments');
$check((float)$foundTx['adjustments'][0]['service_cash_delta'] === -10.00, 'Adjustment cash delta matches');

// Resolving Needs checking: Case A - Complete remaining movements
$ncCoTxId = $service->recordCashOut(4, [
    'principal' => 500,
    'fee_method' => 'Fee via GCash',
    'outcome' => 'Needs checking',
    'reference' => '0000000000302',
    'confirmed_wallet' => true,
    'confirmed_cash' => false,
    'notes' => 'Customer sent 510 wallet, cash payout delayed',
]);
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 4500.00, 'Expected cash unchanged while payout pending');
$check($exp8['expected_wallet_balance'] === 14510.00, 'Expected wallet includes received 510');

// Correction cannot be recorded on Needs checking transaction
$refuse(fn() => $service->recordLinkedCorrection(1, 'admin', $ncCoTxId, -5.00, 0.00, 'Correction on Needs checking refused'));

// Complete the transaction
$resA = $service->resolveNeedsChecking(4, $ncCoTxId, 'complete', ['notes' => 'Cash handed over 500 pesos']);
$check($resA['verification_status'] === 'Completed', 'Resolved to Completed');
$resRowA = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id = ' . $ncCoTxId)->fetch(PDO::FETCH_ASSOC);
$check($resRowA['verification_status'] === 'Completed' && (int)$resRowA['confirmed_cash'] === 1, 'Transaction updated to Completed with confirmed_cash = 1');
$check((float)$resRowA['service_cash_delta'] === -500.00 && (float)$resRowA['gross_fee'] === 10.00, 'Cash delta -500 and gross fee 10 set');
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 4000.00, 'Expected cash reflects completed payout: 4000.00');
$check($exp8['expected_wallet_balance'] === 14510.00, 'Expected wallet: 14510.00');

// Cannot resolve again once completed
$refuse(fn() => $service->resolveNeedsChecking(4, $ncCoTxId, 'complete', ['notes' => 'Duplicate completion']));

// Resolving Needs checking: Case B - Cancel with linked offsetting adjustments
$ncCiTxId = $service->recordCashIn(4, [
    'principal' => 400,
    'outcome' => 'Needs checking',
    'confirmed_cash' => true,
    'confirmed_wallet' => false,
    'notes' => 'Customer paid 405 cash, wallet transfer delayed',
]);
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 4405.00, 'Expected cash includes 405 collected: 4405.00');

// Cashier cannot cancel without admin authorization credentials
$refuse(fn() => $service->resolveNeedsChecking(4, $ncCiTxId, 'cancel', [
    'reason' => 'Customer refunded, network offline',
]));
$refuse(fn() => $service->resolveNeedsChecking(4, $ncCiTxId, 'cancel', [
    'reason' => 'Customer refunded, network offline',
    'approver_username' => 'theme_admin',
    'approver_password' => 'WrongPass',
]));

// Cancel with admin credentials
$resB = $service->resolveNeedsChecking(4, $ncCiTxId, 'cancel', [
    'reason' => 'Customer refunded, network offline',
    'approver_username' => 'theme_admin',
    'approver_password' => 'ThemeTest@2026',
]);
$check($resB['verification_status'] === 'Rejected', 'Resolved to Rejected');
$resRowB = $pdo->query('SELECT * FROM gcash_transactions WHERE transaction_id = ' . $ncCiTxId)->fetch(PDO::FETCH_ASSOC);
$check($resRowB['verification_status'] === 'Rejected', 'Status updated to Rejected');

// Verify linked offsetting adjustment created
$adjB = $pdo->query('SELECT * FROM gcash_adjustments WHERE transaction_id = ' . $ncCiTxId)->fetch(PDO::FETCH_ASSOC);
$check($adjB && (float)$adjB['service_cash_delta'] === -405.00, 'Offsetting adjustment of -405 created');

// Verify expected cash has returned to 4000.00
$exp8 = $service->calculateSessionExpected($sess8);
$check($exp8['expected_service_cash'] === 4000.00, 'Expected cash offset back to 4000.00');
$check($exp8['expected_wallet_balance'] === 14510.00, 'Expected wallet: 14510.00');

// Cannot resolve again once rejected
$refuse(fn() => $service->resolveNeedsChecking(4, $ncCiTxId, 'cancel', ['reason' => 'Duplicate cancel', 'approver_username' => 'theme_admin', 'approver_password' => 'ThemeTest@2026']));

// Audit failure rollback for funding movements
$pdo->exec("CREATE TRIGGER gcash_funding_audit_fail BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Funding audit injection fail'");
try {
    $auditFailThrew = false;
    try {
        $service->recordFundingMovement(1, 'admin', [
            'movement_type' => 'replenishment',
            'fund_type' => 'cash',
            'direction' => 'in',
            'amount' => 100,
            'reason' => 'Test audit failure rollback',
        ]);
    } catch (PDOException $e) { $auditFailThrew = true; }
    $check($auditFailThrew, 'Funding movement must throw on audit failure');
    $check((int)$pdo->query("SELECT COUNT(*) FROM gcash_funding_movements WHERE reason='Test audit failure rollback'")->fetchColumn() === 0, 'Audit failure must roll back funding movement');
} finally {
    $pdo->exec('DROP TRIGGER gcash_funding_audit_fail');
}

// Close session 8 with discrepancy: Counted cash 3950 (-50 variance)
$service->closeSession(4, 3950.00, 14510.00, 'Session 8 closing with 50 pesos variance', 0);
$s8Row = $pdo->query('SELECT * FROM gcash_service_sessions WHERE session_id = ' . $sess8)->fetch(PDO::FETCH_ASSOC);
$check($s8Row['status'] === 'closed' && (float)$s8Row['cash_variance'] === -50.00, 'Session closed with -50 cash variance');
$check($s8Row['waived_at'] === null, 'Discrepancy not yet waived');

// Discrepancy waiver
$refuse(fn() => $service->waiveDiscrepancy(4, 'cashier', $sess8, 'Cashier cannot waive discrepancy'));
$refuse(fn() => $service->waiveDiscrepancy(1, 'admin', $sess8, 'Tiny'));

// Valid discrepancy waiver by admin
$service->waiveDiscrepancy(1, 'admin', $sess8, 'Shortage acknowledged and waived due to counterfeit 50 peso note surrendered');
$s8Waived = $pdo->query('SELECT * FROM gcash_service_sessions WHERE session_id = ' . $sess8)->fetch(PDO::FETCH_ASSOC);
$check((int)$s8Waived['waived_by'] === 1 && $s8Waived['waived_at'] !== null, 'Waiver recorded with admin attribution');
$check((float)$s8Waived['cash_variance'] === -50.00, 'Permanent counted variance preserved');
$check(strpos($s8Waived['waiver_notes'], 'counterfeit 50 peso note') !== false, 'Waiver notes recorded');

// Duplicate waiver refused
$refuse(fn() => $service->waiveDiscrepancy(1, 'admin', $sess8, 'Duplicate waiver rejected'));

// Emergency Handover by Administrator
// Create incoming cashier with open shift
$newUserId = (int)$pdo->query('SELECT MAX(user_id) FROM users')->fetchColumn() + 1;
$newRegisterId = (int)$pdo->query('SELECT MAX(register_id) FROM registers')->fetchColumn() + 1;
$newShiftId = (int)$pdo->query('SELECT MAX(shift_id) FROM cashier_shifts')->fetchColumn() + 1;
$pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id, must_change_password) VALUES ({$newUserId}, 'Theme Cashier Two', 'theme_cashier2', '" . password_hash('ThemeTest@2026', PASSWORD_DEFAULT) . "', 4, 1, 0)");
$pdo->exec("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES ({$newUserId}, 4, 1)");
$pdo->exec("INSERT INTO registers (register_id, name) VALUES ({$newRegisterId}, 'Theme Register 2')");
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status) VALUES ({$newShiftId}, {$newUserId}, {$newRegisterId}, 100, 'open')");

// Cashier 4 opens Session 9
$sess9 = $service->openSession(4, 1000.00, 2000.00);

// Non-admin emergency handover refused
$refuse(fn() => $service->emergencyHandover(4, $newUserId, 1000.00, 2000.00, 'Cashier cannot do emergency handover'));

// Admin executes emergency handover to Cashier
$handover = $service->emergencyHandover(1, $newUserId, 1000.00, 2000.00, 'Cashier 4 medical emergency during shift');
$check($handover['outgoing_session_id'] === $sess9, 'Outgoing session is 9');
$check($handover['incoming_session_id'] > $sess9, 'Incoming session created');

$s9Row = $pdo->query('SELECT * FROM gcash_service_sessions WHERE session_id = ' . $sess9)->fetch(PDO::FETCH_ASSOC);
$check($s9Row['status'] === 'closed' && (int)$s9Row['closed_by'] === 1, 'Outgoing session closed by admin');
$check(strpos($s9Row['closing_notes'], 'medical emergency') !== false, 'Emergency reason recorded');

$newSessRow = $pdo->query('SELECT * FROM gcash_service_sessions WHERE session_id = ' . $handover['incoming_session_id'])->fetch(PDO::FETCH_ASSOC);
$check($newSessRow['status'] === 'open' && (int)$newSessRow['cashier_id'] === $newUserId, 'New session opened for incoming cashier');

// Close new session cleanly
$service->closeSession($newUserId, 1000.00, 2000.00, 'Closing handed-over session', 0);

// Ticket #243: Cashier Overview, fee split, needs_checking scoping, and history cashier authorization
$sess10 = $service->openSession(4, 2000.00, 5000.00);
$ciId = $service->recordCashIn(4, [
    'principal' => 1000,
    'reference' => '0000000000701',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]);
$co1Id = $service->recordCashOut(4, [
    'principal' => 500,
    'fee_method' => 'Fee via GCash',
    'reference' => '0000000000702',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]);
$co2Id = $service->recordCashOut(4, [
    'principal' => 300,
    'fee_method' => 'Fee in cash',
    'reference' => '0000000000703',
    'confirmed_wallet' => true,
    'confirmed_cash' => true,
]);
$nc4Id = $service->recordCashIn(4, [
    'outcome' => 'Needs checking',
    'principal' => 400,
    'notes' => 'Customer left before wallet confirmation',
    'confirmed_cash' => true,
    'confirmed_wallet' => false,
]);

// Overview for cashier 4
$ov4 = $service->cashierOverview(4);
$check($ov4['is_controlling_cashier'] === true, 'Cashier 4 controls session 10');
$check($ov4['expected_service_cash'] > 0 && $ov4['expected_wallet_balance'] > 0, 'Expected balances calculated');
$check($ov4['last_checked_at'] !== null && $ov4['last_checked_formatted'] !== null, 'Last checked timestamp present');
$check((float)$ov4['cash_in_principal'] === 1000.00, 'Cash-in principal total matches completed only');
$check((float)$ov4['cash_out_principal'] === 800.00, 'Cash-out principal total matches completed');
$check((float)$ov4['gross_fees_cash'] === 15.00, 'Gross fees in cash split (10 CI + 5 CO)');
$check((float)$ov4['gross_fees_wallet'] === 10.00, 'Gross fees in wallet split (10 CO)');
$check($ov4['needs_checking_count'] === 1, 'Only 1 unresolved needs checking for cashier 4');
$check((int)$ov4['needs_checking'][0]['transaction_id'] === $nc4Id, 'Needs checking transaction matches');
$check($ov4['needs_checking'][0]['time_formatted'] !== '', 'Needs checking has Philippine time');
$check($ov4['needs_checking'][0]['cashier_name'] !== '', 'Needs checking has Cashier name');

// Scoping: Needs checking for another cashier
$pdo->prepare("INSERT INTO gcash_transactions (session_id, shift_id, transaction_type, reference_number, amount, principal_amount, fee_amount, fee_method, cashier_id, verification_status, notes) VALUES (?, ?, 'Cash-In', '0000000000799', 200, 200, 5, 'cash', ?, 'Needs checking', 'Other cashier held item')")
    ->execute([$sess10, $newShiftId, $newUserId]);

// Cashier 4 overview must NOT see other cashier's needs checking
$ov4After = $service->cashierOverview(4);
$check($ov4After['needs_checking_count'] === 1, 'Needs checking strictly scoped to cashier');

// Cashier history authorization: cashier 4 only sees cashier 4 rows
$h4 = $service->history(4, 1);
foreach ($h4['transactions'] as $tx) {
    $check((int)$tx['cashier_id'] === 4, 'History strictly scoped to authorized cashier');
}

// Legacy record flags
$legacyFound = false;
foreach ($h4['transactions'] as $tx) {
    if ((int)$tx['transaction_id'] === $id) {
        $legacyFound = true;
        $check($tx['is_legacy'] === true, 'Legacy transaction marked is_legacy true');
        $check($tx['shift_id'] === null, 'Legacy transaction has no shift assigned');
        $check($tx['principal_amount'] === null, 'Legacy transaction has no inferred principal');
        $check($tx['gross_fee'] === 0.00, 'Legacy transaction has zero gross fee');
    }
}
$check($legacyFound, 'Legacy transaction found in history');

// Other cashier history cannot see cashier 4 rows
$hOther = $service->history($newUserId, 1);
foreach ($hOther['transactions'] as $tx) {
    $check((int)$tx['cashier_id'] === $newUserId, 'Other cashier history only sees own rows');
}

// Close session 10 cleanly or test ticket #244 Administrator GCash overview
// Ticket #244: Administrator GCash Overview oversight

// 1. Authorization check: non-admin roles refused
$refuse(static fn() => $service->adminOverview(['actor_role' => 'cashier']));
$refuse(static fn() => $service->adminOverview(['actor_role' => 'inventory_manager']));
$refuse(static fn() => $service->adminOverview([]));

// 2. Admin access granted and current expected balances computed
$adminOv = $service->adminOverview(['actor_role' => 'admin']);
$superOv = $service->adminOverview(['actor_role' => 'super_admin']);
$check($adminOv['current_expected_balances']['expected_service_cash'] > 0, 'Current expected service cash computed');
$check($adminOv['current_expected_balances']['expected_wallet_balance'] > 0, 'Current expected wallet balance computed');
$check($adminOv['session_status'] === 'open', 'Session status open');
$check($adminOv['controlling_cashier_id'] === 4, 'Controlling cashier id matches session 10');

// Search/type filters apply before pagination and leave current funds unchanged.
$searchEmpty = $service->adminOverview(['actor_role' => 'admin', 'search' => 'no-such-gcash-reference']);
$check($searchEmpty['total_transactions'] === 0 && $searchEmpty['transactions'] === [], 'Unknown reference returns no matching transactions');
$searchLiteral = $service->adminOverview(['actor_role' => 'admin', 'search' => '%']);
$check($searchLiteral['total_transactions'] === 0, 'Search treats SQL wildcards as literal text');
$cashInOnly = $service->adminOverview(['actor_role' => 'admin', 'type' => 'Cash-In', 'limit' => 10]);
$check(count($cashInOnly['transactions']) > 0, 'Cash-in filter has matching exchanges');
foreach ($cashInOnly['transactions'] as $tx) {
    $check($tx['transaction_type'] === 'Cash-In', 'Type filter excludes other exchange types');
}
$check($cashInOnly['current_expected_service_cash'] === $adminOv['current_expected_service_cash'], 'Type filtering does not change current service cash');
$shiftSearch = $service->adminOverview(['actor_role' => 'admin', 'search' => '#' . $cashInOnly['transactions'][0]['shift_id']]);
$check($shiftSearch['total_transactions'] > 0, 'Search accepts a shift number with its displayed hash prefix');

$refuse(static fn() => $service->adminOverview(['actor_role' => 'admin', 'type' => 'invalid']));

// 3. Store-wide activity aggregation across cashiers
$expectedTotals = $pdo->query(
    "SELECT
        COUNT(*) AS total_transactions,
        COALESCE(SUM(CASE WHEN transaction_type = 'Cash-In' AND verification_status = 'Completed' THEN principal_amount ELSE 0 END), 0) AS cash_in_principal,
        COALESCE(SUM(CASE WHEN transaction_type = 'Cash-Out' AND verification_status = 'Completed' THEN principal_amount ELSE 0 END), 0) AS cash_out_principal,
        COALESCE(SUM(CASE WHEN fee_method = 'cash' AND verification_status = 'Completed' THEN gross_fee ELSE 0 END), 0) AS gross_fees_cash,
        COALESCE(SUM(CASE WHEN fee_method = 'wallet' AND verification_status = 'Completed' THEN gross_fee ELSE 0 END), 0) AS gross_fees_wallet
    FROM gcash_transactions"
)->fetch(PDO::FETCH_ASSOC);

$check((float)$adminOv['cash_in_principal'] === (float)$expectedTotals['cash_in_principal'], 'Store cash in principal');
$check((float)$adminOv['cash_out_principal'] === (float)$expectedTotals['cash_out_principal'], 'Store cash out principal');
$check((float)$adminOv['gross_fees_cash'] === (float)$expectedTotals['gross_fees_cash'], 'Store gross fees cash');
$check((float)$adminOv['gross_fees_wallet'] === (float)$expectedTotals['gross_fees_wallet'], 'Store gross fees wallet');
$check((int)$adminOv['total_transactions'] === (int)$expectedTotals['total_transactions'], 'Store total transactions aggregated');

// 4. Store-wide unresolved activity visibility
$check($adminOv['unresolved_count'] >= 2, 'Store-wide unresolved count includes all cashiers');
$foundNc4 = false;
$foundNcOther = false;
foreach ($adminOv['unresolved_activity'] as $item) {
    if ((int)$item['transaction_id'] === $nc4Id) $foundNc4 = true;
    if ((int)$item['cashier_id'] === $newUserId) $foundNcOther = true;
}
$check($foundNc4 && $foundNcOther, 'Both cashier 4 and other cashier unresolved items visible in admin overview');

// 5. Philippine date boundaries filtering
$today = App\Services\PhilippineTime::today();
$dateFiltered = $service->adminOverview(['actor_role' => 'admin', 'start_date' => $today, 'end_date' => $today]);
$check((float)$dateFiltered['cash_in_principal'] === (float)$expectedTotals['cash_in_principal'], 'Date filtered today matches');
$pastFiltered = $service->adminOverview(['actor_role' => 'admin', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02']);
$check((float)$pastFiltered['cash_in_principal'] === 0.00, 'Past date range has zero principal');
$check($pastFiltered['current_expected_service_cash'] === $adminOv['current_expected_service_cash'], 'Current expected balances unaffected by historical date filter');
$refuse(static fn() => $service->adminOverview(['actor_role' => 'admin', 'start_date' => '2026-10-20', 'end_date' => '2026-10-10']));
$refuse(static fn() => $service->adminOverview(['actor_role' => 'admin', 'start_date' => 'invalid-date']));

// 6. Cashier filter
$cashier4Filtered = $service->adminOverview(['actor_role' => 'admin', 'cashier_id' => 4]);
$check((float)$cashier4Filtered['cash_in_principal'] === (float)$expectedTotals['cash_in_principal'], 'Cashier 4 filter matches');
$otherFiltered = $service->adminOverview(['actor_role' => 'admin', 'cashier_id' => $newUserId]);
$check((float)$otherFiltered['cash_in_principal'] === 0.00, 'Other cashier has zero completed cash-in');
$check($otherFiltered['unresolved_count'] === 1, 'Other cashier unresolved isolated when cashier filtered');

// 7. Legacy reference exclusion from financial totals
$legacyFoundInAdmin = false;
foreach ($adminOv['transactions'] as $tx) {
    if ((int)$tx['transaction_id'] === $id) {
        $legacyFoundInAdmin = true;
        $check($tx['is_legacy'] === true, 'Admin history marks legacy true');
        $check($tx['shift_id'] === null, 'Admin history legacy has null shift');
        $check($tx['principal_amount'] === null, 'Admin history legacy has null principal');
        $check($tx['gross_fee'] === 0.00, 'Admin history legacy has 0 gross fee');
        $check($tx['fee_location'] === null, 'Admin history legacy has null fee location');
    }
}
$check($legacyFoundInAdmin, 'Legacy transaction present in admin history');

// 8. Close session 10 with non-zero variance and verify reconciliation differences & waiver
$exp10 = $service->calculateSessionExpected($sess10);
$closeResult = $service->closeSession(4, (float)$exp10['expected_service_cash'] - 20.00, (float)$exp10['expected_wallet_balance'], 'Session 10 test close with variance', 1);
$check((float)$closeResult['cash_variance'] === -20.00, 'Session 10 closed with cash variance');

$afterCloseOv = $service->adminOverview(['actor_role' => 'admin']);
$check($afterCloseOv['session_status'] === 'closed', 'Session status now closed');
$check($afterCloseOv['differences_count'] >= 1, 'Reconciliation differences tracked');
$sess10Diff = null;
foreach ($afterCloseOv['reconciliation_differences'] as $diff) {
    if ($diff['session_id'] === $sess10) { $sess10Diff = $diff; break; }
}
$check($sess10Diff !== null, 'Session 10 variance found in reconciliation differences');
$check((float)$sess10Diff['cash_variance'] === -20.00, 'Session 10 cash variance matches');
$check($sess10Diff['is_waived'] === false, 'Session 10 variance initially not waived');

// Waive discrepancy
$service->waiveDiscrepancy(1, 'admin', $sess10, 'Approved manager variance waiver');
$afterWaiveOv = $service->adminOverview(['actor_role' => 'admin']);
$waivedFound = false;
foreach ($afterWaiveOv['reconciliation_differences'] as $diff) {
    if ($diff['session_id'] === $sess10) {
        $waivedFound = true;
        $check($diff['is_waived'] === true, 'Session 10 variance marked waived');
        $check($diff['waiver_notes'] === 'Approved manager variance waiver', 'Waiver notes recorded');
        break;
    }
}
$check($waivedFound, 'Waived session verified in reconciliation differences');

// Enough public records to exercise search/pagination and full filtered CSV export.
for ($index = 0; $index < 55; $index++) {
    $service->save(4, '888888888' . str_pad((string)$index, 4, '0', STR_PAD_LEFT), '1', 'Payment');
}
$exportPage = $service->adminOverview(['actor_role' => 'admin', 'search' => '888888888', 'page' => 2]);
$check($exportPage['total_transactions'] === 55 && count($exportPage['transactions']) === 5, 'Search matches across page boundaries');

echo "GCash MySQL integration: passed\n";

