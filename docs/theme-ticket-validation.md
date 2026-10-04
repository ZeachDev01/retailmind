# Theme ticket validation

## #122 — Shared account appearance shell

Audited the existing implementation from `e328acd` through `2b8191e` against
#122 and parent #121. Account storage, authenticated CSRF-protected own-account
writes, early appearance application, accessible shared controls, live System
changes, persistence/isolation, workspace switches, and honest save-failure
Operator Alerts satisfy the shared-shell scope. No runtime changes required.

Strengthened the disposable fixture to verify fresh-account System defaults and
System backfill for existing accounts during an idempotent upgrade. Browser checks
now verify unobscured controls at 320, 360, 390, and 800 px for all four roles,
mobile focus, both live System transitions, and explicit Light overriding a dark
device. Keyboard checks wait for the existing POS startup autofocus after reload.

Validation: account browser suite with `RUN_DB_TESTS=1` passed through running PHP
and disposable MySQL; login browser suite passed; changed PHP/JS syntax, CSS
balance (33 files), fresh schema contract, and whitespace checks passed. No theme
browser or DB test skipped. Parent #121 completed the broader regression run;
its pre-existing Super Administrator route-label failure and optional legacy DB
skips remain outside this ticket's changes.

Review: Standards found no hard violation (optional repeated palette literals in
the existing stylesheet); Spec found no missing or incorrect acceptance behavior.
