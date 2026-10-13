# Cashier UI audit

Date: 14 October 2026 (Asia/Manila). Mode: Operate. Method: Impeccable audit, adapt and polish against the existing RetailMind product and design system.

## Final audit health score

**20/20 — Excellent for the reviewed implementation and states.** This is a scoped technical assessment; the hardware and certification limits below still apply.

| Dimension | Score | Evidence |
| --- | --- | --- |
| Accessibility | 4/4 | Label/landmark/heading review, computed text contrast, keyboard focus and dialog dismissal, readable feedback and appropriately sized controls. |
| Performance | 4/4 | No added dependencies or asset payloads; existing lazy product images and native scrolling retained; no new layout-reading loops or animation effects. |
| Theming | 4/4 | Existing tokens govern cashier surfaces and routine action colors; light/dark contrast checks passed across all reviewed states. |
| Responsive design | 4/4 | Seven viewport sizes, both tablet orientations, contained tables/dialogs, 44px targets and successful synthesized touch catalog scrolling. |
| Implementation integrity | 4/4 | Existing Store vocabulary, role boundaries and workflows preserved; misleading readiness status removed; detector had no primary findings. |
| **Total** | **20/20** | **345 browser checks passed; no JavaScript or live CDN resource errors.** |

Implementation integrity verdict: **Pass**. Ten finding families below were resolved, with no remaining verified P0/P1/P2/P3 defects in the reviewed scope. Strengths retained include identity-scoped history, append-only refunds, checkout recovery, manual printing, server validation and consistent shared navigation.

## Coverage

| Page | States reviewed | Improvements or verification |
| --- | --- | --- |
| My Sales (`cashier/dashboard.php`) | Sales, stock warnings, desktop/mobile/tablet | Restored normal page scrolling; replaced an unsupported “Register ready” claim with a Shift link; fixed section heading levels; enlarged receipt links. |
| Point of Sale (`cashier/pos.php`) | Empty/populated cart, available/unavailable stock, checkout/discard dialogs, held sales, locked Register | Scoped independent desktop panes to POS; bounded mobile catalog scrolling; enlarged quantity/remove buttons; added page heading, category selection states and keyboard access to the catalog; fixed metadata contrast and blue action consistency. |
| Find Product (`cashier/findProduct.php`) | Initial, search/category results, no matches, failed lookup, recovery | Added a visible field label, loading and retry guidance; cancelled stale searches; exposed selected category state; stopped Escape from unexpectedly navigating away when dismissing shared overlays. |
| Cashier Shifts (`cashier/shifts.php`) | Open shift, held-sale closure blocker, locked Register, history | Kept persistent blockers and recovery links inline; labelled action feedback; verified responsive forms and scrollable records. |
| Report Stock Issue (`cashier/stock_issues.php`) | Product selection/clear, correction form, report status filters, empty filter | Added a main landmark and heading hierarchy; made filters wrap and announce selection; explained empty results; fixed button contrast, fluid form columns and long content wrapping. |
| Refunds (`cashier/refunds.php`) | Lookup, refundable sale, saved Refund Receipt, history, locked Register | Kept persistent constraints inline; added error semantics; fixed embedded Refund Receipt heading hierarchy while preserving paper styling; verified form/table reflow. |
| My Operational History (`cashier/history.php`) | Sales, Cash Refunds, Drawer Movements, Cashier Shifts, record detail | Added responsive date controls and detail layouts; made record scrolling keyboard accessible; enlarged record links. |
| Sales (`invoice/sales.php`) | Receipt table, saved Sale Receipt dialog, Legacy Reversals | Verified cashier access, layouts and saved receipt dismissal; reused the existing receipt presentation and native dialog. |
| Profile (`auth/user_info.php`) | Cashier account | Verified existing shared responsive UI. |
| Preferences (`auth/preferences.php`) | Cashier account | Verified existing shared responsive UI. |
| Change password (`auth/change_password.php`) | Cashier account, visibility toggle | Verified responsive controls and touch interaction. |
| Workspace (`auth/workspace.php`) | Cashier role | Verified existing shared responsive UI. |
| Notifications (`notification/notifications.php`) | Cashier account | Verified existing shared responsive UI. |

## Resolved findings

