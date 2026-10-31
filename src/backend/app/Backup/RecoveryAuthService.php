<?php

namespace App\Backup;

use DomainException;
use PDO;
use RuntimeException;

/**
 * Pre-restore credentials, operation-bound recovery authentication, and role evidence.
 * Renders and authenticates independently of database tables (#311).
 */
final class RecoveryAuthService
{
    public const SESSION_TIMEOUT_SECONDS = 900;

    /**
     * Durably capture authorized operator credentials and plan metadata before destructive start.
     */
    public static function preparePreRestoreState(
        PDO $pdo,
        string $operationId,
        int $initiatorId,
        string $initiatorRole,
        #[\SensitiveParameter] string $initiatorPassword,
        array $reconciliationApproval,
        array $safetyMetadata
    ): array {
        if (!in_array($initiatorRole, ['admin', 'super_admin'], true)) {
            throw new DomainException('Only an authorized administrator may initiate recovery preparation.');
        }

        if (empty($reconciliationApproval['package_id']) || ($reconciliationApproval['stage'] ?? '') !== 'reconciliation_approved') {
            throw new DomainException('Invalid or stale account review. Review the backup again before restoring.');
        }

        // Validate initiator credentials against current live database
        $initiatorStmt = $pdo->prepare(
            "SELECT u.user_id, u.username, u.password_hash, u.status, u.is_recovery_account
             FROM users u WHERE u.user_id = ?"
        );
        $initiatorStmt->execute([$initiatorId]);
        $initiator = $initiatorStmt->fetch(PDO::FETCH_ASSOC);

        if (!$initiator || $initiator['status'] !== 'active' || !empty($initiator['is_recovery_account'])) {
            throw new DomainException('Initiating administrator is unavailable or inactive.');
        }
        if ($initiatorPassword === '' || !password_verify($initiatorPassword, (string)$initiator['password_hash'])) {
            throw new DomainException('Your current destination login password is incorrect.');
        }

        // Collect all active destination Administrators and Super Administrators (excluding sealed recovery accounts)
        $authorizedUsersStmt = $pdo->query(
            "SELECT u.user_id, u.username, u.password_hash,
                    MAX(CASE WHEN r.role_name = 'super_admin' OR secondary_role.role_name = 'super_admin' THEN 1 ELSE 0 END) as has_super_admin,
                    MAX(CASE WHEN r.role_name = 'admin' OR secondary_role.role_name = 'admin' THEN 1 ELSE 0 END) as has_admin
             FROM users u
             LEFT JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN user_roles ur ON ur.user_id = u.user_id
             LEFT JOIN roles secondary_role ON secondary_role.role_id = ur.role_id
             WHERE (r.role_name IN ('admin', 'super_admin') OR secondary_role.role_name IN ('admin', 'super_admin'))
               AND u.status = 'active'
               AND u.is_recovery_account = 0
               AND NOT EXISTS (SELECT 1 FROM recovery_accounts ra WHERE ra.user_id = u.user_id)
             GROUP BY u.user_id, u.username, u.password_hash"
        );
        $authorizedUsers = $authorizedUsersStmt->fetchAll(PDO::FETCH_ASSOC);

        $operators = [];
        $operators[] = [
            'user_id' => (int)$initiator['user_id'],
            'username' => (string)$initiator['username'],
            'role' => $initiatorRole,
            'password_hash' => (string)$initiator['password_hash'],
            'is_initiator' => true,
        ];

        foreach ($authorizedUsers as $au) {
            $auId = (int)$au['user_id'];
            if ($auId === $initiatorId) {
                continue;
            }
            $role = !empty($au['has_super_admin']) ? 'super_admin' : 'admin';
            $operators[] = [
                'user_id' => $auId,
                'username' => (string)$au['username'],
                'role' => $role,
                'password_hash' => (string)$au['password_hash'],
                'is_initiator' => false,
            ];
        }

        $sessionEpoch = bin2hex(random_bytes(32));
        $now = gmdate('c');

        $state = [
            'operation_id' => $operationId,
            'package_id' => (string)($reconciliationApproval['package_id'] ?? ''),
            'plan_id' => (string)($reconciliationApproval['approval_id'] ?? $reconciliationApproval['approval_hash'] ?? hash('sha256', json_encode($reconciliationApproval['decisions'] ?? []))),
            'destination_watermark' => (string)($reconciliationApproval['destination_watermark'] ?? ''),
            'safety_operation_id' => (string)($safetyMetadata['operation_id'] ?? ''),
            'safety_metadata' => array_intersect_key($safetyMetadata, array_flip([
                'operation_id', 'stage', 'requested_by', 'requested_by_role', 'package_id',
                'origin_id', 'schema_hash', 'sha256', 'sql_sha256', 'file_sha256', 'size', 'filename'
            ])),
            'actor_id' => $initiatorId,
            'actor_role' => $initiatorRole,
            'authorized_operators' => $operators,
            'stage' => RecoveryStore::STAGE_SAFETY_VERIFIED,
            'stages' => [
                ['stage' => RecoveryStore::STAGE_SAFETY_VERIFIED, 'time' => $now, 'actor_id' => $initiatorId]
            ],
            'replacement_started' => false,
            'session_epoch' => $sessionEpoch,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        RecoveryStore::recordOperation($state);
        return $state;
    }

    /**
     * Authenticate an authorized operator against retained durable hashes.
     * ZERO live database queries.
     */
    public static function authenticate(
        string $username,
        #[\SensitiveParameter] string $password,
        string $clientIp,
        ?string $operationId = null
    ): array {
        if (!RecoveryStore::isPaused()) {
            throw new DomainException('No recovery operation is active.');
        }

        $state = RecoveryStore::state();
        if ($operationId !== null && $operationId !== ($state['operation_id'] ?? '')) {
            throw new DomainException('Recovery operation mismatch.');
        }

        $throttle = RecoveryStore::checkThrottle($clientIp);
        if ($throttle['throttled']) {
            throw new DomainException('Too many failed recovery login attempts. Please wait ' . $throttle['retry_after'] . ' seconds.');
        }

        $matched = null;
        foreach ($state['authorized_operators'] ?? [] as $operator) {
            if (strcasecmp((string)$operator['username'], trim($username)) === 0) {
                $matched = $operator;
                break;
            }
        }

        if (!$matched) {
            RecoveryStore::recordThrottleFailure($clientIp);
            throw new DomainException('Invalid recovery credentials or operator not authorized for this operation.');
        }

        if ($password === '' || !password_verify($password, (string)$matched['password_hash'])) {
            RecoveryStore::recordThrottleFailure($clientIp);
            throw new DomainException('Invalid recovery credentials or operator not authorized for this operation.');
        }

        RecoveryStore::clearThrottle($clientIp);

        return [
            'user_id' => (int)$matched['user_id'],
            'username' => (string)$matched['username'],
            'role' => (string)$matched['role'],
            'is_initiator' => (bool)($matched['is_initiator'] ?? false),
            'operation_id' => (string)$state['operation_id'],
        ];
    }

    public static function startRecoverySession(array $authData): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!headers_sent()) {
                @session_start();
            }
        }

        $csrf = bin2hex(random_bytes(16));
        $_SESSION['recovery_auth'] = [
            'user_id' => (int)$authData['user_id'],
            'username' => (string)$authData['username'],
            'role' => (string)$authData['role'],
            'is_initiator' => (bool)($authData['is_initiator'] ?? false),
            'operation_id' => (string)$authData['operation_id'],
            'authenticated_at' => time(),
            'last_activity' => time(),
            'csrf_token' => $csrf,
        ];
        $_SESSION['_restore_epoch'] = RecoveryStore::epoch();

        return $csrf;
    }

    public static function currentRecoverySession(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!headers_sent()) {
                @session_start();
            }
        }

        $session = $_SESSION['recovery_auth'] ?? null;
        if (!is_array($session) || empty($session['operation_id'])) {
            return null;
        }

        if (!RecoveryStore::isPaused()) {
            self::clearRecoverySession();
            return null;
        }

        $state = RecoveryStore::state();
        if (($session['operation_id'] ?? '') !== ($state['operation_id'] ?? '')) {
            self::clearRecoverySession();
            return null;
        }

        $now = time();
        if (($now - (int)($session['last_activity'] ?? 0)) > self::SESSION_TIMEOUT_SECONDS) {
            self::clearRecoverySession();
            return null;
        }

        $_SESSION['recovery_auth']['last_activity'] = $now;
        return $_SESSION['recovery_auth'];
    }

    public static function verifyCsrf(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $session = $_SESSION['recovery_auth'] ?? null;
        $expected = is_array($session) ? (string)($session['csrf_token'] ?? '') : '';
        return hash_equals($expected, $token);
    }

    public static function clearRecoverySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['recovery_auth']);
        }
    }

    public static function recoveryViewData(?array $recoverySession): array
    {
        if (!RecoveryStore::isPaused()) {
            return [
                'paused' => false,
                'stage' => 'idle',
                'workflow_stage' => 'idle',
                'workflow_stage_label' => 'Store Online',
                'next_permitted_action' => 'Create backup or request identity review for incoming package.',
                'message' => 'No database recovery operation is active.',
            ];
        }

        $state = RecoveryStore::state();
        $rawStage = (string)($state['stage'] ?? '');
        $guidance = StoreReopening::guidance($state);
        $guidanceStage = $guidance['stage'];
        $guidanceLabel = $guidance['stage_label'];
        $nextAction = $guidance['next_permitted_action'];

        if (!$recoverySession) {
            return [
                'paused' => true,
                'requires_auth' => true,
                'stage' => $rawStage ?: 'unknown',
                'workflow_stage' => $guidanceStage,
                'workflow_stage_label' => $guidanceLabel,
                'next_permitted_action' => $nextAction,
                'operation_id' => substr((string)($state['operation_id'] ?? ''), 0, 8) . '...',
                'message' => 'Database recovery is in progress. Store access is paused.',
            ];
        }

        $isSuper = ($recoverySession['role'] ?? '') === 'super_admin';
        $view = [
            'authenticated' => true,
            'user_id' => (int)$recoverySession['user_id'],
            'username' => (string)$recoverySession['username'],
            'role' => (string)$recoverySession['role'],
            'is_initiator' => (bool)($recoverySession['is_initiator'] ?? false),
            'operation_id' => (string)($state['operation_id'] ?? ''),
            'package_id' => (string)($state['package_id'] ?? ''),
            'stage' => $rawStage,
            'workflow_stage' => $guidanceStage,
            'workflow_stage_label' => $guidanceLabel,
            'next_permitted_action' => $nextAction,
            'stages' => $state['stages'] ?? [],
            'safety_operation_id' => (string)($state['safety_operation_id'] ?? ''),
            'replacement_started' => (bool)($state['replacement_started'] ?? false),
            'review_checks' => $state['review_checks'] ?? [],
            'message' => 'Database recovery in progress. Stage: ' . $rawStage,
        ];

        if ($isSuper) {
            $view['destination_watermark'] = (string)($state['destination_watermark'] ?? '');
            $view['safety_metadata'] = $state['safety_metadata'] ?? [];
            $view['append_log'] = self::appendLog();
        }

        return $view;
    }

    /** @return list<array> */
    public static function appendLog(): array
    {
        return RecoveryStore::readLog();
    }
}
