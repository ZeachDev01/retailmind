<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../bootstrap/app.php';

try {
    $pdo = App\Core\Database::connection();
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database !== ($argv[1] ?? '') || preg_match('/demo|kaggle/i', $database)) {
        throw new RuntimeException('Store history export requires the selected Store database.');
    }
    if ($pdo->query("SELECT 1 FROM platform_settings WHERE setting_key = 'demo_import_metadata'")->fetchColumn()) {
        throw new RuntimeException('Demo history cannot be exported as Store evidence.');
    }
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $throughDate = $argv[2] ?? '';
    $through = DateTimeImmutable::createFromFormat('!Y-m-d', $throughDate);
    if (!$through || $through->format('Y-m-d') !== $throughDate || $throughDate >= $today->format('Y-m-d')) {
        throw new RuntimeException('Demand export requires a completed Philippine calendar day.');
    }
    $pdo->beginTransaction();
    $reader = new App\Forecasting\DemandHistoryService();
    $history = ($argv[3] ?? '') === '--training'
        ? $reader->getTrainingHistory($pdo, $throughDate) : $reader->getObservedHistory($pdo, $throughDate);
    $pdo->commit();
    echo json_encode($history, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
