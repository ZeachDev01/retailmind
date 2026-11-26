---
version: 1
slug: "administrator-gcash-overview"
primary_target: "src/frontend/components/administrator/gcash_overview.php"
related_targets: ["src/frontend/components/administrator/gcash_overview_fragments.php", "src/frontend/assets/css/gcash-admin.css", "src/frontend/assets/js/gcash-admin-overview.js", "src/backend/app/Views/gcash/balances.php", "src/backend/app/Views/gcash/activity_metrics.php", "src/backend/app/Views/gcash/outstanding_reviews.php", "src/backend/app/Views/gcash/reconciliation_panel.php", "src/backend/app/Views/gcash/unresolved_panel.php"]
---

# Administrator GCash Overview

## Current compact direction — 2026-11-26

Confirmed four main tabs: Overview, Transactions, Sessions, Fund movements. Unresolved exchanges is a global review drawer under Transactions; Closing checks is a review drawer under Sessions. Preserve legacy review URLs, pending counts, all evidence/actions, and queue independence from report filters. Period/Cashier controls remain visible and are shared between Overview and Transactions; custom dates and supporting record details open in drawers. Empty content uses its natural height instead of viewport stretching. Earlier five-tab and viewport-filling decisions below are historical.

Verification: `npm run check`, four GCash contracts, the complete tab regression, session-history and fund-movements integration browser tests passed. Light/dark captures at 320–1440px include populated/empty states and failed-loading recovery; desktop/mobile captures were visually inspected. A disposable-data action check verified clearing an exchange, its saved receipt and focus restoration, and closing review. TestSprite run `8e5101b6-de90-4ab2-9bf5-dc32d694f66d` passed 22/22 steps at its default viewport. The broader financial overview suite still fails on its existing `users.full_name` fixture; physical devices and Safari remain untested. No new dependencies, global tokens or financial calculations.

Mode: Operate. Confirmed on 2026-11-11: the entire Administrator overview follows the cashier GCash page's tabs, tables, and layout. This is a local consistency change within RetailMind's existing visual system.

## Direction

Reuse the cashier GCash underline tabs with a 44px minimum control height, active blue indicator, keyboard navigation, and horizontal scrolling. Keep one navigation level: Overview, Transactions, Closing checks, Unresolved exchanges, and Service Fund Movements. The former balance/activity coin switcher is removed.

Overview shows current service balances and the review queue together, followed by report controls joined to completed activity. Keep current custody evidence distinct from filtered report totals. Activity metrics use divided columns rather than individual cards. Table views use one bordered panel with a soft header, controls, rows, and pagination; retain horizontal table scrolling on narrow screens.

Retain theme-aware surfaces, system typography, existing GCash assets, semantic statuses, and visible focus. Preserve admin review, emergency handover, export, receipt, filtering, and role-permission behavior. Fragment responses wrap transaction rows in a table so document parsing retains their `tbody` during filter refreshes. No global tokens, dependencies, or visual-world changes were added; `docs/DESIGN.md` and `.impeccable/design.json` remain the incumbent authorities.

## Verification

Implementation verification reported `npm run check` passing and `gcash_admin_tabs_browser_test.js` passing with `RUN_DB_TESTS=1` at 1440px and 390px, including filters, keyboard tabs, transaction details, and mobile reload alignment. The separate visual runner checked all five tabs at 1440px, 768px, and 390px in light and dark themes without page overflow. Four PHP contracts passed: module seams, money history, closing-difference review, and activity dates.

The build thread directly inspected `output/gcash-redesign/overview-light-1440.png`, `transactions-light-1440.png`, `overview-dark-390.png`, and `money-dark-390.png`. These are local fixture evidence, not shipping imagery or production records.

The independent finish reviewer returned `ship` with no required fixes for this scoped consistency change. That verdict does not extend to unrelated work in the shared tree.

The broader `gcash_admin_overview_browser_test.js` stopped early on a pre-existing label assertion: expected `Not recorded`, actual `—`. Its remaining assertions are not claimed as verified by that run. No production financial mutations were used for layout verification.

## Compact overview — 2026-11-15

The user confirmed compact balances above one Activity report, with minimal text. Service cash and GCash wallet now use divided columns/rows, a quiet Total funds row, and always-visible cashier/last actual check. Expected / Last counted labels remain; Not date-filtered identifies the scope without implying that a closed session is current. Duplicate balance subtitles, card hints, activity flow tags, and income tags were removed.

Activity report is the single section heading for date/cashier controls and completed metrics. Gross fees replaces Total Fees Earned; its tooltip refers to the selected period and fees before returns. Existing data calculations, fragment boundaries, filters, exports, review actions, and tabs remain in place.

The cached-script browser regression passed at 1440px, 768px, and 390px, including date-filter balance invariance, visible balance evidence after refresh, one report heading, tab/keyboard navigation, and page overflow checks in light/dark themes. Rendered light desktop and dark mobile captures were inspected in `output/playwright/gcash-admin-clarity/`. The test used a disposable database; missing support files already deleted in the user's working tree were read from HEAD into temporary files for this run and removed afterward, without restoring the deleted workspace files.

`npm run check`, the scoped Impeccable detector, PHP syntax, and the module-seams, activity-dates, closing-difference, and money-history contracts passed. Existing SQLite API deprecation notices remain in three contract runners. The broader overview browser test's old collapsed-evidence assertion was updated, but that separate runner was not rerun.

