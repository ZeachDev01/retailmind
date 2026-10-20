<?php
// Run the real daily entrypoint against an isolated database and controlled clock.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$database = $argv[1] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('Invalid disposable database name.');
}
require_once __DIR__ . '/../../bootstrap/app.php';
$config = require __DIR__ . '/../../config/database.php';
$config['database'] = $database;
$pdo = App\Core\Database::connection($config);
$_ENV['MAIL_MAILER'] = 'disabled';
$clock = new class($argv[2]) implements App\Attention\Clock {
    public function __construct(private string $instant) {}
    public function now(): DateTimeImmutable { return new DateTimeImmutable($this->instant); }
};
$pdo->exec('SET timestamp = ' . $clock->now()->getTimestamp());
require __DIR__ . '/../../scripts/run_notifications.php';
