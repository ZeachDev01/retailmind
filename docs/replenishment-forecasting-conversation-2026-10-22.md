# Replenishment forecasting conversation — October 22, 2026

This is a summary of the conversation and its decisions, not a verbatim transcript. The discussion is unfinished; no receipt extraction or model implementation has been performed.

## Original idea

Use receipt images as the available dataset. Extract text into CSV, categorize products using Jev AI, identify repeated category purchases, and forecast categories likely to appear in future replenishment. Identify useful training fields and use reliable evaluation before retaining or replacing the existing forecasting model.

The original December example meant a future period where categories such as Beverages and Snacks might recur. It was an example, not evidence of an established seasonal pattern.

## Clarified source and purpose

- One store owner buys inventory from several larger retailers.
- The receipts document purchases for that one store's inventory replenishment, not sales to its customers.
- All supplied receipts are for replenishment; personal-purchase filtering is not currently needed.
- The feature provides decision support to the Inventory Manager: a heads-up about categories likely to appear in future replenishment purchases, plus insights into previous spending.
- The first forecast should cover the next calendar month. Weeks were also discussed as a possible extension.
- Count each category once per receipt, even if several product lines belong to it.
- Show up to five categories in the heads-up, with fewer when the evidence supports fewer. The user reaffirmed that this is decision support for replenishment.
- A valid purchase remains a category purchase even if some or all goods are returned later. Record returns separately for spending totals; they do not erase the buying decision.
- Reuse existing product categories; add a category only when no existing category fits the product.
- Exclude cancelled purchases and correct data-entry mistakes before forecasting.
- Retain stock-rule buying-quantity suggestions using current stock, reorder level, safety stock, MOQ, and package size, with the Inventory Manager deciding the final quantity. This does not require sales-history forecasting.
- Count safety stock only once in stock-rule suggestions. The user approved correcting the duplicate-buffer design; no code has been changed.
- Purchases by the store are evidence of replenishment behavior. They do not directly establish what customers bought or wanted.

## Available receipts

- On October 23, 2026, the user revised the available count to approximately 12 replenishment receipts, replacing the earlier estimate of 30. The collection is still incomplete; coverage questions remain deferred until it is ready.
- Some receipts contain 40 or more product lines.
- Latest currently confirmed receipt date: September 16, 2026.
- Another person holds additional receipts. The collection and its date range are not yet confirmed complete.
- Earliest receipt date and missing receipt coverage remain unknown.
- The user will start recording the receipts currently available. This does not confirm that the full collection is ready.
- Many lines on one receipt still represent one shopping event; they do not provide independent months of history.

## Deferred reminder

The user asked to revisit receipt coverage when all receipts are in hand. When the user says the collection is ready, ask:

1. What are the earliest and latest purchase dates across the complete collection?
2. Are any replenishment receipts missing between those dates?

Do not assume September 16 remains the latest date after the other receipts arrive. This question matters for monthly forecasting, testing on later periods, and seasonal claims. It does not block extracting receipts already available. No timed reminder was scheduled.

## Extraction and categorization

Recommended first-batch option: Excel for Microsoft 365's Data > From Picture > Picture From File. Review the extracted table, correct errors, arrange the approved fields, and save as CSV UTF-8. Receipt layouts may need manual cleanup; this is a recommendation, not a tested extraction result.

Jev is the TypeSafe AI model described in the link supplied by the user:

