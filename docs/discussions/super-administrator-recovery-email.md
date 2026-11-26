# Super Administrator recovery email

Implementation authorized on 2026-11-26 Philippine time after the initial three decisions were confirmed. Implemented locally; live inbox delivery has not been checked.

## Confirmed decisions

- Only the primary Super Administrator may register its own email through the Email field in Manage Account.
- Registration requires the account's current password.
- Verify ownership by sending a code to the proposed email address. Save and permanently lock the address only after successful verification.
- An unverified address may be corrected; entering it or requesting a code does not consume the one-time registration.
- Use the verified account email for the existing Forgot password flow; do not introduce a separate recovery email address.

- Proceeding with the stated defaults after the request to implement: setup is optional, and loss of mailbox access does not unlock ordinary email replacement. Existing offline recovery remains the fallback for account lockout.

## Implementation

- The existing Manage Account drawer enables Email only for the signed-in primary Super Administrator with no registered email. Existing nonempty email addresses and other accounts remain read-only.
- Sending a verification code requires the current password and an unused, valid email address. Email remains unregistered until successful verification. The existing email-delivery helper supplies the branded email and delivery logging; codes are excluded from the logged subject.
- The session holds the proposed address and a hashed six-digit code for 15 minutes. Five verification attempts are allowed per code; requests, including wrong-password attempts and delivery failures, have a 60-second session cooldown. A new code replaces the old challenge.
- The challenge is bound to the current account, password fingerprint and session version. Password changes or session revocation invalidate it. Closing or reloading the drawer requires requesting another code; the cooldown still applies.
- Verification locks the account row and commits the one-time email registration with its Protected Audit Record in one transaction. Uniqueness conflicts and audit failures roll back the registration. General profile updates still cannot alter email.
- No database migration or new dependency is required. See [ADR-0037](../adr/0037-one-time-super-administrator-recovery-email.md).

## Prior behavior inspected

- Manage Account renders Email as read-only, and profile updates do not persist submitted email.
- The seeded primary Super Administrator has no email address. Account email addresses are unique.
- Existing Forgot password accepts an active primary Super Administrator with a valid registered email. It sends an email PIN and revokes sessions after a successful password reset.
- The sealed Recovery Account is separate from ordinary email recovery and remains outside normal account management.

References: `src/frontend/components/user_manager/modals/manage_user_modal.php`, `src/backend/app/Services/UserLifecycleService.php`, `src/backend/database/sql/schema.sql`, `src/frontend/components/auth/forgot_password.php`, and `docs/CONTEXT.md`.

## Verification

- `node src/backend/tests/superadmin_recovery_email_browser_test.js` runs the service checks against the existing disposable MySQL fixture, then exercises the real drawer and recovery handler with a test-only mailbox. Covers password confirmation, code expiry, attempt limit, delivery failure, uniqueness, security changes, atomic audit, permanent locking, authorization, CSRF and Forgot password delivery to the saved address. Checks drawer bounds at 1280, 390 and 320 pixels.
- Passed `npm run check`, `npm run check:contracts` and `php src/backend/tests/recovery_exclusion_reset_audit_contract.php`. The view audit reports the existing duplicate `open-register` ID warning in `cashier/shifts.php`.
- [TestSprite run](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/f378d6ca-20bb-4c6a-b8dc-f10a3a5b529d/test/b45a2156-3253-4b2b-9f4d-828962462708): passed, 18/18 steps, including wrong-code rejection, verification, saved address and permanent read-only state. Used the disposable database and test-only mailbox.
- `user_lifecycle_contract.php` fails because its SQLite fixture lacks `cashier_shifts`; `administrator_welcome_email_contract.php` fails its mail-failure warning assertion. Both failures were reproduced using the corresponding `HEAD` implementation sources, before this change.
- Actual Brevo delivery to a live inbox is unverified; automated checks use a disposable mailbox and do not register an email on the live account.
