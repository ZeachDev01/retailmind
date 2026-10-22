# Replenishment receipt forecasting: design discussion

Status: In discussion. Agreed scope is recorded in ADR 0016; the remaining proposals below are not an implementation specification.

## Agreed scope

- Source: receipts issued by larger retailers for inventory the Store buys.
- User: Inventory Manager.
- Purpose: advance notice of likely future replenishment categories, for weeks and months, with historical spending insights.
- Category occurrence: count a category once per receipt, regardless of the number of matching lines or units.
- Categorization: use Jev to suggest a category from a defined list; retain a consistent reviewed category for the same product.
- Review: a human checks extraction and categorization before approving the CSV data.
- Preserve useful source details for future experiments. Retaining a column does not mean using it as a model input.
- Available archive: approximately 12 replenishment receipts, as clarified by the user on October 23, 2026, replacing the earlier estimate of 30. The collection remains incomplete. Some receipts contain 40 or more product lines. Different issuing retailers supply one inventory-owning Store.
- First output: categories likely to appear in next month's replenishment purchases.
- Heads-up length: up to five categories, with fewer shown when the evidence supports fewer. This remains decision support for replenishment.
- A valid purchase still counts as a category purchase even if some or all goods are returned later. Record returns separately for spending totals; they do not erase the original buying decision.
- Reuse existing product categories. Add a category only when the product does not fit any existing category; a different product alone does not require a different category.
- Exclude cancelled purchases and correct data-entry errors before forecasting. Completed purchases followed by returns still count.
- Retain stock-rule quantity suggestions using current stock, reorder level, safety stock, minimum order quantity (MOQ), and package size. The Inventory Manager reviews and decides the quantity to buy; no sales-history forecast is required for this suggestion.
- Count safety stock only once in stock-rule suggestions. The user approved avoiding the duplicate buffer in the current fallback calculation; implementation remains pending.
- All supplied receipts are for Store replenishment; personal-purchase filtering is not currently required.
- Latest currently confirmed receipt: September 16, 2026. Additional receipts are with another person, so the complete date range remains unknown.

## Proposed capture format

Use two linked CSV files to avoid counting receipt totals once per item. Keep the original images. These are proposed columns, not a claim that every retailer prints every field.

### receipts.csv: one row per receipt

| Columns | Meaning |
| --- | --- |
| receipt_id | Stable internal ID joining receipt items; preserve leading zeros in identifiers. |
| store_id | Store receiving the inventory; not the retailer that issued the receipt. |
| retailer_name, retailer_branch | Seller and branch, when printed. |
| retailer_receipt_number | Printed identifier; may need retailer, branch, and date to identify a duplicate. |
| purchase_date, purchase_time | Actual purchase date as YYYY-MM-DD; time only when available. |
| currency | Printed or explicitly confirmed currency; do not silently assume it. |
| printed_subtotal, printed_discount_total, printed_tax_total, printed_grand_total | Printed values as labelled; preserve their tax/discount meaning. |
| transaction_type, related_receipt_id | Purchase, return, or other reviewed type; original receipt link when available. |
| source_image_files, raw_ocr_text | Image references and original extracted text; quote CSV text containing commas or newlines. |
| extraction_tool, extraction_version, extracted_at | Extraction provenance; blank version if unavailable. |
| review_status, reviewed_at, review_notes | Approval and unresolved discrepancies. |

Do not collect payment-card numbers or customer identifiers for this task. Missing values remain blank, not zero. Do not force printed totals to reconcile using an assumed tax formula.

### receipt_items.csv: one row per printed product line

| Columns | Meaning |
| --- | --- |
| receipt_id, line_number | Link to the receipt and unique line position within it. |
| raw_product_description | Exact product text retained from the source. |
| retailer_product_code, barcode | Printed identifiers if present; retailer codes need not be shared across sellers. |
| product_id, canonical_product_name | Reviewed identity reused across receipts; do not merge different sizes or variants just because names resemble each other. |
| brand, variant, pack_size, pack_size_unit | Only printed or verified product attributes. |
| quantity, purchase_unit, units_per_pack | Purchase quantity and its meaning; unknown pack conversions remain blank. |
| printed_unit_price, printed_line_discount, printed_line_tax, printed_line_total | Amounts with the source's meaning retained; optional when not shown. |
| suggested_category, category_confidence, categorizer_model, category_list_version | Jev suggestion and provenance; confidence only if supplied, not invented. |
| category_id, category_name | Final reviewed category, kept consistent for the same product. |
| is_inventory_purchase | Reviewed flag to exclude personal purchases or non-inventory charges where relevant. |
| review_status, review_notes | Unclear lines, category corrections, and extraction issues. |

