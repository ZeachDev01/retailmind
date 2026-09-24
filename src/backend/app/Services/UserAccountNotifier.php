<?php

namespace App\Services;

require_once __DIR__ . '/../../includes/functions.php';

/**
 * Injectable email collaborator for the user lifecycle path.
 *
 * Tests replace this with an anonymous subclass so no test reaches the real
 * mail helper. Empty or missing addresses are skipped quietly.
 */
class UserAccountNotifier
{
    public function disabledNotice(?string $email): bool
    {
        return $this->notify(
            $email,
            'Your account has been disabled',
            'Your RetailMind account has been disabled. You can no longer sign in. Your previous work stays on file. Contact your administrator if you have questions.'
        );
    }

    public function enabledNotice(?string $email): bool
    {
        return $this->notify(
            $email,
            'Your access is active again',
            'Your RetailMind account has been re-enabled. You can sign in to RetailMind again.'
        );
    }

    public function notify(?string $email, string $subject, string $message): bool
    {
        $email = trim((string)($email ?? ''));
        if ($email === '') {
            return false;
        }
        return $this->deliver($email, $subject, $message);
    }

    protected function deliver(string $email, string $subject, string $message): bool
    {
        return send_email_notification($email, $subject, $message);
    }
}
