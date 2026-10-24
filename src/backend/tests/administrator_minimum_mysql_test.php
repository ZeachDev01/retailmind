<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') { echo "Administrator minimum MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$command = static function (string $file, string $action) use ($database): string {
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/' . $file, $action, $database], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($errors . $output);
    return $output;
};
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$children = [];
try {
    $command('theme_fixture.php', 'create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ([['disable', 'disable'], ['demote', 'demote'], ['dormancy', 'dormancy'], ['disable', 'demote'], ['disable', 'dormancy'], ['demote', 'dormancy']] as [$first, $second]) {
        $command('administrator_minimum_fixture.php', 'seed');
        $connections = [];
        foreach ([$first, $second] as $index => $action) {
            // Each runner discovers a different target in its old snapshot.
            // Its locked eligibility/count recheck must see both active accounts below.
            $pdo->exec("UPDATE users SET status = IF(user_id = " . (100 + $index) . ", 'active', 'disabled') WHERE user_id IN (100, 101)");
            $process = proc_open([PHP_BINARY, __DIR__ . '/support/administrator_minimum_fixture.php', $action, $database, (string)(100 + $index)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $children[] = [$process, $pipes];
            $ready = trim((string)fgets($pipes[1]));
            $assert(preg_match('/^READY (\d+)$/', $ready, $match) === 1, 'Independent worker must establish its snapshot');
            $connections[] = (int)$match[1];
        }
        $pdo->exec("UPDATE users SET status = 'active' WHERE user_id IN (100, 101)");
        $pdo->beginTransaction();
        (new App\Authorization\AdministratorAccessMinimum($pdo))->lock();
        foreach ($children as [$process, $pipes]) {
            fwrite($pipes[0], "go\n"); fflush($pipes[0]);
            $assert(trim((string)fgets($pipes[1])) === 'STARTED', 'Both workers must start while the mutex is held');
        }
        $assert($connections[0] !== $connections[1], 'Workers must use independent connections');
        $waiting = 0;
        $waitingQuery = $pdo->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST
            WHERE COMMAND = 'Query' AND INFO = ? AND ID IN (" . implode(',', $connections) . ')');
        for ($attempt = 0; $attempt < 200 && $waiting < 2; $attempt++) {
            // The parent still owns this row lock, so both executing reads are blocked.
            $waitingQuery->execute(["SELECT role_id FROM roles WHERE role_name = 'admin' FOR UPDATE"]);
            $waiting = (int)$waitingQuery->fetchColumn();
            if ($waiting < 2) usleep(25000);
        }
        $assert($waiting === 2, 'Both mutations must overlap while waiting for the common mutex: ' . json_encode($pdo->query(
            'SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID IN (' . implode(',', $connections) . ')')->fetchAll(PDO::FETCH_ASSOC)));
        $pdo->commit();
        $outcomes = [];
        foreach ($children as [$process, $pipes]) {
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $assert(proc_close($process) === 0, $errors . $output);
            $outcomes[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        $children = [];
        foreach ([$first, $second] as $index => $action) {
            if ($action !== 'dormancy') continue;
            $visited = array_merge($outcomes[$index]['disabled'], $outcomes[$index]['skipped_last_admin']);
            $assert($visited === [100 + $index], 'Each dormancy worker must act on its distinct snapshot target');
        }
        $state = json_decode($command('administrator_minimum_fixture.php', 'state'), true, 512, JSON_THROW_ON_ERROR);
        $qualifying = array_filter($state['users'], static fn(array $user): bool => $user['status'] === 'active' && in_array('admin', $user['roles'], true));
        $assert(count($qualifying) === 1, "$first/$second must persist one ordinary Administrator: " . json_encode($outcomes));
        $removals = array_filter($state['audit'], static function (array $audit): bool {
            $before = json_decode($audit['previous_value'], true);
            return ($before['has_admin'] ?? false) || in_array('admin', $before['roles'] ?? [], true);
        });
        $assert(count($removals) === 1, "$first/$second must audit only one committed Administrator removal");
        echo "$first/$second: passed\n";
    }
} finally {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    foreach ($children as [$process, $pipes]) { if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); } }
    $command('theme_fixture.php', 'cleanup');
}
echo "Administrator minimum MySQL: passed\n";
