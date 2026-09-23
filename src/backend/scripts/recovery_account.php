<?php
// Offline-only Recovery Account lifecycle command.
// Usage: php src/backend/scripts/recovery_account.php <status|provision|activate|rotate|reseal>
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';

use App\Services\RecoveryAccountService;

function recovery_input(string $name, bool $required = true): ?string
{
    $file = getenv($name . '_FILE');
    if (is_string($file) && $file !== '') {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("{$name}_FILE is not readable.");
        }
        $value = rtrim((string)file_get_contents($file), "\r\n");
    } else {
        $environmentValue = getenv($name);
        $value = is_string($environmentValue) ? $environmentValue : '';
    }

    if ($required && $value === '') {
        throw new InvalidArgumentException("Set {$name}_FILE (preferred) or {$name} before running this command.");
    }
    return $value === '' ? null : $value;
}

function recovery_password_hash(string $environmentName): string
{
    $password = (string)recovery_input($environmentName);
    // Unified Recovery Account policy (ticket #34): fixed 8-character minimum,
    // same complexity as standard staff in password_policy_error() (auth.php).
    // Existing hashes stay valid (verified via password_verify on login,
    // never re-validated against this policy).
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        throw new InvalidArgumentException('Recovery Account login passwords require at least 8 characters with uppercase, lowercase, and numeric characters.');
    }
    return password_hash($password, PASSWORD_DEFAULT);
}

$command = strtolower(trim((string)($argv[1] ?? '')));
$service = new RecoveryAccountService($pdo);

try {
    switch ($command) {
        case 'status':
            $status = $service->publicStatus();
            echo 'Status: ' . $status['status'] . PHP_EOL;
            echo 'Last use: ' . ($status['last_used_at'] ?? 'never') . PHP_EOL;
            break;

        case 'provision':
            $userId = $service->provision(
                (string)recovery_input('RECOVERY_USERNAME'),
                recovery_password_hash('RECOVERY_LOGIN_PASSWORD'),
                (string)recovery_input('RECOVERY_ACTIVATION_SECRET')
            );
            echo "Recovery Account {$userId} provisioned and sealed.\n";
            break;

        case 'activate':
            $service->activate((string)recovery_input('RECOVERY_ACTIVATION_SECRET'));
            echo "Recovery Account activated. Reseal it immediately after recovery.\n";
            break;

        case 'rotate':
            $service->rotateCredentials(
                (string)recovery_input('RECOVERY_ACTIVATION_SECRET'),
                recovery_password_hash('RECOVERY_NEW_LOGIN_PASSWORD'),
                (string)recovery_input('RECOVERY_NEW_ACTIVATION_SECRET')
            );
            echo "Recovery Account credentials rotated. Store the two new credentials separately.\n";
            break;

        case 'reseal':
            $service->reseal((string)recovery_input('RECOVERY_ACTIVATION_SECRET'));
            echo "Recovery Account resealed and sessions revoked.\n";
            break;

        default:
            fwrite(STDERR, "Usage: php src/backend/scripts/recovery_account.php <status|provision|activate|rotate|reseal>\n");
            exit(2);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Recovery Account command failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
