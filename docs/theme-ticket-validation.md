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

## #124 — Administrator and Super Administrator workflows

Confirmed #122 closed. Audited nine Administrator routes (dashboard, Store
Settings, Registers, Cashier Shift/Stock Issue oversight, Database Backup, Fiscal
Periods, Store Staff, Operational Audit) and ten Super Administrator routes
(Control Center, Platform Settings, Users & Access, Protected Audit Records,
Backup & Restore, Database Updates, Emergency Access, Recovery Account, System
Health, ML Settings). Shared account/gate, receipt, report and routine inventory
surfaces retain the sibling-slice coverage documented above.

Browser checks exposed Database Updates inheriting light text on its standalone
white card in Dark (1.18:1 contrast). Its surfaces/status text now use existing
theme variables. Account dialogs obscured the outer appearance control; they now
contain shared controls. Add Store Staff uses the selected theme's existing
surface/text colors instead of a permanently dark card.

Expanded the disposable real-browser/application/MySQL suite across all nineteen
routes: representative Light/Dark text, inputs, labels, tables/status indicators,
navigation and reload persistence; accessible unobscured controls/focus at 320,
390 and 1280 px;
settings drafts; both account dialogs at all three widths with live selection and
unchanged drafts. Fixture snapshots verify no change to staff credentials/roles,
Registers, Store/Platform/ML/attention settings or Fiscal Periods. Direct forbidden
screen requests still return 403. Existing browser checks cover menus, honest save
failure Operator Alerts, live System changes and chart redraw.

Validation: both theme browser suites passed with `RUN_DB_TESTS=1`; no enabled
browser/database check skipped. Changed PHP/JS syntax, CSS balance (33 files),
role capability, backup routes/unavailable navigation, user lifecycle, Register
administration, Super Administrator workspace and whitespace checks passed.
Unavailable-hosting appearance was inspected with its existing navigation
contract; the browser ran the normal local recovery-storage path. No production
data touched. Parent #121's broader regression evidence and pre-existing route
label failure/optional legacy DB skips remain applicable.

Review against `57491bb`: Standards found zero documented violations and one
optional repeated browser geometry-check smell; kept the small direct assertions.
Spec found zero missing, incorrect or unrequested behaviors. Contrast assertions
sample representative selector categories, not every text node.

## #125 — Inventory Manager workflows

Confirmed #122 closed. Audited fifteen reachable Inventory Manager routes:
Inventory Overview/Insights, Products, CSV Import, Reorder Planner, Replenishment
Requests, Stock Issues, Suppliers, Inventory Counts, Stock Receiving, Purchase
Orders, Demand Forecast, Forecast Exceptions, Data Readiness and Forecast
Analytics. Barcode on-screen/paper paths retain existing browser coverage.
Promotions remains Administrator-only; its Inventory Manager request returns 403.

Products had permanently white inline toolbar/filter panels and light menu/tab
colors. They now use existing palette variables with their original Light
fallbacks. Shared overlay opening now places a synchronized appearance control
inside modal/drawer headers, including dynamically created purchasing confirmations.
The existing theme save handler delegates changes so newly opened controls work;
closed overlay controls are hidden. No new dependencies or permissions.

Disposable real-browser/application/MySQL checks cover all fifteen routes in
Light/Dark, navigation/reload persistence, representative text/input/table/status
contrast and accessible unobscured controls at 320, 390 and 1280 px. Added product
menu/drawer/wizard checks, live System transitions in an open wizard with its draft
retained, dynamic Purchase Order cancellation confirmation (dismissed), purchasing
supplier/request selections and notes, and pending Stock Issue decision notes.
Receiving/count barcode scans at 390 and 1280 px retain product/quantity/notes
through live System updates; open-wizard input colors and chart pixels change with
the device appearance. Existing Operator Alerts, device overrides,
account isolation, account gates and report paper checks pass alongside them.
Database snapshots confirm products, inventory, Supplier Product Terms, purchasing,
Stock Issues, counts, receiving, promotions and Demand Forecast records unchanged.
Camera hardware was not exercised; barcode input uses keyboard scanner events.

Validation: account browser suite with RUN_DB_TESTS=1 and login browser suite passed;
no enabled browser/database checks skipped. Changed PHP/JS syntax, CSS balance
(33 files), inventory counts scan, Stock Issue, Stock Issue correction, role
capability, Operator Alert UI and whitespace checks passed. Contract fixtures use
temporary recovery storage. Parent #121's full regression evidence and pre-existing
route-label failure/optional legacy DB skips remain applicable. Contrast checks
sample representative selector categories, not every text node.

Review against f22ef0b: Standards found zero documented violations or actionable
smells (small shared-overlay theme coupling remains reasonable). Spec identified
partial mobile scanning/live System rendering evidence; the added external
rendering and preserved scan/draft assertions resolve that validation gap.
