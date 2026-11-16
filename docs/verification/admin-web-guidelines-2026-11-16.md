# Administrator Web Interface Guidelines audit

Date: 2026-11-16 (Asia/Manila). Scope: the **Administrator** Workspace, its shared pages, linked destinations, and shared controls. Super Administrator-only pages are outside this audit.

Reviewed against the installed `web-design-guidelines` skill, [upstream Web Interface Guidelines](https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md), and `CODING_STANDARDS.md`. The initial audit was read-only. The user subsequently authorized fixing every finding using Impeccable; those changes preserve existing WIP and the incumbent interface.

## Fix verification

All 11 findings below have been addressed:

| Finding | Change |
| --- | --- |
| F01 | Rejected Store details and thresholds retain submitted scalar values. Store name, email, and timezone validation identify the field, show an inline error, and focus it. Persisted settings remain unchanged on rejection. |
| F02 | Rejected Promotions retain name, discount type/value, scope, Product/Category selection, quantity, and dates. The invalid field receives an associated inline error and focus. Reflected text stays escaped. |
| F03 | Editing forms opt into one shared unsaved-change check. Internal navigation uses the existing confirmation dialog; reload/closing the page uses the browser warning. Submitting the edited form proceeds normally, other edited forms remain protected, Cancel retains the draft, reverting changes clears the warning, and successful navigation releases old form snapshots. Filters remain outside the guard. |
| F04 | Public Tunnel URL now has an associated label. |
| F05–F10 | Corrected the Dashboard warning and breakdown links, GCash primary buttons, Model Review explanations/status badge, Calendar Today badge, Store Configuration navigation/status, and Data Readiness pagination. Foreground/background pairs remain readable across both themes. |
| F11 | Flagged controls now have at least 44px targets, including backup tabs/help, Fiscal Period controls, export/print/filter actions, profile actions, password visibility, audit column/pagination buttons, forecast row actions, and Stock Movement sorting/help/pagination. |

Validation:

- `npm run check` passed, including stylesheet equivalence, all 119 views, 113 smoke checks, and the Remote Access checks.
- `npm run check:contracts` passed.
- `page_navigation_browser_test.js` passed its existing navigation, fullscreen, history, form/upload, download, error, and authentication-redirect cases.
- `register_administration_contract.php`, changed PHP lint, JavaScript syntax, and scoped diff whitespace checks passed.
- The final strict matrix passes **672/672 layout/theme checks**, with no captured JavaScript or failed CDN requests. Evidence: `.scratch/admin-web-guidelines-fixed-2026-11-16.json` and `%TEMP%/rm-admin-ui-90g5TK/`. This complete run supersedes intermediate runs and focused rechecks.
- Added live disposable-database regressions for rejected Store/Promotion drafts, escaped reflected values, selection retention, associated errors/focus, unchanged persisted settings, Cancel/Discard navigation, clean/reverted forms, and native reload warnings.
- Inspected settled light/dark desktop/mobile captures of the warning, Dashboard history, Model Review exclusions, form validation, password controls, audit page, and Stock Movement surface. Settled captures: `%TEMP%/rm-admin-ui-I8rPlv/`. Additional page captures: `%TEMP%/rm-admin-ui-wpN7k6/`.

The browser suite now uses the current direct PHP server setup, covers the Administrator pages missing from its old route list, ignores hidden native selects/print headings, and closes dialogs before testing background navigation. It respects unpainted `content-visibility:auto` regions; source review and scrolling visual checks supplement the matrix. Temporary screenshot experiments that captured an open guidance alert, CSS transitions, or unpainted deferred content are not final defect evidence.

Two additional contracts still fail on pre-existing copy expectations: `administrator_model_review_component_test.php:305` expects an obsolete `forecast-status-summary` section/message, and `admin_alert_contract.php` expects longer alert sentences in Store Settings and four unchanged auth/backup locations. Inspection of `HEAD` confirms the mismatch predates these fixes. These assertions were not relaxed or worked around. The default check and contract suites above pass.

No production data, passwords, roles, restore state, or Remote Access service state were changed. Browser fixtures created and cleaned up guarded disposable databases. Physical devices, screen readers, and the full release suite remain outside this verification.

## Original findings

### Tier 1 — functional issues

