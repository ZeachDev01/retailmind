---
version: 1
slug: "src-frontend-components-report-stock-receiving-php"
primary_target: "src/frontend/components/report/stock_receiving.php"
related_targets: ["src/frontend/assets/css/stock-receiving.css","src/frontend/assets/css/reports.css"]
---

# Stock Receiving

Mode: Operate. Inventory Managers scan/search, enter delivered quantities, review and save once. Preserve the existing RetailMind shell, tokens, product identity, replacement links, encrypted recovery and stock transaction boundaries.

## Direction contract

THESIS: Variant A keeps selection and quantity entry in one editor; Review is the only separate stage.

OWN-WORLD: Existing navy navigation, Action Blue controls, semantic states, system UI typography and compact bordered work panels.

STORY: Add to delivery selects a product. Inline quantities calculate accepted base units. Save receiving & update stock commits only after Review.

FIRST VIEWPORT: Searchable catalog on the left and compact product rows on the right. One product can open for quantity, lot/expiry and batch editing, with receipt details and Review below. Narrow screens stack these areas. Focus moves to the visible quantity after a completed scan; rescans retain values.

FORM: User-selected split catalog within the established interface. seed: none (user-selected direction).

FINISH: Record the finish review and verification here. This ordinary extension preserves docs/DESIGN.md and .impeccable/design.json; it introduces no shipping rasters.

## Verification

Focused coverage and limitations: docs/verification/inline-receiving-479.md. No new global tokens, dependencies or shipping image assets.

## Documented extension — #479

The finished editor retains the incumbent navy shell, Action Blue interaction, semantic stock states, system UI typography, theme surfaces, bordered panels and quiet depth. The composition extends the existing receiving surface rather than changing the visual system: catalog and delivery lines share the desktop editor, receipt details follow the lines, and Review remains separate. Batch / lot and expiry stay visible together on desktop and stack on narrow screens.

Compared against docs/DESIGN.md, the implementation and captures preserve the established typography, material and worksurface language. No visual-system drift was identified at this surface scope. Independently reviewed evidence: output/issue-479/editor-1440-light.png, editor-1440-dark.png, editor-600-light.png and editor-390-dark.png. The date-icon contrast finding was resolved by correcting capture theme setup to mirror production theme.js, including color-scheme; the corrected dark capture shows a readable calendar icon. The finish reviewer confirmed that finding resolved.

The verification note records functional/browser coverage and the physical-scanner/device limitation. This documentation pass checked the source and captures; it did not rerun that suite. Captures are verification evidence, not shipping assets. The 1,001-product fixture produces a long stacked catalog on narrow screens; scan focus moves to the visible quantity as specified, but physical-device operation remains unverified.

## Independent batches — #480

Each product has one group with a calculated accepted total and independent batch rows. Add another batch uses the existing secondary action and form controls. Review lists each batch and offers Current product cost only when known costs differ. Group headings, dividers, system typography, theme surfaces and focus treatments extend the incumbent interface without changing global tokens or assets. Desktop/narrow light/dark captures and functional verification are recorded in `docs/verification/independent-receiving-batches-480.md`; evidence is under ignored `output/issue-480/`.

## Code recovery — #481

An inline recovery section below scan status uses the existing form typography, secondary actions, theme border and Action Blue focus. Unknown codes require explicit packaging classification; existing cartons show saved-size confirmation with base-unit and configuration recovery. Native radio labels keep 44px targets, actions wrap on narrow screens, and programmatic focus remains visible. No global tokens, dependencies or shipping assets were added. Final 1440px/dark and 390px/light confirmation captures were inspected against the incumbent system; classification coverage also includes both themes and reduced motion. Verification, independent review fixes, the failed TestSprite status and device limits are recorded in `docs/verification/receiving-code-recovery-481.md`; captures are under ignored `output/issue-481/`.

## Compact delivery lines

Each product row keeps the entered quantity and accepted total visible when folded. Multiple batches show their count and accepted total. The open editor keeps lot and expiry together below quantity. Missing or invalid quantity stays visible on the row, and opening it takes staff to the field that needs attention. Scanning or adding opens the corresponding product, and folding a row retains its batch entries.

Playwright checks quantity summaries, keyboard focus, retained inputs, reordering, and viewport fit. Compact-row captures are under ignored `output/playwright/stock-receiving/`. The existing receiving and rescan flow passed [TestSprite](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/test-cases/86af457c-c7b6-40b8-ac12-7d222e6053b5).