- [Introducing System One Models and Jev](https://typesafe.ai/blog/introducing-system-one-models-and-jev)
- [Microsoft: Insert data from picture](https://support.microsoft.com/en-us/excel/insert-data-from-picture)

Use Jev to suggest a category from a fixed category list after text extraction. The user agreed that the same product should keep the same category across receipts, and that extracted data and category assignments will receive human review before entering the approved CSV dataset. Structured outputs do not guarantee a correct category. Jev access and its performance on these products have not been tested.

## Data to preserve

The user asked to retain useful columns for future testing and improvement. Keeping a field does not mean it should automatically become a training input.

Proposed format: two linked CSV files, one row per receipt and one row per product line. This avoids repeatedly counting receipt totals for every item.

- Essential recurrence evidence: purchase date, receipt ID, inventory-owning store ID, reviewed category.
- Product matching: original product description, retailer product code or barcode when present, consistent product ID and name.
- Spending: line totals, currency, and receipt totals.
- Additional useful details: quantities, pack sizes, units, brand, variant, unit prices, discounts, taxes, retailer and branch.
- Review evidence: source images, original OCR text, extraction provenance, AI category suggestion and confidence when supplied, approved category, and review notes.
- Preserve missing values as unknown; do not invent prices, quantities, pack conversions, confidence scores, or zero purchases.

The full proposed field list is in the linked design notes below. No CSV files have been created yet.

## Meaning of category spending

Category spending is the money the store spent purchasing products in a category during a period. It is historical purchasing information, not customer sales revenue or automatically a future spending prediction.

Illustrative example only: Beverages PHP 5,000, Snacks PHP 3,000, Household Supplies PHP 2,000. Beverages account for 50% of the PHP 10,000 replenishment spending.

## Evaluation discussed, not yet finalized

- Predict later periods using only information available earlier; do not randomly split product lines from receipts.
- Compare against simple baselines, such as previous category purchase frequencies.
- Proposed probability measure: Brier score, with calibration checks.
- Proposed heads-up list measures: precision and recall at the selected list length.
- Report category-specific results so frequent categories do not hide poor results elsewhere.
- Evaluate OCR extraction and category assignment separately against human-reviewed data.
- Evaluate any future numerical spending prediction separately, for example using currency-based MAE.
- Do not replace the existing model merely because a newer model exists. Compare candidates fairly against baselines on the same later periods.
- Thirty receipts can support initial data organization and exploration; forecast reliability depends on date span, completeness, and available evaluation periods. December seasonality cannot be assumed from the receipt count alone.

## Existing system findings

Read-only inspection found a per-product Random Forest forecasting pipeline with 7-, 14-, and 28-day horizons. The existing sales-history CSV import requires sale_date, sku, and quantity. Those are customer-sales inputs; replenishment purchases must not be relabelled as sales and imported into that pipeline.

The current design uses chronological evaluation and sales-quantity metrics. Those metrics do not establish category-occurrence forecast accuracy. The decision about how the new feature coexists with or replaces the existing pipeline remains open; no production forecasting decision has been superseded.

## Remaining decisions

- Complete receipt date span and coverage, deferred until the remaining receipts arrive.
- Review category coverage against the receipts; reusing existing categories and a heads-up limit of up to five categories are agreed.
- Model choice, evaluation windows, and useful improvement thresholds once data is inspected.
- Treatment of incomplete coverage; cancelled purchases are excluded, errors corrected, and returns do not erase valid purchase decisions.
- The user rejected keeping the sales-history forecasting approach alongside this feature, citing insufficient historical data. Retaining stock-rule quantity suggestions with the Inventory Manager deciding the final quantity is agreed. Settle the exact retirement scope; ADR 0005 has not yet been superseded and no implementation has occurred.
- Finalize the stock-rule target and formula using the agreed rule that safety stock counts only once. Current code still adds safety stock to reorder level, while the glossary says reorder level already includes safety stock; implementation remains pending.
- Final confirmation of the complete design before implementation.

## Related records

- [Glossary](../GLOSSARY.md): Replenishment Receipt and Category Replenishment Forecast.
- [ADR 0016: distinguish replenishment patterns from customer demand](adr/0016-distinguish-replenishment-patterns-from-customer-demand.md).
- [Proposed data fields and evaluation plan](specs/replenishment-receipt-forecasting.md).
- [Existing forecasting architecture](adr/0005-single-random-forest-forecasting-pipeline.md).

Communication preference from this discussion: simple English.
