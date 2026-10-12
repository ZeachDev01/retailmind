# Responsive UI verification

Verified on 2026-10-09 in headless Chromium. These changes extend the existing UI; no dependencies, application database schema, authorization rules, or backend business logic were changed.

## Root causes and fixes

| Observed cause | Fix |
| --- | --- |
| Page initialization repeatedly registered global modal and submit handlers. Multiple Escape handlers could close both a confirmation and its underlying drawer. | Register global handlers once, bind each form once, and dismiss only the topmost overlay. Keep checkout's submission guard in its page handler. |
| Overlay stacking, focus return, and scroll locking were handled inconsistently. A closed overlay could still receive keyboard focus; navigation could leave a confirmation behind. | Use the shared overlay helpers for users and POS, track active overlay order, trap and restore focus, hide closed panels, and clean up overlays/native dialogs during navigation. |
| Fixed backdrops left an uncovered strip beside the stable scrollbar gutter. Long forms could push their actions outside the viewport. | Cover the viewport explicitly, constrain panels with dynamic viewport height, and scroll their body while keeping headers/actions accessible. |
| Account submenus were clamped over their own trigger on narrow screens. | Place them beside the menu when space permits, otherwise above/below their trigger with bounded internal scrolling. |
| Mobile table card styles overrode the browser's `hidden` behavior, showing rows outside the selected page. | Preserve semantic `hidden` across responsive styles. Keep product cards within their container and retain ten-row pagination. |
| Grid columns and form fields retained their content's minimum width. Tabs and notification actions did not wrap. The POS desktop rule disabled its cart's horizontal scrolling. | Allow affected columns/fields to shrink, wrap tabs and notification actions, restore cart scrolling, and extend the product toolbar's tablet breakpoint. |
| Navigation replaced a page's existing main-content ID, breaking product pagination's scroll target. | Preserve authored IDs on the initial page and partial navigation. Assign the fallback ID only when absent. |
| POS async functions were scoped inside the page renderer's script block but referenced by global inline click handlers. | Bind those buttons and dynamically rendered Held Sale resume actions to their existing functions within the page's scope. |
| Products wrapped the global overlay helper repeatedly, retaining stale page closures. | Restore product form drafts through a scoped overlay-open event. |

The screenshot's exact GPU/blur artifact was not reproduced on physical hardware. Layer hit-testing, opening/closing transitions, table visibility, focus, and scroll restoration passed the checks below.

## Application files changed

Paths below are relative to the repository root.

| Area | Files |
| --- | --- |
| Shared styles | `src/frontend/assets/css/global.css`, `modals.css`, `responsive.css`, `sidebar.css`; regenerated `style.css` |
| Page styles | `src/frontend/assets/css/cashier.css`, `inventory.css`, `invoices.css`, `notifications.css`, `reports.css` |
| Shared JavaScript | `src/frontend/assets/js/ui.js`, `page-navigation.js`, `sale-receipt-dialog.js` |
| Page components | `src/frontend/components/cashier/pos.php`, `inventory_management/products.php`, `sidebar.php`, `system_administrator/system_settings.php`, `user_manager/user_manager.php` |

## Responsive verification performed

The real-page matrix passed **324/324 checks with zero captured JavaScript page errors**: 54 role/route combinations, covering 48 distinct route paths, at **320, 375, 425, 768, 1024, and 1440px**, each 900px high. Authentication used the actual login form for Administrator, Super Administrator, Inventory Manager, and Cashier accounts in a disposable database.

Coverage includes dashboards, Store/Platform Settings, registers/shifts, users, audit/recovery/health pages, inventory/catalog/counts/purchasing, replenishment, promotions, forecasting/reports, historical sales/reversals/refunds, POS, profile/preferences/password pages, and notifications. Selected populated query variants exercise historical-sale and approved-request layouts rather than only empty pages.

Every matrix entry checks page overflow, content bounds, control bounds or containment in a horizontal scroller, and JavaScript errors. Interaction coverage includes:

- Mobile sidebar/backdrop; account workspace/preferences menus; command palette.
- Product drawer and all three tabs; add-product and column modals; filter expansion/collapse; next-page pagination with ten visible rows.
- User create modal and user drawer tabs; forecast analytics tabs.
- Actual POS quick-add and final-quote request, checkout review, cash input, dismissal, discard cancellation, and scrolling to/clicking the cart remove action.
- Actual notification form submission through partial navigation and confirmation that the unread row disappears.
- Overlay viewport bounds, layer hit-testing, body scroll lock, Escape dismissal, and scroll restoration.

Additional focused tests passed:

| Test in `src/backend/tests/` | Verified behavior |
| --- | --- |
| `responsive_components_browser_test.js` | Six widths at 844px and 420px heights; shared long modal/drawer forms, reachable actions, table scrolling/filtering/pagination, hidden rows, and scroll restoration. |
| `page_responsive_components_browser_test.js` | Production template excerpts for seven page families at all six widths, including counts, reversals, promotions, replenishment, and settings. |
| `overlay_lifecycle_browser_test.js` | Six widths; repeated initialization, confirmation cancellation/acceptance/retry, nested overlays, keyboard focus, and checkout/native-dialog/alert keyboard ownership. |
| `account_menu_position_browser_test.js` | Six widths plus 320x360; workspace/preferences panels stay within bounds and their triggers remain clickable. |
| `notification_responsive_browser_test.js` | Six widths; ordinary and long unbroken text, action reachability, and no page overflow. |
| `page_navigation_browser_test.js` | Initial/replacement IDs, persistent shell, script lifecycle, history, forms/uploads/downloads, failure/auth paths, competing requests, and overlay cleanup. |
| `product_drawer_layer_browser_test.js` | 1536px and 390px, light/dark, three open/close cycles; stacking hit-tests, transitions, focus, and scrolling. |
| `checkout_quote_browser_test.js` | Scoped async button bindings, quote review, submitting-state dismissal guard, and scroll locking. |
| `checkout_attempt_browser_test.js`, `cart_workspace_browser_test.js`, `drawer_movement_browser_test.js` | Existing affected checkout/cart/drawer behavior checks. |
| `sale_receipt_browser_test.js`, `sale_receipt_dialog_browser_test.js` | Responsive receipt preview, including 320px; 58/80mm print output; six-width receipt-dialog lifecycle and scroll restoration. |
| `fullscreen_preferences_browser_test.js` | Native/fallback fullscreen, mobile through desktop widths, resize/reload/manual exit, and storage failure. |
| `theme_mobile_browser_test.js`, `theme_login_browser_test.js`, `sidebar_loader_browser_test.js` | Existing mobile/theme/login/sidebar checks. |

