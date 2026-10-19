# Product drawer editing

Status: Expanded design agreed on 2026-10-17 and published as implementation tickets. Not implemented.

Stock-planning update agreed on 2026-10-20: [Stock-based Replenishment Recommendations](stock-based-replenishment-recommendations.md) is authoritative for Target Stock, trigger semantics, purchase quantities, rounding, the selected table, and current stock-only guidance. Its rules supersede the earlier formula-preservation requirements below.

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

### Definition update agreed on 2026-10-20

Reorder Level includes expected demand while waiting for replenishment plus Safety Stock. For example, demand of 15 bottles while waiting plus 5 backup bottles gives a Reorder Level of 20. Safety Stock remains saleable; editing either setting does not move stock.

Safety Stock must not exceed Reorder Level. This cross-field validation is agreed but not yet implemented. The Inventory Manager chooses the actual purchase quantity; system recommendations remain guidance and do not require buying the suggested quantity.

Add an Inventory Manager-editable Target Stock setting per product, expressed as a whole-number count of Base Units strictly greater than Reorder Level. Validate this relationship when either setting changes. At or below Reorder Level, the suggested purchase starts from Target Stock minus current stock; above Reorder Level, no stock-rule purchase is suggested. Safety Stock is already included in the trigger and is not added again to the purchase quantity.

Suggest individual Base Units without forcing whole-case rounding. Show a case-and-loose-unit breakdown as guidance, such as 45 bottles = 3 cases of 12 plus 9 bottles. When replenishment is needed, suggest the larger of the stock deficit and Minimum Order Quantity; do not round to a multiple of the minimum. For example, a deficit of 45 with a minimum of 50 suggests 50. A minimum of 1 represents no additional purchase minimum. Explain when the minimum causes the suggestion to exceed Target Stock. Above Reorder Level, the minimum does not create a purchase suggestion.

For a product without Target Stock, show "Set target stock" instead of inventing a suggested purchase quantity. Preserve its actual stock status so missing configuration does not hide an out-of-stock or low-stock condition.

Use stock-based suggestions for current operations, with direct explanations showing current stock, reorder level, target, and suggested purchase. Forecast-based suggestions remain a future option; do not build a new forecasting selector or automatically switch the current buying guidance to forecasts as part of this design.

These decisions supersede preservation of the existing threshold formulas below. Implementation is pending: stock displays currently use differing triggers, and stock-rule replenishment adds Safety Stock to Reorder Level. The application does not yet implement Target Stock.

- Units per Package/Case is a positive whole-number count of Base Units in one Receiving Unit.
- Changing Units per Package/Case affects future receiving conversions and replenishment rounding. Preserve current stock in Base Units and the conversion snapshots on recorded receiving history. For example, changing 12 to 24 leaves 120 cans on hand unchanged; the next received case adds 24 cans.
- Minimum Order Quantity is a positive whole-number count of Base Units, not cases. Existing replenishment rounding still rounds the minimum up to a package multiple.
- Reorder Level and Safety Stock are whole-number counts of Base Units, each at least zero.
- Validate these four settings on the server; reject fractions, malformed values, out-of-range values, and values below their minimum instead of silently truncating or clamping them.
- Keep existing stock-status and replenishment formulas. Saved settings apply when those views next load; edits do not move stock, generate forecasts, or retrain models. In the existing forecast-backed path, Reorder Level and Safety Stock do not replace the forecast-derived quantity target.

## Selected replenishment table layout

The user selected the full table below over the compact shopping-guide alternatives. Preserve these columns and show out-of-stock, low-stock, and in-stock products, including products with an unset target. Values are illustrative; package examples use 12 bottles per case.

| Priority | Product | On hand | Reorder at | Target | Suggested purchase | Why |
| --- | --- | --- | --- | --- | --- | --- |
| Out of stock | Sparkling Water 330 mL | 0 | 12 | 48 | **48 bottles** · 4 cases | Buy 48 to reach target |
| Out of stock | Orange Soda 500 mL | 0 | 12 | Not set | **Set target stock** | Target needed |
| Low stock | Bottled Water 500 mL | 15 | 20 | 60 | **45 bottles** · 3 cases + 9 loose | Buy 45 to reach 60 |
| In stock | Cola 330 mL | 36 | 20 | 60 | **No purchase needed** | Above reorder level |

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
