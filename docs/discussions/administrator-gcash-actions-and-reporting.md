# Administrator GCash actions and reporting

Status: accepted, 2026-11-16 Philippine time. User answered yes to Q1–Q3 and confirmed the final scope with “oo padayon”. Implementation is authorized.

## Stated intent

- Remove Record Exchange from Administrator GCash Overview. Customer exchanges belong in the Cashier workspace.
- Remove Export Report from Administrator GCash Overview. Provide GCash reporting through Generate Report.
- Existing glossary distinction applies: Multi-role Accounts enter each Workspace separately; capabilities are never combined. No new domain term is needed.

## Behavior before this change

- Record Exchange opens a workspace-switch dialog for accounts assigned the Cashier workspace; recording requires an open Cashier Shift and GCash session.
- Export Report downloads a CSV using the overview's selected filters.
- Generate Report currently has no GCash report type. Removing the export before adding its replacement would remove report access.

## Accepted decisions

- Q1: Add GCash reporting under Generate Report, preserving the existing CSV details and applicable filters. Access remains limited to Administrator.
- Q2: Remove only Record Exchange and Export Report from the overview header. Keep on-screen Activity report, filters, transactions, balances, and review controls.
- Q3: Permit the replacement report export through the tunnel link under the same Administrator permissions as local access, consistent with the glossary's remote-access policy.
- Prepare the replacement report before retiring the old export entry point. Cashier workspace assignments and transaction permissions remain unchanged.

## Documentation scope

These reversible navigation changes do not warrant a new ADR. Keep existing GCash Live Custody and GCash Activity Scope definitions; if on-screen reporting moves, review ADR-0025 and the Administrator GCash specification for consistency.

## Additional findings

- Administrator status does not automatically grant the Cashier workspace; only assigned workspaces are available. Removing the shortcut does not change assignments.
- Generate Report is also used by other workspaces; the GCash report must enforce its Administrator restriction.
- The initial investigation confused remote monitoring with approved tunnel ingress. Approved tunnel requests are already treated as local by the shared access policy. Q3 preserves that policy for the replacement report; unapproved remote monitoring remains restricted.

## Implementation and verification

Implemented in Generate Report as GCash Transactions, with the overview actions and switch dialog removed. Existing overview monitoring/review controls and transaction posting remain intact. No new glossary term or ADR was needed.

Passed: `npm run check`, `gcash_report_browser_test.js` with `RUN_DB_TESTS=1`, `inventory_manager_report_access_contract.php`, `active_workspace_boundaries_contract.php`, `gross_profit_report_browser_test.js`, and `gross_profit_report_layout_test.php`. The focused GCash HTTP/browser suite covers a 105-record export, legacy labels, formula protection, combined filters, empty results, UI downloads, approved tunnel ingress, denied workspaces, CSRF, and failed retrieval/staging without partial attachments. QA screenshots use disposable fixtures at 1440px, 768px and 390px in both themes.

The broader `gcash_admin_overview_browser_test.js` cannot currently finish because its dependent integration fixtures still reference the retired `users.full_name` column. Its export assertions were migrated to Generate Report, but that broader suite is not a claimed pass. Repairing unrelated operational fixtures remains outside this change.
