# Staff login policy

Agreed on 2026-10-14. Policy recorded; login behavior has not been changed.

## Agreed rules

- Sign staff out after 1 hour without deliberate staff activity.
- Sign staff out 12 hours after signing in, even if they are still working.
- Automatic update checks (background polling) do not extend either limit.
- When a login expires, leave the Cashier Shift open. Its owner must sign in again to continue.
- At staff handover, the outgoing cashier closes their Cashier Shift and signs out. The incoming cashier signs in with their own account and opens their own Cashier Shift.

These rules support a full working shift without leaving an unattended login usable for a whole day. A Cashier Shift and authenticated access are separate concepts.

## Current behavior and implementation scope

The local session lifetime is 7,200 seconds (2 hours). `src/backend/app/Core/Session.php` checks time since the last request and refreshes that timestamp on each session-starting request. It has no absolute login limit. Automatic backup-status checks run every 20 seconds and may therefore keep an unattended session alive; this interaction has not been tested live.

Implementation must enforce both limits on the server, distinguish staff activity from automatic requests, and preserve Cashier Shift ownership and existing logout behavior. Production configuration has not been checked.

## Reference

[OWASP Session Management guidance](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html#automatic-session-expiration) distinguishes inactivity limits from maximum login duration.