- **F01 — `src/frontend/components/administrator/store_settings.php:141`** — Failed validation discards entered Store details. Reproduced by changing Store name and submitting `Invalid/AuditZone` as timezone: the error appears, but the name resets to `Shalom Store` and timezone to `Asia/Manila`. Keep submitted values after rejection; associate the error with the failing field and focus it.
- **F02 — `src/frontend/components/inventory_management/promotions.php:109`** — Failed validation clears the Promotion draft. Reproduced with an end date before the start date: the server rejects it, but name and discount become blank and dates return to defaults. Preserve submitted fields, scope, and selections so the user can correct the dates.
- **F03 — `src/frontend/components/administrator/store_settings.php:200`** — Editing forms have no unsaved-change protection. Section links navigate away from edited Store details; the shared navigation and form scripts provide no dirty-form guard. The same gap affects Promotion creation (`promotions.php:104`) and Cashier Shift working notes (`administrator/shift_report.php:295`). Warn before abandoning edits; exclude successful submissions and read-only filter forms.

These are entry-loss/recovery defects, not evidence of lost saved records or an authorization bypass.

### Tier 2 — accessibility and interaction

- **F04 — `src/frontend/components/administrator/store_settings.php:347`** — `#remote-public-url` has no accessible name. “Public Tunnel URL” is a span, not an associated label. Add a label or `aria-labelledby`; the field becomes visible when Remote Access is online.
- **F05 — `src/frontend/assets/css/dashboard.css:216`** — Dashboard missing-cost warning text measures **1.70:1** in dark mode. Its hardcoded light warning surface conflicts with the rendered text color. The 30-day breakdown summary also measures **3.65:1** (`dashboard.css:644`). Use compatible semantic foreground/background pairs; normal text needs 4.5:1.
- **F06 — `src/frontend/components/administrator/gcash_overview.php:172`** — “Apply filters” text measures **2.54:1** in dark mode. Correct the button foreground/background pair and verify hover/focus states as well.
- **F07 — `src/frontend/components/administrator/model_review.php:241`** — Dark-mode explanatory text measures **2.91:1**. Exclusion reasons measure **1.99:1** (`:334`), next actions **2.91:1** (`:335`), and observation guidance **2.91:1** (`:360`). Replace legacy `--color-*` fallbacks with the established semantic text tokens.
- **F08 — `src/frontend/components/administrator/store_calendar.php:386`** — The small “Today” badge measures **4.16:1** in light mode and **2.66:1** in dark mode. Both fall below 4.5:1 for this text size.
- **F09 — `src/frontend/assets/css/store-settings.css:790`** — Active section text measures **4.17:1** in light mode. The “Online” state measures **2.66–3.04:1** (`:821`). Use accessible text tokens while retaining the status dot.
- **F10 — `src/frontend/components/report/data_readiness.php:147`** — Current pagination number measures **1.41:1** in dark mode. White text on the theme's primary background needs a different accessible pairing.
- **F11 — `src/frontend/assets/css/backup.css:401`** — Recovery tabs render at **40px** high. Other undersized controls include Fiscal Period rollover (**34px**, `fiscal-periods.css:37`), report export/print (**40px**, `report-generation.css:156`), profile actions (**42px**, `user-info.css:188`), and password visibility (**32px**, `auth/change_password.php:551`). Bring these toward the project's 44px touch target. This is a touch-usability recommendation, not a claim that every target fails WCAG's 24px minimum.

Tier 3 cosmetic micro-lint is intentionally omitted. Findings are grouped by root cause/page rather than multiplied by viewport.

## Coverage and evidence

`npm run check:views` passed: **119 PHP views, zero errors, zero warnings**. That scanner does not verify rendered contrast, accessible names of all controls, draft retention, or every generated link.

The current browser matrix exercised **29 page paths / 48 accessible route states × 2 themes × 7 viewports = 672 layout checks**. Viewports: 320×740, 375×812, 768×1024, 900×600, 945×1177, 1024×768, and 1440×900. Layout was checked using DOM rectangles and computed styles. No captured JavaScript errors, failed CDN requests, or page-overflow findings occurred in this run.

