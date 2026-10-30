<?php

// Recovery decisions and their callers must work without a usable database.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require_once __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\{DatabaseBackupService, DatabaseRestoreService, RecoveryAuthService, RecoveryStore, StoreReopening};

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$now = 1800000000;
$state = ['stage' => RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING];
foreach (['stock', 'receipt', 'upload', 'attribution'] as $key) {
    $state['review_checks']['automated'][$key] = ['status' => 'passed', 'checked_at' => gmdate('c', $now)];
    $state['review_checks']['human_confirmations'][$key] = ['confirmed' => true];
}
$check(StoreReopening::readiness($state, $now)['ready'], 'Complete fresh checks permit reopening');
$check(!StoreReopening::readiness(null, $now)['ready'], 'No operation cannot be reopened');
$check(StoreReopening::readiness($state, $now + 900)['ready'], 'Checks remain valid at exactly 15 minutes');
$check(!StoreReopening::readiness($state, $now + 901)['ready'], 'Checks expire after 15 minutes');
$check(StoreReopening::guidance($state, $now + 901)['stage'] === 'verification_needed', 'Expired checks change guidance');

foreach (['missing', 'failed', 'invalid timestamp', 'missing timestamp', 'human unconfirmed'] as $case) {
    $invalid = $state;
    switch ($case) {
        case 'missing':
            unset($invalid['review_checks']['automated']['stock']);
            $invalid['review_checks']['automated']['unrelated'] = ['status' => 'passed', 'checked_at' => gmdate('c', $now)];
            break;
        case 'failed':
            $invalid['review_checks']['automated']['stock']['status'] = 'failed';
            break;
        case 'invalid timestamp':
            $invalid['review_checks']['automated']['stock']['checked_at'] = 'invalid';
            break;
        case 'missing timestamp':
            unset($invalid['review_checks']['automated']['stock']['checked_at']);
            break;
        case 'human unconfirmed':
            $invalid['review_checks']['human_confirmations']['stock']['confirmed'] = false;
    }
    $check(!StoreReopening::readiness($invalid, $now)['ready'], "Rejects {$case}");
    $check(StoreReopening::guidance($invalid, $now)['stage'] === 'verification_needed', "Guidance rejects {$case}");
}

foreach (RecoveryStore::VALID_STAGES as $stage) {
    $staged = array_replace($state, ['stage' => $stage]);
    $expected = match ($stage) {
        RecoveryStore::STAGE_SAFETY_VERIFIED => 'ready_to_restore',
        RecoveryStore::STAGE_RECOVERY_REQUIRED => 'actionable_failure',
        RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING => 'ready_to_reopen',
        RecoveryStore::STAGE_REOPENED_COMPLETED => 'reopened_completed',
        default => $stage,
    };
    $check(StoreReopening::guidance($staged, $now)['stage'] === $expected, "Guidance for {$stage}");
    $check(StoreReopening::readiness($staged, $now)['ready'] === ($stage === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING), "Readiness for {$stage}");
}

$private = sys_get_temp_dir() . '/rm-reopening-test-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
$_ENV['BACKUP_STORAGE_PATH'] = $private;
// An uninitialized PDO throws if any caller attempts database access.
$pdo = new class extends PDO { public function __construct() {} };
$policy = new RoleCapabilityPolicy();
$restore = new DatabaseRestoreService($pdo, $policy, new DatabaseBackupService($pdo, $policy));
$session = ['user_id' => 1, 'username' => 'operator', 'role' => 'admin', 'is_initiator' => true];

try {
    $check(!RecoveryAuthService::recoveryViewData(null)['paused'], 'Inactive view works without database');
    $check(!$restore->validateReopeningReadiness()['ready'], 'Inactive readiness works without database');
    foreach (RecoveryStore::VALID_STAGES as $stage) {
        $durable = array_replace($state, ['stage' => $stage]);
        foreach ($durable['review_checks']['automated'] as &$automated) {
            $automated['checked_at'] = gmdate('c');
        }
        unset($automated);
        RecoveryStore::recordOperation($durable);
        $guidance = $restore->workflowGuidance();
        foreach ([null, $session] as $viewer) {
            $view = RecoveryAuthService::recoveryViewData($viewer);
            $check($view['workflow_stage'] === $guidance['stage'], "Display and guidance agree for {$stage}");
            $check($view['workflow_stage_label'] === $guidance['stage_label'], "Labels agree for {$stage}");
            $check($view['next_permitted_action'] === $guidance['next_permitted_action'], "Actions agree for {$stage}");
            $check(!isset($view['append_log']), 'Ordinary viewers do not receive protected evidence');
        }
    }

    $durable['stage'] = RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING;
    $durable['operation_id'] = 'test-reopening';
    $durable['authorized_operators'] = [['user_id' => 1, 'role' => 'admin', 'password_hash' => password_hash('test-password', PASSWORD_DEFAULT)]];
    foreach ($durable['review_checks']['automated'] as &$automated) {
        $automated['checked_at'] = gmdate('c', time() - 901);
    }
    unset($automated);
    RecoveryStore::recordOperation($durable);
    $check(!$restore->validateReopeningReadiness()['ready'], 'Existing readiness interface refuses expired checks');
    $check($restore->workflowGuidance()['stage'] === 'verification_needed', 'Existing guidance interface refuses expired checks');
    $view = RecoveryAuthService::recoveryViewData($session);
    $check($view['workflow_stage'] === 'verification_needed', 'Recovery display refuses expired checks without database');
    $check($view['review_checks']['human_confirmations'] === $state['review_checks']['human_confirmations'], 'Expiry retains human confirmations');
    try {
        $restore->reopenStore(1, 'admin', 'test-password', 'test-reopening');
        throw new RuntimeException('Expired checks must block enforcement');
    } catch (DomainException $exception) {
        $check(str_contains($exception->getMessage(), 'stale'), 'Enforcement refuses expiry before touching database');
    }
    $check(RecoveryStore::isPaused(), 'Refused reopening keeps Store paused');
    $fresh = $durable;
    foreach ($fresh['review_checks']['automated'] as &$automated) $automated['checked_at'] = gmdate('c');
    unset($automated);
    $check($restore->workflowGuidance($fresh)['stage'] === 'ready_to_reopen', 'Explicit guidance state is evaluated consistently');
} finally {
    foreach (glob($private . '/*') ?: [] as $file) unlink($file);
    if (is_dir($private)) rmdir($private);
}

echo "Store reopening: passed\n";
