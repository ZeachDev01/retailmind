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

## #123 — Staff login isolation and authenticated gates

Confirmed #122 closed. Audited sign-in/logout, account pages, workspace selection,
and Mandatory Password Change/Register Lock against #123. Found the landing login
modal covered the outer appearance control. Added the existing shared control to
both login/recovery faces, hiding the outer control while the modal is open and
the inactive face's control. Theme selection remains inside the accessible modal.
Also reproduced blocked session storage aborting login-modal initialization;
the existing catches now cover storage getters as well as reads/writes.

Added real-browser assertions for login controls/forms at 320, 360, 390, and
1280 px, recovery-face selection and form preservation, fresh-browser sign-in for
all four accounts with distinct Light/Dark/System choices, denied local/session
storage with normal landing login and sign-in/save/reload/logout, Preferences and
Voluntary Password Change form preservation, and desktop/mobile gate focus,
visibility, contrast and invalid-CSRF rejection. Password-gate state, open locked
Cashier Shift, sales and stock remain unchanged. Keyboard checks now wait through
both native and delayed POS startup autofocus.

Validation: both theme browser suites passed with the disposable MySQL fixture
enabled (`RUN_DB_TESTS=1`); no browser/database checks skipped. Changed JavaScript
syntax, CSS balance (33 files), password-change, workspace-switching, Register Lock,
Staff login identifier contracts and whitespace checks passed. Parent #121's
broader regression evidence and its pre-existing route-label failure/optional
legacy DB skips still apply. No new dependencies or gate permissions added.

Review: Standards found no documented violations or actionable smells. Spec found
two validation gaps (distinct saved account choices and denied session storage)
and the session-storage runtime failure; regression checks and the minimal fix
resolve them. Both axes report no remaining findings.
