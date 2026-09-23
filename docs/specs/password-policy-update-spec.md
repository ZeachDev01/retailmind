## Problem Statement

Users currently experience password policy requirements that vary depending on account type (10 characters for standard vs 12 for recovery), and lack immediate feedback regarding these requirements. This inconsistency causes confusion and frustration when password changes or registrations fail on the server side after submission.

## Solution

Unify the password minimum length requirement to a consistent 8 characters across all account types (Standard and Recovery) while maintaining current complexity requirements (uppercase, lowercase, numeric digits). Additionally, introduce basic client-side HTML5 validation to provide immediate UI feedback before form submission.

## User Stories

1. As a regular staff user, I want to create an account with a password of at least 8 characters, so that I can securely manage my access with a password length that meets my personal preference.
2. As a staff user, I want to receive immediate feedback if my password is shorter than 8 characters when creating or changing a password, so that I can correct it before submitting the form.
3. As a recovery administrator, I want to set a recovery account password of 8 characters, so that I can follow a unified security policy across RetailMind.
4. As a user, I want to ensure my password still requires both letters and numbers, so that I maintain the security integrity of my account.
5. As an organization, I want a consistent password policy across all account types, so that our security posture is predictable and easy to manage.

## Implementation Decisions

- **Backend Validation (`src/backend/includes/auth.php`)**: Update the `password_policy_error` function to enforce a strict minimum length of 8 characters regardless of environment configuration.
- **Backend Validation (`src/backend/scripts/recovery_account.php`)**: Update `recovery_password_hash` to enforce a minimum length of 8 characters, matching the standard account policy.
- **Frontend Validation**:
  - Update `change_password.php`, `add_user_modal.php`, and `manage_user_modal.php` to include appropriate `minlength="8"` attributes on password input fields.
  - Optional: Improve client-side JavaScript or HTML5 `pattern` validation to ensure uppercase, lowercase, and numeric character presence before submission for enhanced UX.
- **Configuration**: The `PASSWORD_MIN_LENGTH` environment variable will be deprecated or constrained to a minimum of 8 if it remains for future-proofing.

## Testing Decisions

- **Seam**: The primary seam for automated verification is `password_policy_error()` in `auth.php` and `recovery_password_hash()` in `recovery_account.php`.
- **Test Strategy**:
  - Update or add unit tests in `src/backend/tests/` to verify that passwords of length 7 are rejected and passwords of length 8 with correct complexity are accepted for both standard and recovery scenarios.
  - Prior art: See `src/backend/tests/recovery_account_lifecycle_test.php` for existing test patterns.

## Out of Scope

- Implementing multi-factor authentication (MFA).
- Enforcing password rotation schedules.
- Changing password hashing algorithms.
- Enforcing special character requirements (these remain optional).

## Further Notes

- The security policy update ensures that existing hashed passwords in the database remain valid without requiring a force-reset, as enforcement is applied on re-authentication or password-change events.
