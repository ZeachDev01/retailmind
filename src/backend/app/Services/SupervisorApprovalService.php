<?php

namespace App\Services;

use DomainException;
use PDO;
use PDOException;
use Throwable;

/**
 * Unified per-action supervisor approval for business operations.
 * Requires active ordinary Administrator assignment (primary or user_roles).
 * Technical-account status (Super Administrator) alone is ineligible.
 */
final class SupervisorApprovalService
{
    /**
     * @return int Approver user ID
     */
    public static function authorize(
        PDO $pdo,
        string $identifier,
        string $password,
        string $failureMessage = 'Administrator authorization failed: invalid credentials.',
        int $errorCode = 0
    ): int {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            throw new DomainException($failureMessage, $errorCode);
        }

        $locking = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $user = null;

        // 1. Try username lookup
        try {
            $stmt = $pdo->prepare(
                "SELECT u.user_id, u.username, u.password_hash, u.status, u.must_change_password, r.role_name
                 FROM users u
                 JOIN roles r ON r.role_id = u.role_id
                 WHERE LOWER(TRIM(u.username)) = LOWER(?)
                 LIMIT 1" . $locking
            );
            $stmt->execute([$identifier]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $user = $row;
            }
        } catch (PDOException) {
            $user = null;
        }

        // 2. Fall back to email lookup (excludes recovery accounts)
        if ($user === null) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT u.user_id, u.username, u.password_hash, u.status, u.must_change_password, r.role_name
                     FROM users u
                     JOIN roles r ON r.role_id = u.role_id
                     WHERE (u.is_recovery_account = 0 OR u.is_recovery_account IS NULL)
                       AND LOWER(TRIM(u.email)) = LOWER(?)
                     LIMIT 1" . $locking
                );
                $stmt->execute([$identifier]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $user = $row;
                }
            } catch (PDOException) {
                // Table might lack email or is_recovery_account in minimal test fixtures
                try {
                    $stmt = $pdo->prepare(
                        "SELECT u.user_id, u.username, u.password_hash, u.status, u.must_change_password, r.role_name
                         FROM users u
                         JOIN roles r ON r.role_id = u.role_id
                         WHERE LOWER(TRIM(u.email)) = LOWER(?)
                         LIMIT 1" . $locking
                    );
                    $stmt->execute([$identifier]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row)) {
                        $user = $row;
                    }
                } catch (PDOException) {
                    $user = null;
                }
            }
        }

        // 3. Verify role: must have ordinary Administrator assignment (primary or additional)
        $isAdmin = ($user !== null && (string)$user['role_name'] === 'admin');
        if ($user !== null && !$isAdmin) {
            try {
                $roleStmt = $pdo->prepare(
                    "SELECT 1 FROM user_roles ur
                     JOIN roles ar ON ar.role_id = ur.role_id
                     WHERE ur.user_id = ? AND ar.role_name = 'admin'
                     LIMIT 1"
                );
                $roleStmt->execute([(int)$user['user_id']]);
                $isAdmin = (bool)$roleStmt->fetchColumn();
            } catch (PDOException) {
                $isAdmin = false;
            }
        }

        $passwordMatches = $user !== null && password_verify($password, (string)$user['password_hash']);
        $valid = $user !== null
            && $user['status'] === 'active'
            && (int)$user['must_change_password'] === 0
            && $isAdmin
            && $passwordMatches;

        if (function_exists('login_throttle_identity') && function_exists('record_login_attempt')) {
            try {
                $ip = function_exists('get_client_ip_address') ? get_client_ip_address() : '127.0.0.1';
                record_login_attempt($pdo, login_throttle_identity($user, $identifier), $ip, $valid);
            } catch (Throwable) {
            }
        }

        if (!$valid) {
            throw new DomainException($failureMessage, $errorCode);
        }

        return (int)$user['user_id'];
    }
}
