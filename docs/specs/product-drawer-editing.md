# Product drawer editing

Status: Expanded design agreed on 2026-10-17 and published as implementation tickets. Not implemented.

## Implementation tickets

- [#212: Edit product catalog details](https://github.com/ZeachDev01/retailmind/issues/212) - no blockers.
- [#213: Edit selling price](https://github.com/ZeachDev01/retailmind/issues/213) - blocked by #212.
- [#214: Deactivate and reactivate products](https://github.com/ZeachDev01/retailmind/issues/214) - blocked by #212.
- [#215: Edit packaging and stock planning settings](https://github.com/ZeachDev01/retailmind/issues/215) - blocked by #212.

## Purpose and access

Allow routine catalog corrections without leaving the Products drawer. Opening a product remains read-only. Only the active Inventory Manager workspace may enter Edit product or save changes; enforce the same restriction on the server. Administrator and Super Administrator workspaces retain inspection access.

## Editing

- Edit product enters an explicit edit mode with Save and Cancel.
- Cancel discards the draft. Warn before closing or otherwise leaving an unsaved draft.
- Save validates the changes, persists them, and returns the drawer to read-only mode with updated values. Failed saves retain the draft and show actionable errors.
- Editable product details: product name, variant label, brand, category, product image, cost price, selling price, and active/inactive status.
- Editable stock settings: Units per Package/Case, Minimum Order Quantity, Reorder Level, and Safety Stock. These are settings, not adjustments to Current Stock.
- Variant-label edits correct an existing variant of the same physical product. Preserve its parent product and identity; a different size or physical item requires a separate product. Retain the existing variant-label requirements and 100-character limit.
- SKU, barcode (including case barcode), parent product, batch expiration, current quantity, Base Unit, and Receiving Unit remain outside this editor. Keep existing Stock Receiving, inventory count, and stock adjustment workflows.

## Stock settings

- Units per Package/Case is a positive whole-number count of Base Units in one Receiving Unit.
- Changing Units per Package/Case affects future receiving conversions and replenishment rounding. Preserve current stock in Base Units and the conversion snapshots on recorded receiving history. For example, changing 12 to 24 leaves 120 cans on hand unchanged; the next received case adds 24 cans.
- Minimum Order Quantity is a positive whole-number count of Base Units, not cases. Existing replenishment rounding still rounds the minimum up to a package multiple.
- Reorder Level and Safety Stock are whole-number counts of Base Units, each at least zero.
- Validate these four settings on the server; reject fractions, malformed values, out-of-range values, and values below their minimum instead of silently truncating or clamping them.
- Keep existing stock-status and replenishment formulas. Saved settings apply when those views next load; edits do not move stock, generate forecasts, or retrain models. In the existing forecast-backed path, Reorder Level and Safety Stock do not replace the forecast-derived quantity target.

## Price and status rules

- Cost price may be edited to PHP 0 or more and is rounded to two decimal places. Catalog corrections preserve receiving records, batch costs, and transaction history; later Stock Receiving may replace the catalog cost.
- Selling price must be greater than PHP 0. A price below the latest purchase cost is allowed with a warning.
- New checkout uses current prices. Preserve completed sales and receipt details, consistent with [ADR-0006](../adr/0006-preserve-customer-receipt-details.md).
- Retain the existing held-sale rule: holding reserves neither stock nor price. Resuming uses current prices and reports changes; checkout validates current prices and active status.
- Deactivation blocks future sales, including sales from older carts. Preserve stock, product records, and transaction history; allow reactivation.

## Implementation constraints and checks

Reuse existing authorization, CSRF protection, catalog validation, image handling, and drawer conventions. Validate editable fields on the server and restrict updates to those fields. Preserve unrelated product data and historical receipt snapshots.

Verify save/cancel, unsaved-draft exit, failed-save recovery, workspace enforcement, zero/negative/invalid price rejection, allowed below-cost pricing with its warning, deactivation/reactivation, held-sale refresh, and completed-receipt preservation. Check keyboard access and narrow-screen editing.

Also verify variant correction without changing identity, strict whole-number stock-setting validation, updated stock-status and replenishment displays, MOQ/package rounding, and future receiving conversion while preserving current stock and recorded multipliers. Check receiving forms opened before a package-size change: receiving currently uses the saved multiplier at submission, so preview consistency needs explicit verification.

## Existing limitation outside this edit

The drawer currently reads the product-level expiration date, which receiving can overwrite with a later receipt's date even when older batches remain. Batch expiration is outside this editor; deriving an earliest remaining-stock expiration is a separate improvement, not an agreed part of this scope.

## Documentation

Existing domain terms and role boundaries apply. No new glossary term or ADR is needed for this reversible editor design.
