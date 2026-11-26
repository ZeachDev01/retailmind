# Super Administrator concurrent login

Discussion decisions confirmed and implementation authorized on 2026-11-26 Philippine time. Implemented locally; production deployment has not been checked.

## Confirmed decisions

- Apply this rule to the Super Administrator only for now.
- A successful new login automatically ends the account's previous authenticated session, retaining the new login.
- Show a SweetAlert on the previous device explaining the other login and advising an immediate password change if it was not recognized.
- This login replacement differs from the existing administrative Revoke action, which ends all current sessions.
- The warning provides Back to login and Forgot password actions because the previous session is already signed out; it cannot directly use authenticated Change Password.
- Reject the previous session at its next authenticated server request. Show the warning within approximately 30 seconds on an active, online page; offline or suspended pages show it when they resume contact with the server.
- A separate browser or private window counts as a separate login. Ordinary tabs sharing the same authenticated session remain together.
- The user accepted this simple flow after these three details were proposed. No additional verification step was requested in this discussion.

## Warning copy

"Na-log out ka kay naay bag-ong login sa imong account. Kung dili ikaw ang ni-login, usba dayon imong password."

Actions: Back to login; Forgot password. Match the interface language when implementing.

Implemented English interface title: "Signed out after another login". Message: "Your account was signed in on another browser or device. If this was not you, change your password immediately."

## Security trade-off

The warning does not verify who made the new login. Anyone able to successfully authenticate can replace the previous session, including someone with stolen login credentials. Account recovery remains necessary for an unrecognized login; this flow alone does not prevent account takeover or repeated replacement.

## Implementation

- A successful Super Administrator login increments the existing account session version, captures its issued version and records the successful login audit in one database transaction. This prevents a concurrent old-session request from seeing the replacement version before its explanation is available. A failed transaction rolls back and clears any partially established session. Ordinary roles retain concurrent access.
- Successful login audit records include the issued version. A rejected Super Administrator session checks for a later successful login before presenting the replacement warning, distinguishing it from a password change or other revocation alone.
- The session activity endpoint returns HTTP 401 with the replacement reason instead of redirecting a background fetch. The existing client redirects to the public login page, which displays the SweetAlert and provides the two recovery actions.
- The changed session activity script uses a versioned asset URL to avoid replaying a cached older script. Pages already open before the update need a reload to use the new client.

## Prior behavior inspected

- Login previously permitted concurrent sessions for every role and copied the account's session version.
- Existing session-version revocation is enforced on the next authenticated server request.
- Background activity checks run approximately every 30 seconds while browser timers run; the previous invalidation redirect did not guarantee a visible warning from that polling path.
- Password change currently retains its own authenticated session and invalidates other sessions.

References: `src/backend/includes/auth.php`, `src/backend/app/Services/UserLifecycleService.php`, `src/frontend/assets/js/session-activity.js`, `src/frontend/components/auth/change_password.php`, and [staff login policy](../specs/session-policy.md).

## Verification

- TDD: observed the protected-request test fail because the old session remained authenticated; implemented replacement and observed it pass. Then observed the polling test fail with HTTP 302 instead of 401; implemented the unauthorized response and observed it pass. Finally observed the browser test fail because the warning was absent; implemented the client and SweetAlert and observed it pass.
- `node src/backend/tests/superadmin_concurrent_login_http_test.js` covers replacement, new-session access, rejected old-session access, failed-login isolation, ordinary Administrator concurrency and navigation/polling warning reasons.
- `node src/backend/tests/superadmin_concurrent_login_browser_test.js` covers independent browser contexts, shared tabs, automatic polling, desktop/mobile warning bounds, both actions and offline/resume behavior. Recovery checks open the real form without sending email.
- Both tests also passed with `RUN_DB_TESTS=1` using disposable MySQL/MariaDB data created and removed through the existing theme fixture.
- Passed `npm run check`, `npm run check:contracts`, session policy PHP/browser/HTTP tests, password policy/change-mode contracts, login redirects and login CSRF recovery. The view audit reports one existing duplicate `open-register` ID warning in `cashier/shifts.php`.
- `staff_login_identifier_contract.php` fails because its SQLite users fixture lacks `first_name`; reproduced with the `HEAD` authentication source as well as the changed source.
- [TestSprite run](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/1dd593de-293b-46e5-bb1d-ae56a26e8d78/test/bbfa73d3-8ac6-488b-8623-496c59a24b4b): passed, 7/7 steps. Scope: real warning UI and login/recovery navigation on a disposable local fixture. Independent-device replacement is covered by local Playwright.
