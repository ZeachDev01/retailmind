<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/csrf.php';

function role_capability_policy(): \App\Authorization\RoleCapabilityPolicy { return new \App\Authorization\RoleCapabilityPolicy(); }
function app_url(string $path): string { return '/' . $path; }

$pdo = new PDO('sqlite::memory:');
$service = new \App\Backup\DatabaseBackupService($pdo, role_capability_policy());
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$_SESSION = [];
ob_start();
require __DIR__ . '/../../frontend/components/backup/restore_form.php';
$html = ob_get_clean();
$document = new DOMDocument();
@$document->loadHTML($html);
$xpath = new DOMXPath($document);
if ($xpath->query('//input[@name="backup_passphrase"]')->length !== 0 || str_contains($html, 'Older backups')) {
    throw new RuntimeException('Restore offers current key-based backups only.');
}
if ($xpath->query('//input[@name="backup_file" and @required]')->length !== 1
    || $xpath->query('//input[@name="restore_password" and @required]')->length !== 1) {
    throw new RuntimeException('Restore still requires a package and current login password.');
}
echo "Backup restore form: passed\n";