JavaScript syntax checks passed for 50 application scripts. PHP lint passed for the seven edited PHP files. The generated stylesheet check, test-runner shell syntax check, and `git diff --check` passed.

Six new browser tests were added (the first five focused tests above plus `responsive_system_browser_test.js`) and registered in `run_all.sh`. Existing navigation, checkout, drawer, and receipt tests were updated for the verified behavior. Disposable theme/receipt fixtures were corrected to supply required schema fields; the theme fixture also seeds notifications for the matrix.

### Reproduction

Focused browser tests run with `node src/backend/tests/<test-file>.js` from the repository root. The real-page matrix additionally needs `RUN_DB_TESTS=1`, `PHP_BINARY`, and `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD` pointing at a disposable MySQL/MariaDB instance. It creates and drops its own randomly named `retailmind_theme_test_*` database; do not use production credentials.

Styles are generated with `php src/backend/scripts/build_styles.php`; validate them with the same command plus `--check`.

The final matrix's results and server log were written to `%TEMP%/rm-responsive-browser-DA6pPM/`. Temporary test services were used separately from the installed application's database.

## Verification limits

- Chromium only. Firefox and WebKit could not launch in this environment. Physical phones/tablets, mobile browser chrome/virtual-keyboard movement, and the original device's GPU artifact remain unverified.
- External CDN requests were blocked by the environment. The real-page matrix served empty CDN responses to exercise local CSS/JavaScript and fallback tables. CDN-provided DataTables sorting/filtering/pagination and SweetAlert2 rendering require a connected-browser check.
- Representative real forms and scoped confirmation/submission regression tests were exercised. The matrix did not complete payments, refunds, deletion, restoration, password changes, or email delivery against application data, and it does not click every action on every page.
- The complete `run_all.sh` suite was not passed. The existing `theme_account_browser_test.js` timed out waiting for an appearance control to be visible before opening its current account/preferences menu; its older selector flow remains to be updated. Python/vendor-dependent repository gates were not run.

## Follow-up: Android tablet, Products & Stock → Manage

The reported rectangular blur artifact remained unverified by the original desktop/headless checks. A touch-enabled, mobile-viewport check of the real product page also passed before this follow-up change; that result alone does not prove the Android hardware issue was fixed.

The shared product/modal/command/user drawers and checkout overlays no longer use live `backdrop-filter` blur. Their existing translucent dimming, placement, transitions, and interactions remain. This removes a GPU-dependent rendering path rather than adding more stacking overrides. Chromium has documented backdrop-image/filter size errors in its GPU renderer ([Chromium fix](https://chromium.googlesource.com/chromium/src/+/14e091ccc401d6807f03b5d1c10b17a8f53239bf)). That is a plausible mechanism for the screenshot, not a confirmed diagnosis of this tablet.

The Products & Stock stylesheet URL now includes its file modification time so a normal page request selects the current CSS version. The touch checks also reproduced a separate promotion-form overflow at 1194/1280px: inventory styles overwrote the shared form grid with columns that retained native date-input minimum widths. These grid columns and children can now shrink within their container.

Additional verification:

- **238 production-markup layout checks passed**: seven page families, desktop and touch/mobile emulation, at 320, 375, 425, 768, 800, 820, 834, 900, 901, 912, 960, 1024, 1180, 1194, 1280, 1366, and 1440px. The previously failing date fields stayed within their cards.
- **24 real-page/viewport checks passed** for Products & Stock and promotions with touch/mobile emulation and pixel ratio 2, at 768, 800, 820, 834, 900, 901, 912, 960, 1024, 1180, 1194, and 1280px. Manage uses an actual touch tap in this targeted run; its tabs, modals, pagination, and stylesheet-version assertion passed. Artifacts: `%TEMP%/rm-responsive-browser-5tXxSk/`.
- The extended product layer test **passed** at 768x1024, 834x1112, 1024x768, and 1280x800 touch viewports, alongside its desktop/mobile cases, in both themes with three repeated cycles. It tests touch opening/initial close, open-drawer rotation, background scroll locking, inner touch scrolling, repeated reopening, and Escape dismissal after swipes. Chrome's injected swipe sequence sometimes suppressed a subsequent synthesized tap even on a plain HTML scroller, so that sequence is not used to claim close-button behavior after momentum scrolling on hardware. Screenshots: `%TEMP%/product-drawer-layers-818D6H/`.
- Shared normal/short-viewport overlay/table tests, overlay lifecycle tests, stylesheet bundle equivalence/build checks, and product PHP lint passed again.

This follow-up changes `modals.css`, `cashier.css`, `inventory.css`, generated `style.css`, `products.php`, and the three existing responsive/product browser tests. No database or business logic changed. The exact Android-device artifact still requires checking on that device after the updated stylesheet loads.
