# Cashier tablet and small-screen audit

Date: 2026-11-18 (Asia/Manila). Mode: Operate; preserve the existing RetailMind identity and workflows.

## Result

Fixed the shared Cashier page-heading layout and GCash session header. The authenticated Chromium regression passes **22 routes/states × seven viewports (154 checks)** with no page overflow, clipped non-scrollable controls, heading hidden beneath the mobile bar, or Return to POS action separated vertically from its heading on tablet/desktop.

Viewports: 744×1133 and 768×1024 portrait; 1133×744 and 1024×768 landscape; 390×844 and 320×740 phones; 1440×900 desktop. Tests use synthetic data in a disposable database, not live Store records.

## Findings and changes

| Priority | Finding | Change |
| --- | --- | --- |
| P1 | Stock Issues inherits a 320px flex basis after the global 900px breakpoint changes its header to a column. This creates approximately 300px of blank space before Return to POS. | Cashier header children reset their flex basis. Tablet/desktop headings use a flexible title column and an action column. |
| P2 | Shifts, Refunds and Find Product stack Return to POS below the title at tablet widths despite enough horizontal space. | Shared Cashier headings retain an action row above 640px; phones retain their established stacked flow. |
| P2 | The more specific Cashier `.pos-panel-header` rules override the GCash session header, centering and stacking its brand/actions on tablets. | Scope the GCash header rule to `#gcash-workspace`, explicitly use a row, and retain stacking at 600px. |
| P2 | Pages loading `cashier-pages.css` miss some of the 44px control heights supplied by `cashier.css`. | Cashier buttons, history tabs and Stock Issue filters receive a 44px minimum height on smaller screens or coarse pointers. Header buttons also align icons and text with a gap. |

Changes are CSS only. `style.css` was rebuilt from source; existing unrelated working-tree edits were preserved.

## Page coverage

| Page | Audited states | Result |
| --- | --- | --- |
| POS | Open shift, existing held sale | Fits; existing dedicated POS suite also passes. |
| Dashboard | Synthetic sales; dedicated populated/empty fixture suite | Fits; dashboard suite passes. |
| GCash | New transaction, History, Service Fund Movements, closed session | Fits; tablet header corrected. |
| Shifts | Record movement, Current movements, Close shift, Recent shifts | Fits; Return to POS corrected. |
| Transaction History | Sales, refunds, movements, shifts | Fits; intentional inner scrolling remains available. |
| Refunds | Lookup and loaded sale | Fits; Return to POS corrected. |
| Refund corrections | Empty pending queue | Fits; populated correction review remains outside this layout check. |
| Stock Issues | New report and synthetic existing report | Fits; large header gap removed. |
| Find Product | Initial category/search workspace | Fits; tablet action row corrected. |
| Receipt History | Saved sale list | Fits. |
| Account, Preferences, Notifications | Cashier-accessible shared pages | Fit. |

## Verification

- `RUN_DB_TESTS=1 node src/backend/tests/cashier_responsive_browser_test.js`: passed, 22 routes/states and seven viewports. Uses `getBoundingClientRect()` and native visibility checks; controls inside intentional horizontal scroll containers are not treated as page overflow.
- `npm run check`: passed. View audit retains its existing conditional-template duplicate-ID warning for `open-register` in Shifts.
- Dedicated browser suites passed: `shifts_layout_browser_test.js`, `gcash_dialog_layout_browser_test.js`, `cashier_dashboard_layout_browser_test.js`, `pos_tablet_layout_browser_test.js`, `gcash_history_layout_browser_test.js`, `gcash_follow_up_layout_browser_test.js`, `gcash_fund_movements_browser_test.js`. Shift fixture emits existing undefined-variable warnings.
- Supplementary authenticated 744px dark-theme check passed: touch opening/closing navigation and background inert state; all four shift tabs; three GCash tabs; Open GCash dialog and Escape. No transaction was submitted. Dark renders of Shifts, GCash, Refunds, Stock Issues and Find Product fit.
- Impeccable detector: zero primary findings; 564 advisory occurrences across the scanned existing Cashier views/styles and shared responsive stylesheet. These are largely literal palette/type/radius values; they are opportunities for a separate token-consistency pass, not evidence of a new layout failure.
- TestSprite run `7c131e4d-8f85-4d36-b40b-46ad0be60652`: failed its broad navigation assertion after successful login. The pinned steps and HTML snapshot were inspected: the POS workspace loaded, but the assertion supplied no specific overflow measurement or failing navigation action. Artifact download was refused because TestSprite withheld the stored script after an environment change. Local touch navigation passes independently; **TestSprite remains failed**, not accepted as a pass. [Run](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/d0bc1aba-ea2b-4b4e-ae4e-8a37eb8ed983/test/7c131e4d-8f85-4d36-b40b-46ad0be60652).
- Existing `cashier_ui_browser_test.js` cannot start because `src/backend/tests/support/cashier_ui_fixture.php` is missing. The new layout suite uses the available `theme_fixture.php`.

## Evidence and remaining opportunities

Before/after captures: [Shifts before](evidence/cashier-responsive-2026-11-18/shifts-before-744.png), [Shifts after](evidence/cashier-responsive-2026-11-18/shifts-after-744.png), [Stock Issues before](evidence/cashier-responsive-2026-11-18/stock-issues-before-744.png), [Stock Issues after](evidence/cashier-responsive-2026-11-18/stock-issues-after-744.png), [GCash after](evidence/cashier-responsive-2026-11-18/gcash-after-744.png), [GCash dark](evidence/cashier-responsive-2026-11-18/gcash-dark-744.png), [Refunds landscape](evidence/cashier-responsive-2026-11-18/refunds-after-1133.png).

Implementation integrity: the change preserves existing tokens, content, role gates, IDs and actions. No dependency, animation, backend logic or new user flow was introduced. Existing table scrolling, two-column tablet forms and phone stacking were retained where they already worked.

This is a responsive audit with targeted interaction/theming checks, not a full WCAG certification or performance benchmark; no aggregate accessibility/performance score is claimed. Physical iPad mini, Safari/WebKit, software-keyboard behavior, camera/scanner hardware, 200% text scaling on every route, and populated approval/correction drawers remain unverified. Prioritize physical-device checks and repairing the missing full-UI test fixture before expanding visual changes. Finish any further adaptations with an Impeccable polish pass.
