<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

$recoveryUrl = app_url() . '?login=1&recovery=1';
$loginUrl = app_url() . '?login=1';

// Handle GET cancellations or resends
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $getAction = trim((string)($_GET['action'] ?? ''));
    if ($getAction === 'cancel') {
        unset(
            $_SESSION['_recovery_step'],
            $_SESSION['_recovery_identity'],
            $_SESSION['_recovery_masked_email'],
            $_SESSION['_recovery_verified_reset_id'],
            $_SESSION['_recovery_verified_user_id'],
            $_SESSION['_recovery_resend_cooldown'],
            $_SESSION['_recovery_code_expires_at'],
            $_SESSION['_recovery_message'],
            $_SESSION['_recovery_message_class']
        );
        header('Location: ' . $loginUrl, true, 303);
        exit;
    }
    if ($getAction === 'resend') {
        $cooldown = (int)($_SESSION['_recovery_resend_cooldown'] ?? 0);
        if ($cooldown > time()) {
            $wait = $cooldown - time();
            $_SESSION['_recovery_step'] = 'verify_code';
            $_SESSION['_recovery_message'] = "Please wait {$wait} seconds before requesting another code.";
            $_SESSION['_recovery_message_class'] = 'tag-warning';
            header('Location: ' . $recoveryUrl, true, 303);
            exit;
        }
        $_SESSION['_recovery_step'] = 'request';
        unset(
            $_SESSION['_recovery_verified_reset_id'],
            $_SESSION['_recovery_verified_user_id'],
            $_SESSION['_recovery_message']
        );
        header('Location: ' . $recoveryUrl, true, 303);
        exit;
    }
    header('Location: ' . $recoveryUrl, true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = trim((string)($_POST['action'] ?? 'request_code'));

    try {
        if ($action === 'request_code') {
            $identity = trim((string)($_POST['identity'] ?? ''));
            if ($identity === '') {
                throw new InvalidArgumentException('Please enter your username or registered email.');
            }
            if (str_contains($identity, '@') && !filter_var($identity, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Please enter a valid email address format.');
            }

            $stmt = $pdo->prepare("SELECT user_id, display_name, email FROM users WHERE (username = ? OR email = ?) AND status = 'active' AND role_id IS NOT NULL AND is_recovery_account = 0 LIMIT 1");
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new InvalidArgumentException('No active account found with that username or email.');
            }

            $userEmail = trim((string)($user['email'] ?? ''));
            if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('This account does not have a registered email address. Please contact your store administrator.');
            }

            // Enforce 60-second cooldown rate limit
            $recentStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
                FROM password_reset_tokens
                WHERE user_id = ?
                ORDER BY reset_id DESC LIMIT 1");
            $recentStmt->execute([(int)$user['user_id']]);
            $lastAge = $recentStmt->fetchColumn();
            if ($lastAge !== false && (int)$lastAge < 60) {
                $wait = 60 - (int)$lastAge;
                $_SESSION['_recovery_step'] = 'verify_code';
                $_SESSION['_recovery_resend_cooldown'] = time() + $wait;
                throw new InvalidArgumentException("Please wait {$wait} seconds before requesting a new code.");
            }

            // Generate 6-digit numeric verification PIN
            $pin = sprintf('%06d', random_int(100000, 999999));
            $pinFormatted = substr($pin, 0, 3) . ' ' . substr($pin, 3, 3);
            $tokenHash = hash('sha256', $pin);

            // Invalidate prior unconsumed reset tokens for this user
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? OR expires_at < NOW()')->execute([(int)$user['user_id']]);
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))')
                ->execute([(int)$user['user_id'], $tokenHash]);

            $plainBody = "Hello {$user['display_name']},\n\n"
                . "Your RetailMind password reset code is: {$pinFormatted}\n\n"
                . "This code expires in 15 minutes.\n\n"
                . "If you did not request a password reset, you can safely ignore this email.";

            $htmlBody = password_reset_email_html((string)$user['display_name'], $pinFormatted);

            if (!send_email_notification($userEmail, 'RetailMind verification code: ' . $pin, $plainBody, $htmlBody)) {
                throw new RuntimeException('Failed to deliver verification code email. Please check store email settings.');
            }

            $emailParts = explode('@', $userEmail);
            $maskedUser = strlen($emailParts[0]) > 2 ? substr($emailParts[0], 0, 2) . '***' : $emailParts[0] . '***';
            $maskedDomain = $emailParts[1] ?? '';
            $maskedEmail = $maskedUser . '@' . $maskedDomain;

            $_SESSION['_recovery_step'] = 'verify_code';
            $_SESSION['_recovery_identity'] = $identity;
            $_SESSION['_recovery_masked_email'] = $maskedEmail;
            $_SESSION['_recovery_resend_cooldown'] = time() + 60;
            $_SESSION['_recovery_code_expires_at'] = time() + 900;
            $_SESSION['_recovery_message'] = "Verification code sent to {$maskedEmail}. Enter the 6-digit code below.";
            $_SESSION['_recovery_message_class'] = 'tag-success';

        } elseif ($action === 'verify_code') {
            $rawCode = trim((string)($_POST['code'] ?? ''));
            $code = preg_replace('/\D/', '', $rawCode);
            if (strlen($code) !== 6) {
                $_SESSION['_recovery_step'] = 'verify_code';
                throw new InvalidArgumentException('Please enter the complete 6-digit numeric code.');
            }

            $tokenHash = hash('sha256', $code);
            $stmt = $pdo->prepare("SELECT pr.reset_id, pr.user_id, u.display_name, r.role_name
                FROM password_reset_tokens pr
                JOIN users u ON u.user_id = pr.user_id
                JOIN roles r ON r.role_id = u.role_id
                WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= NOW() AND u.is_recovery_account = 0
                LIMIT 1");
            $stmt->execute([$tokenHash]);
            $reset = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reset) {
                $_SESSION['_recovery_step'] = 'verify_code';
                throw new InvalidArgumentException('Invalid or expired 6-digit code. Please check your inbox or request a new code.');
            }

            // Step 2 complete: verified code unlocks password change
            $_SESSION['_recovery_step'] = 'set_password';
            $_SESSION['_recovery_verified_reset_id'] = (int)$reset['reset_id'];
            $_SESSION['_recovery_verified_user_id'] = (int)$reset['user_id'];
            $_SESSION['_recovery_message'] = 'Code verified! Enter your new password below.';
            $_SESSION['_recovery_message_class'] = 'tag-success';

        } elseif ($action === 'set_password') {
            $verifiedResetId = (int)($_SESSION['_recovery_verified_reset_id'] ?? 0);
            $verifiedUserId = (int)($_SESSION['_recovery_verified_user_id'] ?? 0);

            if ($verifiedResetId <= 0 || $verifiedUserId <= 0) {
                $_SESSION['_recovery_step'] = 'request';
                throw new InvalidArgumentException('Your reset session has expired. Please enter your username to request a new code.');
            }

            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['password_confirm'] ?? '');

            if ($password === '') {
                $_SESSION['_recovery_step'] = 'set_password';
                throw new InvalidArgumentException('Please enter a new password.');
            }
            if ($password !== $confirm) {
                $_SESSION['_recovery_step'] = 'set_password';
                throw new InvalidArgumentException('Passwords do not match.');
            }
            if ($policyError = password_policy_error($password)) {
                $_SESSION['_recovery_step'] = 'set_password';
                throw new InvalidArgumentException($policyError);
            }

            $pdo->beginTransaction();
            $consume = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE reset_id = ? AND used_at IS NULL AND expires_at >= NOW()');
            $consume->execute([$verifiedResetId]);
            if ($consume->rowCount() !== 1) {
                throw new DomainException('This reset code has already been used or expired.');
            }

            $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0, session_version = session_version + 1, failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $verifiedUserId]);

            log_activity(
                $pdo,
                $verifiedUserId,
                'Password reset completed via 6-digit verification code',
                'User Access',
                $verifiedUserId,
                null,
                ['method' => 'pin_code', 'sessions_revoked' => true]
            );
            $pdo->commit();

            // Clear all recovery state
            unset(
                $_SESSION['_recovery_step'],
                $_SESSION['_recovery_identity'],
                $_SESSION['_recovery_masked_email'],
                $_SESSION['_recovery_verified_reset_id'],
                $_SESSION['_recovery_verified_user_id'],
                $_SESSION['_recovery_resend_cooldown'],
                $_SESSION['_recovery_code_expires_at']
            );

            $_SESSION['_recovery_message'] = 'Password reset successfully! You can now sign in with your new password.';
            $_SESSION['_recovery_message_class'] = 'tag-success';
            header('Location: ' . $loginUrl, true, 303);
            exit;
        }
    } catch (InvalidArgumentException $invalid) {
        $_SESSION['_recovery_message'] = $invalid->getMessage();
        $_SESSION['_recovery_message_class'] = 'tag-warning';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        \App\Support\OperatorAlert::message($exception, 'The password reset could not be completed.');
        $_SESSION['_recovery_message'] = 'Password reset failed: ' . $exception->getMessage();
        $_SESSION['_recovery_message_class'] = 'tag-warning';
    }
}

header('Location: ' . $recoveryUrl, true, 303);
exit;
