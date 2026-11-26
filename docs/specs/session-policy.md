# Staff login policy

Agreed on 2026-10-14. Implemented and tested on the local implementation branch; production deployment has not been checked.

## Agreed rules

- Sign staff out after 1 hour without deliberate staff activity.
- Sign staff out 12 hours after signing in, even if they are still working.
- Automatic update checks (background polling) do not extend either limit.
- When a login expires, leave the Cashier Shift open. Its owner must sign in again to continue.
- At staff handover, the outgoing cashier closes their Cashier Shift and signs out. The incoming cashier signs in with their own account and opens their own Cashier Shift.

These rules support a full working shift without leaving an unattended login usable for a whole day. A Cashier Shift and authenticated access are separate concepts.

## Current behavior and implementation scope

The server enforces `SESSION_LIFETIME=3600` seconds of inactivity and `SESSION_ABSOLUTE_LIFETIME=43200` seconds since successful authentication. Both clocks reset only on successful login; deliberate requests and validated browser activity extend only the inactivity clock. Existing authenticated sessions without a login timestamp require fresh authentication. PHP session-file retention covers both configured limits.

Backup-status and scanner polling, navigation prefetch, dashboard automatic refresh, and automatic login-status checks do not extend inactivity. The browser sends a CSRF-protected activity request for real typing, clicks/taps, input, and scrolling, at most once per 15 seconds. It checks the server at least every 30 seconds while timers run and when the tab becomes visible. The server remains authoritative across tabs; an idle tab never calls logout to end another tab's active login.

Expiry destroys authenticated access only. It does not close, reconcile, or release a Cashier Shift or its register. Existing close-shift and staff ownership safeguards are preserved for handover.

Validation: public `Session::start()` expiry/activity tests, activity endpoint HTTP checks (including invalid CSRF and expired renewal), browser typing/polling/expiry checks, and existing cashier shift/logout checks. Browser timers may be delayed when a tab is suspended or the device sleeps; the next server request still enforces expiry before permitting access. Production environment settings have not been checked.

## Cashier device confirmation (2026-11-26)

Staff Accounts assigned the Cashier Workspace have one active browser login, including Multi-role Accounts while using another Workspace. A separate browser or private window counts as a separate login; ordinary tabs sharing its session remain together. The separate Super Administrator replacement policy remains in effect.

After valid credentials on another device, that device waits without authenticated access. The existing login receives an "Is this you?" confirmation through its normal session checks, approximately every thirty seconds on an active online page. Approval ends its access and lets the waiting device authenticate; rejection keeps the existing login. There is one pending request per account, valid for five minutes. Expired or rejected requests require another login attempt.

The existing one-hour inactivity and twelve-hour absolute expiry rules remain authoritative. Activity is recorded on the server; background polling does not renew it. An offline or broken device needs no confirmation once its login expires. Logging out releases ownership immediately. Expiry, logout and transfer leave Cashier Shift and register ownership unchanged. Unsaved browser work is not copied to the other device; finish or hold work before approving. Stock protections still apply across separate cashier accounts.

The database migration creates `cashier_login_sessions`. Account-row locking serializes competing first logins, and protected requests verify ownership. Pending requests contain no saved passwords and require matching session version, active account, CSRF-protected approval and a short-lived random token. Password changes invalidate pending approval while keeping the changing device's ownership. Backup packages omit these transient rows. Cashier sessions issued before rollout must sign in again. No new account-recovery flow is introduced.

Local checks: `cashier_login_service_test.php`, `cashier_login_mysql_test.php`, `cashier_concurrent_login_http_test.js`, `cashier_concurrent_login_browser_test.js`, and existing session/login checks. Local migration applied; production deployment has not been checked.

## Reference

[OWASP Session Management guidance](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html#automatic-session-expiration) distinguishes inactivity limits from maximum login duration.
