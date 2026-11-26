<?php
// Service checks reuse the existing disposable MySQL theme fixture.
require_once __DIR__ . '/../bootstrap/app.php';
use App\Services\UserLifecycleService;
use App\Authorization\RoleCapabilityPolicy;
$database = $argv[1] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$service = new UserLifecycleService($pdo, new RoleCapabilityPolicy());
$adminId = 1;
$insertActor = static function (string $name, string $role) use ($pdo): int {
    $insert = $pdo->prepare('INSERT INTO users(first_name,display_name,username,password_hash,role_id,must_change_password) VALUES(?,?,?,?,(SELECT role_id FROM roles WHERE role_name=?),0)');
    $insert->execute([$name,$name,str_replace(' ', '_', strtolower($name)),password_hash('ActorPassword123', PASSWORD_DEFAULT),$role]);
    return (int)$pdo->lastInsertId();
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
    // Reuse the lifecycle fixture for one-time, verified Super Administrator email setup.
    $emailOwnerId = $insertActor('Email Setup', 'super_admin');
    $pdo->prepare('UPDATE users SET email = NULL WHERE user_id = ?')->execute([$emailOwnerId]);
    $challenge = [];
    $capturedCode = '';
    $requestEmail = static function (string $email = 'recovery@example.test', string $password = 'ActorPassword123', bool $delivered = true) use ($service, $emailOwnerId, &$challenge, &$capturedCode): void {
        $service->requestRecoveryEmail($emailOwnerId, 'super_admin', $email, $password, $challenge,
            static function (string $recipient, string $code) use (&$capturedCode, $delivered): bool {
                $capturedCode = $code;
                return $delivered;
            });
    };
    $verifyEmail = static function (string $code) use ($service, $emailOwnerId, &$challenge): array {
        return $service->verifyRecoveryEmail($emailOwnerId, 'super_admin', $code, $challenge);
    };
    $rejectEmail = static function (callable $operation, string $needle) use ($assert): void {
        try { $operation(); $assert(false, 'Email setup must reject: ' . $needle); }
        catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), $needle), 'Expected email setup rejection: ' . $needle . '; got ' . $exception->getMessage());
        }
    };
    $rejectEmail(fn() => $requestEmail(password: 'wrong'), 'Current password');
    $rejectEmail(fn() => $requestEmail(), 'wait 60 seconds');
    $challenge = [];
    $rejectEmail(fn() => $requestEmail('not-an-email'), 'valid email');
    $challenge = [];
    $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?')->execute(['used@example.test', $adminId]);
    $rejectEmail(fn() => $requestEmail('used@example.test'), 'already belongs');
    $challenge = [];
    $rejectEmail(fn() => $requestEmail(delivered: false), 'could not be sent');
    $assert(!isset($challenge['code_hash']) && $service->get($emailOwnerId)['email'] === null, 'Failed delivery cannot register an email or produce a usable code');
    $challenge = [];
    $rejectEmail(function () use ($service, $adminId, &$challenge) {
        $service->requestRecoveryEmail($adminId, 'admin', 'other@example.test', 'ActorPassword123', $challenge, fn() => true);
    }, 'primary Super Administrator');
    $pdo->prepare('UPDATE users SET is_recovery_account = 1 WHERE user_id = ?')->execute([$emailOwnerId]);
    $rejectEmail(fn() => $requestEmail(), 'primary Super Administrator');
    $pdo->prepare('UPDATE users SET is_recovery_account = 0 WHERE user_id = ?')->execute([$emailOwnerId]);
    $requestEmail();
    $assert($service->get($emailOwnerId)['email'] === null && $challenge['code_hash'] !== $capturedCode, 'Request keeps email unregistered and stores only a code hash');
    $rejectEmail(fn() => $requestEmail(), 'wait 60 seconds');
    for ($i = 0; $i < 5; $i++) $rejectEmail(fn() => $verifyEmail('000000'), 'Incorrect verification code');
    $rejectEmail(fn() => $verifyEmail($capturedCode), 'attempt limit');
    $challenge['sent_at'] -= 61;
    $requestEmail('corrected@example.test');
    $challenge['expires_at'] = time() - 1;
    $rejectEmail(fn() => $verifyEmail($capturedCode), 'expired');
    $challenge['sent_at'] -= 61;
    $requestEmail();
    $pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE user_id = ?')->execute([$emailOwnerId]);
    $rejectEmail(fn() => $verifyEmail($capturedCode), 'security changed');
    $challenge['sent_at'] -= 61;
    $requestEmail();
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([password_hash('ChangedPassword123', PASSWORD_DEFAULT), $emailOwnerId]);
    $rejectEmail(fn() => $verifyEmail($capturedCode), 'security changed');
    $challenge['sent_at'] -= 61;
    $requestEmail(password: 'ChangedPassword123');
    $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?')->execute(['recovery@example.test', $adminId]);
    try { $verifyEmail($capturedCode); $assert(false, 'Email claimed during verification must fail'); }
    catch (PDOException) { $assert($service->get($emailOwnerId)['email'] === null, 'Duplicate email failure rolls back registration'); }
    $pdo->prepare('UPDATE users SET email = NULL WHERE user_id = ?')->execute([$adminId]);
    $pdo->exec("CREATE TRIGGER reject_recovery_email_audit BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected audit failure'");
    try { $verifyEmail($capturedCode); $assert(false, 'Registration must fail if its audit cannot be recorded'); }
    catch (PDOException) { $assert($service->get($emailOwnerId)['email'] === null, 'Audit failure rolls back email registration'); }
    $pdo->exec('DROP TRIGGER reject_recovery_email_audit');
    $afterEmail = $verifyEmail($capturedCode);
    $assert($afterEmail['email'] === 'recovery@example.test' && $challenge === [], 'Verification saves email once and consumes the challenge');
    $auditEmail = $pdo->query("SELECT * FROM activity_log WHERE action = 'Super Administrator recovery email verified'")->fetch(PDO::FETCH_ASSOC);
    $assert($auditEmail && $auditEmail['category'] === \App\Audit\AuditRecordCategory::SECURITY
        && !str_contains($auditEmail['new_value'], $capturedCode), 'Registration writes protected audit evidence without the code');
    $rejectEmail(fn() => $verifyEmail($capturedCode), 'Request a new');
    $rejectEmail(fn() => $requestEmail('replacement@example.test', 'ChangedPassword123'), 'cannot be changed');
    $service->update($emailOwnerId, 'super_admin', $emailOwnerId, array_replace($afterEmail, ['email' => 'replacement@example.test']));
    $assert($service->get($emailOwnerId)['email'] === 'recovery@example.test', 'Ordinary profile updates cannot replace the verified email');

echo "Super Administrator recovery email service: passed\n";
