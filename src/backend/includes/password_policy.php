<?php
// Unified password policy (ticket #36): single source of truth for every
// staff password entry point (creation, change, email reset, legacy
// management) and the offline Recovery Account procedure.
//
// The minimum-length configuration can raise the bar but never lower it
// below 8: password_minimum_length() clamps any PASSWORD_MIN_LENGTH value
// with a floor of 8. Enforcement applies at creation/change/reset events
// only; stored hashes stay valid (verified via password_verify on login,
// never re-validated against this policy), so there is no force-reset.
// Client hint copy and minlength attributes state the default unified 8;
// if the configured minimum is ever raised above 8, update them to match.

if (!function_exists('password_minimum_length')) {
    function password_minimum_length(): int
    {
        $raw = null;
        if (function_exists('env')) {
            $raw = env('PASSWORD_MIN_LENGTH', null);
        } else {
            $raw = $_ENV['PASSWORD_MIN_LENGTH']
                ?? $_SERVER['PASSWORD_MIN_LENGTH']
                ?? getenv('PASSWORD_MIN_LENGTH');
            if ($raw === false || $raw === '') {
                $raw = null;
            }
        }

        return max(8, (int)($raw ?? 8));
    }
}

if (!function_exists('password_meets_complexity')) {
    function password_meets_complexity(string $password): bool
    {
        return (bool)preg_match('/[A-Z]/', $password)
            && (bool)preg_match('/[a-z]/', $password)
            && (bool)preg_match('/\d/', $password);
    }
}

if (!function_exists('password_policy_error')) {
    function password_policy_error(string $password): ?string
    {
        $minimum = password_minimum_length();
        if (strlen($password) < $minimum) {
            return "Password must contain at least {$minimum} characters.";
        }
        if (!password_meets_complexity($password)) {
            return 'Password must include uppercase, lowercase, and numeric characters.';
        }
        return null;
    }
}

if (!function_exists('recovery_password_policy_error')) {
    function recovery_password_policy_error(string $password): ?string
    {
        // Same 8-character plus complexity rule as standard staff; only the
        // message names the Recovery Account for the offline procedure.
        $minimum = password_minimum_length();
        if (strlen($password) < $minimum || !password_meets_complexity($password)) {
            return "Recovery Account login passwords require at least {$minimum} characters with uppercase, lowercase, and numeric characters.";
        }
        return null;
    }
}
