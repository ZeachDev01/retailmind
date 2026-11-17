# Receiving code recovery — #481

Receiving now resolves item, receiving-case, Sales Pack and SKU identifiers through the existing authenticated read-only lookup. Its explicit barcode type takes precedence over an overlapping SKU; item/SKU equality remains units. Conflicting barcode types require manual resolution. Default lookup and POS precedence are unchanged.

Inactive matches return a separate outcome without product details or candidates. Receiving offers the existing authorized Products workflow, retry and manual selection. Lookup failures never become unknown products. Nothing in lookup or manual selection writes identifiers, reactivates products or changes stock.

Only unknown codes show the three packaging choices, with no default. Back and Cancel retain the code; Find existing product allows catalog search. Receiving cases explain the outer-carton/item-barcode distinction. Sales Packs explain their separate size, barcode and price. This ticket supplies classification and manual recovery; dependent create-and-return work remains separate.

Unknown cartons and Sales Packs require confirmation of their saved size. Confirmation refreshes current product data and requires another confirmation if relevant configuration changed. A mismatched or uncertain carton can use actual base units or the existing product configuration workflow. An explicit different-mode selection creates a blank independent allocation while preserving existing quantities. Existing allocations keep their prior conversion until refreshed Review detects and acknowledges any change. Known registered packaging bypasses unknown-size confirmation.

Encrypted account-scoped drafts retain the code and explicit classification. Resume requires product selection and conversion confirmation again. Delivery values, existing mode counts and stock-save safeguards remain intact.

## Verification

- `receiving_code_recovery_browser_test.js`: isolated MySQL plus authenticated HTTP/browser coverage for all identifiers, SKU overlap, legacy type/product ambiguity, inactive redaction, unauthorized Cashier access, lookup/network errors, retry, cancellation, empty classification, quantity preservation, a 12-unit unknown carton versus saved 24, changed-size reconfirmation, existing-allocation stale Review, independent base-unit recovery and encrypted resume.
- `stock_receiving_empty_catalog_browser_test.js`: an empty active catalog still permits unknown-code classification without selecting a default.
- `npm run check`, `npm run check:contracts`, POS lookup, Sales Pack receiving and keyboard scanning passed. PHP and JavaScript syntax checks passed; this repository has no separate TypeScript typecheck script.
- Desktop/narrow light/dark and reduced-motion captures are under ignored `output/issue-481/`. Final confirmation captures at 1440px/dark and 390px/light were visually inspected; browser geometry confirms no horizontal overflow and visible focused recovery controls.

The final `npm run test:e2e` passed in full against a disposable database: Inventory Counts, stock receiving, inline receiving, code recovery, empty catalog, forecast decisions, sidebar labels and Remote Access QR. An initial run stopped on the pre-existing configured database's open-fiscal-period fixture collision. The empty-catalog expectation was then updated to cover the intentionally enabled scan field. The final run exited 0; its log is under ignored `output/issue-481/e2e-final.log`.

The standalone `product_code_lookup_contract.php` still fails its two historical out-of-Store assertions. Both failures were reproduced against the unchanged starting commit `0e7c1c7`; this ticket does not change Store scoping. The view audit retains its unrelated duplicate `open-register` ID warning in `cashier/shifts.php`.

## TestSprite

[Run 44049c3d](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/d26f11ec-2b52-46dd-9339-c80a1db6e9e8/test/44049c3d-6d1a-42c2-9c69-21320e592615) reported **failed** on the compound assertion for unchecked packaging choices and retained quantity. Its failing-step HTML was inspected: all three radio inputs lack `checked`, and the active `received_packages` input retains `value="2"` (48 accepted units). The captured template also contains an unused empty input. Local browser assertions verify the active controls directly. TestSprite is not counted as passing. The artifact bundle endpoint withheld the stored script with `environment_changed`; step HTML remained available and was inspected.

## Standards

The independent review found no hard violations. Its copy advisory was resolved by shortening the saved-size prompt. Follow-up: no remaining findings.

## Spec

The independent review found two conversion issues: confirmation could overwrite shared conversion state before Review, and explicit base-unit recovery could focus an existing case field. Both were reproduced with browser regressions and fixed. Follow-up: no remaining findings.

Final review: Standards 0 remaining; Spec 0 remaining. Physical scanners and real mobile devices were not tested.
