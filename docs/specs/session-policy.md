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

## Reference

[OWASP Session Management guidance](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html#automatic-session-expiration) distinguishes inactivity limits from maximum login duration.
