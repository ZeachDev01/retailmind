# Cashier POS tablet adaptation — 2026-11-18

Target: `src/frontend/components/cashier/pos.php`. Mode: Operate. Preserve the existing desktop identity and product-entry/current-sale arrangement.

## Verdict

The tested Chromium tablet viewports now retain two aligned, independently scrollable panes. No page-wide horizontal overflow was observed. Checkout stays reachable by scrolling the sale pane. Product cards adapt to available panel width, including piece/pack controls. The split applies at widths of 768px and above, and in landscape from 640px; narrower screens and portrait widths below 768px keep the checkout-first stack.

Implementation integrity: pass for this scoped adaptation. The change reuses the existing tablet layout, cart container query, touch controls, tokens and fullscreen behavior. No dependency, transaction logic, markup or global stylesheet bundle was changed.

## Findings and fixes

- **[P1, fixed] Tablet layout overridden by later CSS.** `cashier.css` ended with a single-column rule through 1100px, overriding the earlier tablet layout. The later desktop column declaration also superseded equal tablet columns through 1180px. Move the desktop declaration to the original grid rule and restrict the final stack to widths below 768px.
- **[P1, fixed] Narrow product cards could overflow.** Forcing three columns squeezed piece/pack controls at intermediate widths, including 1194px. Product grids now choose columns using their available width and minimum card width.
- **[P3, existing] Design-system drift advisories.** The detector reports existing literal color and type values elsewhere in `cashier.css`. No primary deterministic findings remain. These advisories are separate from the responsive defects and were not expanded into a visual redesign.

## Scoped audit scores

These scores describe the reviewed POS surface, not a full WCAG or performance certification.

| Dimension | Score | Evidence / limit |
| --- | --- | --- |
| Accessibility | 3/4 | Enabled POS controls measured at least 44px in both dimensions in the live matrix; quantity increment/remove exercised with synthesized touch. Full contrast and assistive-technology audits remain open. |
| Performance | 3/4 | CSS-only adaptation; no dependency or JS rendering work added. Real-device performance was not measured. |
| Theming | 3/4 | Live light/dark layouts passed; existing color/type advisories remain. |
| Responsive design | 3/4 | Portrait/landscape, fullscreen, overflow and checkout reachability passed; physical iPad Safari and on-screen keyboard behavior remain unverified. |
| Implementation integrity | 3/4 | Shared patterns retained; detector has no primary findings, with existing advisories as above. |
| Total | 15/20 | Good; physical-device acceptance remains necessary. |

## Verification

- `node src/backend/tests/pos_tablet_layout_browser_test.js` — passed. Updated the existing harness for current cart dependencies and delegated event handlers; source extraction now asserts valid boundaries.
- Persistent layout matrix: 768×1024, 820×1180, 834×1194, 1024×768, 1100×800, 1180×820, 1181×820, 1194×834, 1366×1024, 1440×900, 767×1024 and 390×844; normal/fullscreen. Includes 24 products, eight cart rows, empty cart, compact/default cards, piece/pack availability, 44px product targets, quantity alignment, independent scroll, checkout reachability and synthesized touch increment/remove.
- Live PHP page against a disposable `retailmind_theme_test_*` database: **44 combinations passed**. Viewports: 768×1024, 820×1180, 834×1194, 1024×768, 1100×800, 1180×820, 1181×820, 1194×834, 1366×768, 1920×1080 and 390×844; light/dark × normal/fullscreen. Actual shared navigation shell included. No page JS errors; enabled POS targets were at least 44px; touch increment changed quantity from 1 to 2.
- `npm run check` — passed; existing duplicate `open-register` ID warning in `cashier/shifts.php` remains outside this change.
- `node src/backend/tests/pos_cart_quantity_test.js` — passed.
- `node src/backend/tests/pos_keyboard_scan_browser_test.js` — passed.
- `node src/backend/tests/pos_mixed_form_cart_test.js` — fails in its existing DOM mock: `TypeError: document.getElementById(...)?.addEventListener is not a function`. Production JavaScript was not changed. This test is not counted as passing.
- Impeccable detector, `cashier.css`, `--no-advisory --quiet` — passed.

## Rendered evidence

Synthetic fixture content only. Screenshots show the live PHP page in Chromium, not physical hardware.

- [iPad mini portrait, 768×1024](evidence/pos-tablet-2026-11-18/ipad-mini-portrait.png)
- [Tablet landscape, 1024×768](evidence/pos-tablet-2026-11-18/ipad-landscape.png)

## Follow-up: preserve the split on small landscape screens

The tablet pane sizing and independent scroll rules also apply from 640px in landscape. Landscape widths through 1180px use 12px panel padding and gaps to leave more working space without shrinking text or touch controls. At 800×480 the synthetic 24-product fixture displays two catalog columns in the normal grid; at 640×360 the catalog uses one column while products and Current sale remain side by side. Short screens require vertical scrolling inside each pane; all content is not simultaneously visible.

- The persistent layout test passed again, adding 640×360, 720×480, 800×480, 960×600 and 1024×600, in normal/fullscreen, with populated and empty carts, compact cards, piece/pack controls, and checkout access. Existing portrait and desktop cases still pass.
- The live PHP matrix passed **64 combinations**: the original eleven viewports plus those five landscape sizes, each in light/dark and normal/fullscreen. No page JS errors or page-wide horizontal overflow; measured enabled POS controls remained at least 44px. Synthesized touch increment passed.
- `npm run check` and the detector passed again. The existing Shifts duplicate-ID warning remains.
- TestSprite was not rerun for this CSS follow-up: the preceding run failed before checkout at its navigation assertion, documented below. It does not verify this revision.
- [Live PHP, 800×480](evidence/pos-tablet-2026-11-18/small-landscape-800x480.png)
- [Live PHP, 640×360](evidence/pos-tablet-2026-11-18/small-landscape-640x360.png)

## TestSprite

The existing standard-browser POS checkout case was run against the disposable Store, with synthetic TEST-A/TS10 product data and an open test Fiscal Period. This is separate workflow coverage and does not certify a tablet viewport.

Run: `9a05ff87-a61e-44a9-a13c-f72fa0c6104b`. **Failed before checkout**, at the first workspace/navigation/overflow assertion after successful login and two navigation-button clicks. Eight action steps passed; the checkout steps were not reached. The final recorded video frame shows Point of Sale, its desktop navigation, products and current-sale pane. This does not establish the runner's asserted wrong-workspace/overflow diagnosis; the local live layout measurements above passed. Do not count this as a passing end-to-end checkout run.

Pinned run steps and the video were inspected. The artifact endpoint refused the failure bundle with `Resource not found`, stating that the stored script was withheld because its environment was edited or deleted after the run started. The run's checkout coverage remains unverified.

[TestSprite case dashboard](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/test-cases/7cf528f3-b490-4445-98c3-d389c8d6fb2f)

## Remaining acceptance

Check a physical iPad in Safari in both orientations, with the on-screen keyboard open, including swipe scrolling in each pane. Chromium viewport emulation and synthesized taps do not establish Safari gesture, scanner, receipt printer or hardware performance acceptance. No live Store transactions were used.
