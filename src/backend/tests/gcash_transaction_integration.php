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
if (in_array($argv[2] ?? '', ['--save-race', '--review-race'], true)) {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            if ($argv[2] === '--save-race') { $service->save(4, '0000000000004', '1', 'Payment'); }
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
$check($row['reference_number'] === '0000000000001' && $row['verification_status'] === 'Pending', 'Save must preserve zeroes and stay Pending.');
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
echo "GCash MySQL integration: passed\n";
