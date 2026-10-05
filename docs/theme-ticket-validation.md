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

## #126 — Cashier workflows

Confirmed #122 closed. Audited Dashboard, POS, Product Finder, Cashier Shifts,
Cash Refunds, Stock Issues and My History (sales, refunds, drawer movements,
shifts and sale details). POS quote review and current-cart/Held Sale discard
dialogs concealed the top-bar appearance control; their headers now contain
synchronized shared controls. Closed dialog controls remain hidden. A more
specific muted-span rule overrode the stock-warning pill's semantic text in
Light (4.22:1 contrast); the pill selector now retains its existing warning color.

Real payment review uncovered a pre-existing URL bug: the hidden `action` input
shadowed `form.action`, sending quote requests to an invalid URL. Both JavaScript
callers now read the native action attribute: quote review uses the current page
when absent, and the workspace navigation guard checks its explicit target.
No quote mathematics, payment submission, transaction or authorization rules
changed. Tightened the quote browser's route fixture: it failed before the getter
fix and passed afterward. The workspace regression verifies a named action input
cannot bypass the unfinished-cart decision when a switch is cancelled.

Disposable browser/application/MySQL checks cover all seven screens and history
variants in Light/Dark, navigation/reload persistence, representative headings,
table/form/status contrast and unobscured keyboard-focused controls at 320, 390
and 1280 px. Drafts include drawer amount/note/count, Cash Refund quantity/note,
Stock Issue product scan/selection/quantity/explanation and history dates. POS
checks preserve cart quantity, payment and open quote/discard forms across mode
changes and both live System transitions, including visible rendered input colors.
Unknown-product Operator Alerts remain readable; failed appearance saves remain
visible above an open checkout without losing payment input. Database snapshots
confirm unchanged shifts/locks, Held Sales, sales/refunds, stock and drawer records;
forbidden Administrator and Inventory Manager requests still return 403.

Validation: full account browser suite with `RUN_DB_TESTS=1`, login browser,
reviewed-quote browser, checkout-attempt browser and cart-workspace browser passed.
Cash Refund, Cashier Shift opening/reconciliation/Register Lock, Cashier workspace
authorization, Held Sale shift ownership, My History, Stock Issue and Cashier
Operator Alert contracts passed. Changed JavaScript/PHP syntax, CSS balance
(33 files) and whitespace checks passed. No enabled browser/database checks skipped.
Parent #121's broader regression evidence, pre-existing Super Administrator
route-label failure and optional legacy DB skips remain applicable.

Harness retries exposed delayed landing autofocus racing credential entry, and
account-drawer geometry sampled during its opening transition. Checks now wait
for observable startup focus and completed native animations. Temporary diagnosis
probes were removed. Existing account/gate and receipt-paper coverage coordinates
the sibling scopes. Camera hardware was not exercised; barcode lookup uses
keyboard scanner input. Contrast sampling covers representative solid-background
selectors; the dashboard's gradient hero is outside that helper.

Review against 88666ab: Standards found zero violations and one optional repeated
fixture snapshot-loop smell; kept the direct fixture. Spec's Operator Alert
validation gap is resolved by the added failure-state checks. Both reviewers also
cleared the URL getter/guard corrections with no remaining findings.

## #127 — Sale Receipt and Refund Receipt appearance

Confirmed #122 closed. Native Sale Receipt dialogs made the outer appearance
control and body-level save warning inert and concealed. Their sticky header now
contains the shared synchronized control and existing warning while open; closing
returns the warning to the page. Initial checkout/history dialogs open before
DOMContentLoaded, so warning creation also honors an already-open dialog. Header
wrapping keeps controls and warnings readable at mobile widths. Native modal focus
containment, loading/error handling and manual printing remain intact.