Keep category definitions and approved product mappings with the dataset. A deliberate category correction should be applied consistently, with its history retained.

## Most important data

For category recurrence, essential evidence is purchase date, receipt identity, reviewed category, and which Store the receipt belongs to. Product descriptions and stable product identity support correct category assignment and repeat-product matching. Line amounts and currency support historical category-spending insights; quantities, pack details, discounts, and seller details support later experiments.

Track the receipt archive's coverage separately: earliest/latest date, known missing trips or receipts, and which periods have been checked as complete. No receipt in an incomplete period means unknown, not confirmed no purchase. Thirty product lines from one trip remain one receipt event, not thirty independent replenishment dates.

## Proposed derived inputs and labels

- Candidate inputs: prior category occurrence rate, days since last category purchase, previous receipt counts, previous category spend, and calendar fields.
- Generate these only from purchases available before the forecast date. Future receipt totals, categories, quantities, and Jev classifications are outcomes, not inputs for an earlier forecast.
- Keep purchase history and approved extraction/review timestamps separate; a retrospectively reconstructed experiment must be labelled as such.
- First output: probability each category appears at least once in the next calendar month. Weekly forecasts remain a possible later extension; predicting only the next shopping trip is a different target.
- Monthly historical spending summaries do not require a forecasting model. Future spend prediction would be a separate numeric target.
- Do not sum incomparable quantities such as beverage cases and snack sachets as a category demand measure.

## Proposed evaluation

- Evaluate later periods using only earlier history, with separate weekly and monthly results; do not randomly split item rows.
- Establish simple baselines: past category frequency and the latest period's category presence; seasonal comparisons only where history supports them.
- For category occurrence probabilities, use Brier score and inspect calibration; for a short ranked heads-up list, report precision and recall at the chosen list length. Report per-category results so common categories do not hide failures.
- Category-spend predictions, if approved later, need separate currency-based MAE and baseline comparison. Existing SKU-unit MAE/WAPE do not validate category-occurrence predictions.
- Decide test windows, useful improvement thresholds, and any model replacement after confirming the archive's date span and completeness. A model must beat the baseline on the same future periods; no default commitment to Random Forest or a more complex replacement.
- Evaluate OCR and Jev separately against reviewed receipt fields and category labels. A valid category choice can still be the wrong category.
- With a small or short archive, report descriptive patterns and limited evidence rather than unsupported seasonal certainty. More item columns cannot create more independent months of history.

## Extraction starting point and sources

For a manually reviewed first batch, try Excel for Microsoft 365: Data > From Picture > Picture From File, review and insert the result, then arrange the approved data in the proposed tables and save each as CSV UTF-8. The feature targets tabular images; receipt layouts may require substantial cleanup. It does not automatically identify all proposed fields.

- [Microsoft: Insert data from picture](https://support.microsoft.com/en-us/excel/insert-data-from-picture)
- [TypeSafe: Introducing System One Models and Jev](https://typesafe.ai/blog/introducing-system-one-models-and-jev): defines structured choices and probabilities; vendor claims do not establish accuracy on this Store's products or make Jev an OCR tool.
- [Forecasting: Principles and Practice: time series cross-validation](https://otexts.com/fpp3/tscv.html)

## Open decisions

- Deferred until the user has the complete receipt collection: what are the earliest and latest receipt dates, and are any replenishment receipts missing between them? Ask again when the user says the receipts are ready. Coverage affects monthly evaluation and whether seasonal claims have supporting history; it does not block receipt extraction.
- Review the existing category list against the receipts; reuse categories unless none fits. Which comparison baselines and useful performance thresholds should be approved?
- How should incomplete receipt coverage affect labels?
- The user rejected coexistence with the sales-history forecasting approach because the available history does not support it. Retaining stock-rule quantity suggestions with Inventory Manager review is agreed. The exact retirement scope and replacement of ADR 0005 still require a settled design; no implementation or architecture retirement has occurred.
- Finalize the stock-rule target and formula before implementation, applying the agreed rule that safety stock counts only once. The existing fallback in ReplenishmentQueueService::getRecommendations adds safety stock to reorder level, while GLOSSARY.md defines Reorder Level as already including Safety Stock. The user approved avoiding this duplicate buffer; the code has not been changed.

## Receipt collection progress

The user will begin recording the receipts currently available. They have not said the complete collection is ready; the receipt-coverage questions remain deferred.