## Activity report empty state — 2026-11-16

When the selected range has zero completed exchanges, replace zero metrics and fee badges with a receipt icon, concise explanation, and Reset filters action. Retain report controls and View transactions. Failed refreshes hide the empty state and expose unavailable totals; populated reports retain existing metrics. Filters stack into one column at 480px and below.

The cached-script browser regression passed with empty-state coverage at 320, 390, 768, 1024, and 1440px in light/dark themes, populated-state recovery, and simulated failed loading. Captures in `output/playwright/gcash-admin-empty/` were visually inspected on desktop and mobile. `npm run check`, PHP/JS syntax, module-seams and activity-dates contracts passed; the latter retains its existing SQLite deprecation notice. Tests used a disposable database and temporary copies of support files deleted in the user's tree. Physical phones and Safari were not tested.

## Distill, clarify, typeset — 2026-11-16

All five tabs retain their controls and financial evidence with less prose. Removed duplicate review subtitles, all-clear claims, and empty-table explanations. Missing values use a dash; absent balance badges and shift/approval metadata are omitted. Real Expected/Last counted labels remain. Legacy movement evidence is labeled once, and missing amounts never become zero. Export wording and action-consequence warnings remain intact.

Typography keeps system UI with quieter labels, sentence-case table headers, larger metadata, plain reference numbers, and unboxed Expected/Counted/Difference comparisons. Transaction details stack on narrow screens. Tables opt out of shared filler rows and the inherited minimum height, so only real records determine their height. Activity summary badges are plain metadata.

The all-tab browser regression covers 320, 390, 768, and 1440px in both themes, plus filtering, empty/error recovery, keyboard navigation, and transaction details. Captures are in `output/playwright/gcash-distill/`. `npm run check`, PHP/JS syntax, four GCash contracts, and an isolated missing-balance/legacy-view render check passed. The broader financial browser suite passed its initial missing-balance checks but stopped in its pre-existing integration fixture: `Unknown column 'full_name' in 'INSERT INTO'`. Its remaining flows are unverified by that run. Physical devices and Safari remain untested.

## Consistent empty states — 2026-11-16

The five tabs share one neutral empty-state treatment: a muted Bootstrap icon, 1rem semibold heading, short optional guidance, and a secondary recovery action when useful. No inset border, gradient, illustration, or success claim. Overview includes matching states for missing service balances, an empty review queue, and completed activity. Real unavailable/error responses remain distinct.

Empty transaction/review tables hide their column headers and remove the populated table minimum width so messages remain centered and visible on phones without horizontal scrolling. Populated tables retain their columns and scrolling. Empty actions retain 44px touch targets; Reset filters preserves its tab, and All sessions preserves the movement scope.

The browser regression verifies populated behavior, then recreates its disposable database without GCash records and captures all five tabs at 320, 390, 768, 1024, and 1440px in both themes. It checks content bounds, recovery links, touch targets, failed loading, and successful retry. Captures are in `output/playwright/gcash-empty-states/`. `npm run check`, module-seams/activity-dates contracts, and PHP/JS syntax checks passed. Deleted support fixtures remain deleted in the user's working tree; temporary copies were used only for verification. Physical devices and Safari remain untested.

## Viewport height and movement rules — 2026-11-16

The GCash workspace uses a viewport-height flex layout. Empty report and table bodies grow into the remaining visible area, with headings, filters, and footers retaining their natural height. The 200px minimum remains for short screens and long content, which can scroll normally. Overview balance/review cards remain compact; populated tables retain their scrolling behavior.

Timestamp & correction rules now appears as a help trigger in its own row above the Service Fund Movements panel. It reuses the shared tooltip script, retains the original timing explanation, stays within the viewport, and supports focus, click, and Escape. No shared tooltip implementation or financial behavior changed.

Verification includes exact panel-bottom checks against viewport height minus workspace bottom padding at 1440×1800, all-tab light/dark responsive checks, and tooltip focus/Escape/bounds checks. `npm run check`, PHP syntax, module-seams and money-history contracts passed. Physical devices and Safari remain untested.

The user subsequently moved Timestamp & correction rules into the Service Fund Movements panel header, beside the heading. The separate help row was removed; tooltip content and keyboard/click behavior remain unchanged.

## GCash actions and reporting — 2026-11-16

User confirmed removal of Record Exchange and Export Report from this overview. Removed both header actions, their switch dialog, export fragment, obsolete handlers and action-only styles. Activity report, date/Cashier filters, all five tabs, balances and review controls remain. Cashier recording continues through existing workspace navigation.

Generate Report owns the replacement Administrator-only GCash Transactions report. Its independently selected scope uses the same Activity Scope query and preserves CSV fields, complete matching history, legacy labels and formula protection. Approved tunnel ingress follows existing shared access policy.

Verification: `npm run check`, focused `gcash_report_browser_test.js` (`RUN_DB_TESTS=1`), report access and active-workspace contracts pass. The focused suite captures the action-free overview at `output/playwright/gcash-reports/overview-1440.png`; this is disposable-fixture QA evidence. The broad overview suite remains blocked by its pre-existing integration fixture references to `users.full_name`, so its complete pass is not claimed. Scoped finish review: ready for this navigation change; no new tokens, dependencies or shipping imagery.