| Page path under `src/frontend/components/` | Route states |
| --- | ---: |
| `administrator/dashboard.php` | 1 |
| `administrator/gcash_overview.php` | 1 |
| `administrator/registers.php` | 1 |
| `administrator/store_settings.php` | 3 |
| `administrator/store_calendar.php` | 1 |
| `administrator/database_backup.php` | 1 |
| `administrator/model_review.php` | 1 |
| `administrator/stock_issues.php` | 2 |
| `administrator/shift_report.php` | 1 |
| `system_administrator/fiscal_periods.php` | 1 |
| `system_administrator/audit_logs.php` | 1 |
| `user_manager/user_manager.php` | 1 |
| `inventory_management/promotions.php` | 1 |
| `inventory_management/products.php` | 1 |
| `inventory_management/replenishment_queue.php` | 1 |
| `inventory_management/inventory_insights.php` | 1 |
| `inventory_management/product_investigation.php` | 1 |
| `invoice/sales.php` | 2 |
| `invoice/legacy_reversals.php` | 1 |
| `report/data_readiness.php` | 1 |
| `report/forecast_exceptions.php` | 1 |
| `report/predictions.php` | 1 |
| `report/receiving_history.php` | 1 |
| `report/report_generation.php` | 16 |
| `auth/user_info.php` | 1 |
| `auth/preferences.php` | 1 |
| `auth/change_password.php` | 1 |
| `auth/workspace.php` | 1 |
| `notification/notifications.php` | 1 |

Report states cover the default sales report and every currently cataloged report type, including GCash Transactions. Store Configuration covers all three sections. Stock Issue Oversight covers list/detail; Receipt History covers its default and reversal-linked state.

Raw matrix evidence: `%TEMP%/rm-admin-ui-Op3iKp/results.json` and `server.log`. Focused validation evidence: `%TEMP%/rm-admin-ui-D8IXCL/`; corrected Calendar keyboard interaction: `%TEMP%/rm-admin-ui-PG2tof/`. These are local temporary artifacts, not portable committed evidence.

The run used guarded random `retailmind_theme_test_*` databases, with fixture cleanup afterward. Invalid form submissions were made only against those disposable databases. No production mutation or remote-access toggle was submitted.

## Initial test-harness limitations and rejected false positives

This records the initial audit setup. The fix-verification section above describes the subsequent checked-in test repairs.

- The checked-in `superadmin_ui_browser_test.js` references a deleted `support/theme_router.php`. A temporary copy used the current direct PHP server setup with `DB_NAME` pointing at its disposable fixture, added GCash Overview, Model Review, Receiving History, GCash reporting, and the two extra Store Configuration sections, and bounded animation waits at 400ms. Repository tests were not edited.
- The original unbounded animation wait stalled on Dashboard; that first attempt was stopped and cleaned up. The completed matrix uses the bounded wait.
- Raw output contains **676 records / 438 flagged records**, not 438 distinct defects. It includes repeated viewport findings, target-size recommendations, and the false positives below. Do not treat that count as a WCAG score.
- Hidden native selects lose their explicit label when the existing searchable-combobox enhancement transfers it to the visible search input. The hidden controls are not unlabeled interactive fields.
- Generate Report has a hidden print-only `h1`; counting all headings without visibility checks falsely reports multiple page headings.
- The Calendar interaction test tries to focus a background date while its editor is open. Closing the editor first makes keyboard date selection pass; background focus isolation is correct.
- Receipt History's reversal-linked state opens a receipt dialog. Its backdrop correctly blocks the test's attempt to tap the page menu before closing the dialog.
- Data Readiness's interaction assertion expects obsolete `.stat-card .value` markup. That timeout alone is not a page defect.
- `report/forecast_analytics.php` returns **403** for Administrator and is absent from its current sidebar. This obsolete matrix entry is an expected authorization boundary, excluded from the 48 accessible states.

## Initial audit limits

The initial source and automated Chromium audit did not include visual screenshot review; the follow-up rendered review is recorded above. This is not screen-reader certification or exhaustive WCAG conformance testing. Physical touch devices, Safari/Firefox, virtual keyboards, and every populated modal/error/forecast state remain untested. Shared modals and scripts were inspected, but the matrix does not establish that every action or data combination works. The full release suite was not run. The requested Caveman skill file was not found in the installed skill paths; the supplied concise-voice rules were followed.
