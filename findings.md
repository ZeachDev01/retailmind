# Findings

## Current Implementation

- The sidebar's Audit Log item targets `components/modals/audit_log.php` and is intercepted by `data-audit-log-open` JavaScript.
- The sidebar renders an audit overlay containing a lazy iframe, plus close/backdrop/Escape/postMessage handling.
- `components/modals/audit_log.php` is already a complete HTML document with auth, SQL filters, CSV export, a six-column table, and server-side pagination at 10 rows per page.
- The page supports an `embed=1` presentation mode and footer used only by the iframe modal.
- User Management is implemented as a regular app-shell page and the sidebar uses a compatibility path under `components/modals`.
- No DataTables library or initialization currently exists anywhere under `src`; adding it will be a new frontend dependency.
- Existing audit styling is concentrated in `assets/css/modals.css`, including both overlay/frame rules and embedded-page rules.
- DataTables 3.0.4 is the current stable release in the official documentation and is dependency-free, so it can be initialized with `new DataTable(...)` without adding jQuery.
- The existing `components/modals/manage_users.php` can render as a normal app-shell page or an embedded modal; its full-page layout provides the closest in-repo presentation reference.
- Audit links also appear on the admin dashboard, so both sidebar and dashboard entry points must be updated and their `data-audit-log-open` hooks removed.
- A natural canonical location is `components/system_administrator/audit_logs.php`, alongside ML settings, backups, and system settings.
- The compatibility CSS bundle imports `modals.css`; removing the iframe markup makes its audit-overlay selectors dead code, while a dedicated audit stylesheet can avoid coupling the new page to modal presentation rules.

## Design Notes

- A dedicated page is appropriate because audit logs benefit from persistent table controls and more screen space than a modal provides.
- Native DataTables pagination should replace the current PHP pagination only if the page loads the full filtered result set; otherwise DataTables would search/sort only the current 10-row slice.
- For the current app, client-side DataTables over the filtered result set is the smallest reliable integration; `deferRender` and a 25-row default reduce initial DOM work. If the log grows very large, the follow-up should be DataTables server-side processing.
- The browser-control runtime is not exposed in this session, so visual QA must be completed with static/runtime checks rather than an automated screenshot pass.
- The repository smoke suite is content-based and can be extended with a regression check for the new canonical route, DataTables initialization, and absence of audit modal hooks.