- **P1 — Content could be clipped on desktop:** `cashier.css` applied POS height/overflow rules to every `.cashier-page`. Dedicated `.cashier-pos-page` scoping restores the dashboard and finder page scroll.
- **P1 — Payment followed the entire mobile catalog:** the stacked POS catalog now has a bounded, native scroll region with a keyboard focus target. Payment remains reachable after a consistent amount of page scrolling.
- **P1 — Small interaction targets:** quantity steppers, remove actions, product actions and disclosure controls now provide at least 44px targets. Dense operational records keep their own horizontal scroll rather than overflowing the document.
- **P1 — Unlabelled lookup / missing landmarks and headings:** visible product lookup label, POS page heading, Stock Issue main landmark and section hierarchy, and embedded Refund Receipt heading correction improve assistive navigation.
- **P1 — Low text contrast:** Stock Issue submit buttons and POS category counts, stock warnings and metadata now use readable colors in both themes.
- **P2 — Persistent warnings interrupted navigation:** the existing shared alert mechanism turned held-sale and locked/no-shift constraints into pop-ups, hiding recovery links. Persistent conditions now use the established inline-alert convention; action failures retain Operator Alerts.
- **P2 — Incomplete filter feedback:** category/status buttons expose their selection state; an empty Stock Issue status filter explains how to recover. New text searches clear old category selection.
- **P2 — Search recovery and cancellation:** lookup failures distinguish service failure from no matches; input/category changes cancel pending work; Escape no longer leaves Find Product while closing shared navigation.
- **P2 — Theme and sizing drift:** cashier surfaces and routine actions use existing neutral/blue tokens; mobile inputs use 16px text; history details stack on small screens and long content wraps.
- **P3 — Stale styles:** cashier stylesheets include file versions, including the changed receipt stylesheet.

## Verification

The browser regression uses an authenticated Cashier against a disposable MySQL database and real PHP routes. Fixtures include long product/category names, out-of-stock products, sales, held sales, saved refunds and historical receipts. Production records are never used.

Viewport matrix: **320×740, 375×812, 768×1024, 900×600, 945×1177, 1024×768 and 1440×900**, in light and dark themes. Checks cover document overflow, control bounds, field/control names, headings, 44px targets, computed text contrast and shared header overlap. Checkout/discard dialogs receive separate checks at phone, tablet and desktop sizes.

Interactions include synthesized Chromium touch scrolling of the product catalog, product quantity changes, search/category filters, simulated failed lookup and recovery, Stock Issue selection/clear and empty filters, drawer/backdrop/account/search controls, password visibility, checkout focus containment and Escape dismissal, and saved Sale Receipt dismissal. Locked Register states are checked separately. A database comparison confirms that the UI checks do not change sales, stock, cash, refunds or held sales.

Reproduce in PowerShell:

```powershell
$env:RUN_DB_TESTS='1'
node src/backend/tests/cashier_ui_browser_test.js --live-cdn
```

The script reports its temporary artifact directory and writes `results.json`, screenshots and server logs. Default mode stubs external CDN assets for isolated layout checks; `--live-cdn` verifies the actual icons, scanner library, alerts and receipt-table assets. The test is included in `run_all.sh` and skips unless disposable database tests are enabled.

Final confirmation: `--live-cdn --no-screenshots` returned **345 checks, 0 failing checks, 0 page errors and 0 resource errors**. Results: `C:/Users/Mike/AppData/Local/Temp/rm-cashier-ui-EySSVb/results.json`. The preceding screenshot inspection is in `C:/Users/Mike/AppData/Local/Temp/rm-cashier-ui-LKzAoj/`; settled checkout screenshots are in `C:/Users/Mike/AppData/Local/Temp/rm-cashier-ui-EySSVb/`. Temporary artifacts can be regenerated with the command above.

Additional checks: PHP syntax for all cashier pages and receipt/test helpers; stylesheet bundle consistency; Cashier alerts, workspace authorization, operational history, Stock Issue submission/correction, shift reconciliation/register locking and append-only Cash Refund contracts; thermal Refund Receipt rendering at 58mm/80mm and Sale Receipt keyboard/mobile/manual-print dialog checks.

Impeccable detector: **zero primary findings**. Its scan produced advisory palette/type/radius notices for legacy fallback literals and compact operational styles. These were reviewed against the existing design system; contrast and routine action-color defects were corrected. No detector waivers or changes to PRODUCT.md/DESIGN.md were introduced.

## Evidence limits

Viewport emulation and synthesized touch ran in Chromium on Windows. Physical iPhone/Android/tablet hardware, Safari/Firefox, screen-reader software, real camera/USB scanners and physical receipt printers were not exercised. Browser print checks verify paper layout and manual print behavior, not printer calibration. This technical audit is scoped to the reviewed surfaces and states; it is not a WCAG certification or a guarantee about every device/network condition.