Extended the disposable application/MySQL browser fixture with saved Sale and
Refund Receipts, a legacy sale and another seller/issuing Cashier's records. After
editing live Store/product/Register details, reprints keep their recorded details.
Checks cover Light/Dark/System and both live device transitions at 320, 390 and
1280 px, representative text/action contrast, unobscured labelled controls and
focus, keyboard selection inside the native modal, reload/navigation, failed saves,
close/reopen warning restoration and unchanged receipt contents. Both 58 mm and
80 mm reprints use the active Register's current width. Explicit printing creates
light paper with dark ink, no controls or clipping; navigation never auto-prints.
Database snapshots retain transactions, refunds, stock, shifts, Held Sales and
immutable customer details. Legacy notices and denied receipt reads remain intact.
`THEME_RECEIPTS_ONLY=1` narrows the account browser suite to this acceptance slice;
its normal invocation still exercises every role. Contrast waits for native CSS
transitions; close assertions wait for the native close event.

The current frontend has no standalone receipt PHP route. The obsolete backend
legacy route is blocked by hosting configuration; it was not revived. Current
shell/dialog routes use real application requests; standalone renderer suites
retain short/long Sale and Refund Receipt and 58/80 mm paper coverage.

Validation: full account and scoped receipt browsers with `RUN_DB_TESTS=1`, login browser, all four
Sale/Refund Receipt rendering/dialog/reprint browser suites, receipt attribution
and Cash Refund contracts passed. Disposable Receipt History integration passed
inside the dialog suite. Changed PHP/JS syntax, CSS balance and whitespace checks
passed. Optional Receipt Table contract explicitly skipped because it targets the
configured live database; production data was not changed. Parent #121's broader
regression evidence and pre-existing route-label failure/legacy DB skips apply.

Review against `b0c89ae`: Standards and Spec independently found zero remaining
findings. No receipt storage, transaction rules, permissions or automatic print
behavior changed.

## #128 — Report viewing and light paper

Confirmed #122 closed. Inventoried every current non-receipt explicit print
implementation: Report Generation (Print/Export PDF, all sixteen report catalog
entries), Purchase Orders and standalone Printable Barcode Labels. Also audited
native browser printing of Data Readiness, Forecast Exceptions, Demand Forecasts,
the development Forecast dashboard and Forecast Analytics. Stock Receiving's
operational form/screen remains covered by #125.
Receipt printing remains covered by #127.

Real-browser assertions reproduced Purchase Orders leaking creation/actions and
navigation into paper, plus shared global Search, smart-table controls and
dynamic purchasing confirmation controls. Existing `no-print` markup now marks
Purchase Order and prototype actions. Shared print CSS hides shell tools/overlays,
clears shell padding and prevents table clipping without changing screen paging,
data, label dimensions or report filters. Analytics labels also follow print-media
changes so a print preview stays dark after browser `afterprint` events and returns
to the active screen palette when print media ends.

Expanded the disposable application/MySQL browser suite across eleven representative
report routes: Light/Dark and both live System changes, explicit overrides,
navigation/reload, filter drafts, desktop/mobile control visibility/focus at 320,
390 and 1280 px, representative text/form/table/status contrast and open purchasing
confirmation appearance. Three visible canvas kinds exercise rendered axes and
labels, live redraw and paper/screen restoration. Populated sales, five-unit
Purchase Orders, actual forecasts/training runs, 66 inventory rows and 120 labels
verify values, selected screen pagination, light Dark/System paper, hidden controls,
unclipped tables at A4-like 794 px and multipage browser PDFs. Inventory/purchasing/forecast and
transaction snapshots remain unchanged. Navigation never requests print; explicit
Print requests still call browser print.

Validation: `RUN_DB_TESTS=1 THEME_REPORTS_ONLY=1` scoped account browser passed;
no enabled browser/database check skipped. Changed PHP/JS syntax, CSS balance
(33 files) and whitespace checks passed. Print screenshots inspected for Purchase Orders and Forecast
Analytics. Parent #121 owns final global regression; the previously documented
baseline route-label failure and optional legacy Store DB skips remain separate.

Standards review found zero violations or actionable smells. Spec review found
two remaining development dashboard paper gaps: variant B's product navigation
rail leaked and variant C's 1050 px queue clipped A4 output. The rail is now
screen-only; print layout removes queue minimum widths, wraps cells and retains
all forecast data. B/C routes and rendered A4-width assertions cover both fixes.
Those narrower assertions also reproduced inherited mobile `white-space:nowrap`
clipping Report Generation; print cells now wrap while screen paging remains intact.
