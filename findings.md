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
- Fiscal Periods currently links to `components/modals/fiscal_periods.php` and the sidebar intercepts that link with `data-fiscal-periods-open` to launch a lazy iframe overlay.
- The fiscal-period file is already a full HTML document with admin authorization, CSRF-protected create/close/lock actions, activity logging, and an optional `embed=1` presentation/footer.
- Fiscal-period modal behavior is centralized in the sidebar: overlay markup, iframe loading, backdrop/close/Escape handling, and a `close-fiscal-periods` postMessage listener.
- The existing page styling is mixed into `assets/css/modals.css`; its embedded-mode branches and overlay/frame selectors should be removed when the dedicated page is introduced.
- The admin dashboard has two fiscal-period entry points (the overview stat card and the multiple-open-periods attention item); both currently carry the modal trigger attribute.
- The dedicated Audit Logs implementation establishes the preferred route location (`components/system_administrator`) and normal app-shell layout for administration pages.
- Existing fiscal-period form/card styles originate from the broad `admin.css`/`modals.css` bundles. A dedicated stylesheet can keep the new page scoped and allow all fiscal modal selectors to be deleted from `modals.css`.
- The smoke suite lives at `src/backend/tests/smoke_checks.php` and already includes dedicated audit-page navigation regressions that can be mirrored for fiscal periods.
- The global style bundle already supplies the app-shell topbar, stat cards, dashboard sections, form basics, buttons, and design tokens; the new fiscal stylesheet only needs page-specific layout and state treatment.
- After implementation, repository-wide frontend search finds no fiscal modal trigger, overlay, iframe, embedded-mode, footer, or modal-route references; only direct links to the canonical page and the intentional compatibility redirect remain.
- System Health currently lives at `components/modals/system_health.php`; it builds a read-only check list through `SystemHealthService`, counts healthy/warning/critical results, provides a refresh link, and shows maintenance commands.
- Its sidebar link is intercepted with `data-system-health-open`, and the sidebar owns the overlay, lazy iframe, close/backdrop/Escape, and postMessage behavior.
- System-health modal and embedded presentation selectors occupy a self-contained block in `assets/css/modals.css`, plus two responsive blocks, so they can be removed cleanly.
- Unlike Fiscal Periods, System Health has no admin-dashboard navigation link to migrate; the only direct UI entry is the System Administration sidebar item.
- The existing `admin.css` supplies compact generic health-check styles, but a dedicated page-scoped stylesheet will avoid relying on that legacy aggregate stylesheet.
- The existing System Health smoke check points at the modal path and must be moved to the canonical page, with a separate direct-navigation regression check for the sidebar.
- After implementation, frontend search finds no System Health trigger, overlay, iframe, embedded-mode, footer, or modal-route reference; only the canonical page and intentional compatibility redirect remain.
- The Manage Users screenshot shows the Users/Branches tabs as a full-width dark slab between the stats and content card; this creates excessive visual weight and makes the control feel detached from the panel it switches.
- The tab markup is already accessible (`tablist`, `tab`, `tabpanel`, `aria-selected`, roving `tabindex`) and should be preserved.
- Tab styling is centralized in `assets/css/modals.css`; the same control serves full-page and embedded modes, so the redesign needs light-page defaults plus dark embedded overrides.
- User and branch counts are already loaded on the page and can be surfaced as compact tab badges without additional queries.
- The new tab control can remain entirely server-rendered and CSS-driven; the existing click and keyboard handlers continue to operate through unchanged `data-management-tab` attributes.

## Design Notes

- A dedicated page is appropriate because audit logs benefit from persistent table controls and more screen space than a modal provides.
- Native DataTables pagination should replace the current PHP pagination only if the page loads the full filtered result set; otherwise DataTables would search/sort only the current 10-row slice.
- For the current app, client-side DataTables over the filtered result set is the smallest reliable integration; `deferRender` and a 25-row default reduce initial DOM work. If the log grows very large, the follow-up should be DataTables server-side processing.
- The browser-control runtime is not exposed in this session, so visual QA must be completed with static/runtime checks rather than an automated screenshot pass.
- The repository smoke suite is content-based and can be extended with a regression check for the new canonical route, DataTables initialization, and absence of audit modal hooks.
