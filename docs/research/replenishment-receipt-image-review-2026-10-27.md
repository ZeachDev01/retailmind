# Replenishment receipt image review — October 27, 2026

## Decision

Do not approve this collection as validated training or test labels for a next-month category forecast. The ZIP supplies only September and October 2026 purchase events, and neither month's receipt coverage is confirmed complete. The workbook still marks all 835 item rows `needs_review`. Nine categories are `unsure`; 11 product identities are marked `unknown`. Any precision, recall, F1, or November category list from this collection is provisional.

The [original receipt CSV](../../outputs/receipt-review-2026-10-27/original/receipts.csv) and [original item CSV](../../outputs/receipt-review-2026-10-27/original/receipt_items.csv) reproduce the unedited workbook in the supplied ZIP. The [reviewed workbook](../../outputs/receipt-review-2026-10-27/audit/receipts_and_items_reviewed.xlsx) preserves all source rows and review flags. Corrections supported by the originals include 28 R0009 single-item quantities, two R0007 image-reference extensions, the unknown date for R0012, R0006 line 21's quantity and amount, and two readable handwritten descriptions on R0008. Its 11 unclear product identities remain `unknown`. The [corrected item review CSV](../../outputs/receipt-review-2026-10-27/audit/receipt_items_review_corrected.csv) mirrors all 835 workbook item rows. The [full category review CSV](../../outputs/receipt-review-2026-10-27/audit/receipt_items_category_reviewed_with_quantity.csv) has 824 category-approved rows. The sole file in `reviewed/`, [category pilot data](../../outputs/receipt-review-2026-10-27/reviewed/replenishment_category_pilot_data.csv), keeps quantity and limits those rows to 695 dated purchases; it excludes the return and the undated receipt. It does not certify monthly coverage.

## Sources and checks

- Inspected the user-supplied `drive-download-20261026T233425Z-1-001.zip`: 54 JPG images and an inner `receipt_and_item_categorize.zip` containing receipt and item workbooks. Its 13 receipt records and 835 item rows match the same fields in the separately supplied root ZIP. The outer combined workbook does not contain receipt review statuses, so its receipt metadata is unreviewed.
- Compared every listed image path with the archive, every item receipt ID with the receipt table, and receipt-level item sums with saved totals. Visually checked selected source images, including each material exception and printed dates or totals. This is an evidence review, not a line-by-line certification of all 835 item descriptions and category labels.
- There are 12 purchase receipts and one linked return slip. Seven purchases have confirmed September 2026 dates, one has only a September folder date, and four are dated October 2026. A photographed return does not erase its original purchase event.

## Findings to resolve

| Receipt | Finding | Decision |
| --- | --- | --- |
| R0001 | 143 saved item totals sum to PHP 22,283.05; printed total is PHP 22,412.90. Saved quantity 751 versus printed 755. | Four units and PHP 129.85 remain unaccounted for. Do not approve item transcription. |
| R0002 | 84 saved item totals sum to PHP 7,587.25; printed total is PHP 7,544.75. Saved quantity 235 versus printed 238. | Resolve both amount and quantity differences before approval. |
| R0003–R0004 | Separate purchase and return slips show PHP 2,435.00 purchased and PHP 722.50 returned. | Keep the return separate. Receipt-level totals reconcile, but item categories still need review. |
| R0006 | Receipt is torn; its grand total and some item details are not visible. Original photo supports line 21 as 5 × PHP 30.65 = PHP 153.25 and shows PHP 90.60 on the next torn line. Four product identities remain unknown, three with category `unsure`. | Correct the visible values; do not interpret missing lines or categories as absent purchases. |
| R0007 | Image filenames say `2025-10-06`, but the printed receipt says October 6, 2026. Two workbook image references omit `.jpg`; corresponding JPGs exist. | Kept printed 2026 date and repaired references in the reviewed copy. |
| R0008 | Handwritten slip's five amounts add to PHP 2,620.00, matching its written total. The photo reads `Trini Fairy` and `3 + 14`, correcting two transcriptions. | All five product identities and their categories remain unknown. |
| R0009 | Printed SKU count 82, quantity 410 and total PHP 6,151.55. Workbook has 82 lines and matching amount, but 28 quantity cells are blank; treating each as one makes quantity 410. | Set the 28 blank quantities to `1` in the reviewed copy with inference notes. Retained `needs_review` pending full line verification. |
| R0010 | Printed 176 SKUs, 1,019 units and PHP 18,162.20 match workbook totals. One line remains `VICTORY BRAND P`, category `unsure`; the image does not identify it clearly enough to assign a category. | Keep that category unknown pending product identification. |
| R0011 and R0013 | Printed receipt totals and quantities match workbook aggregates. | Aggregate reconciliation does not certify every category label. |
| R0012 | Top of receipt is missing. Visible lines sum to PHP 17,095.00 against printed total PHP 22,895.90, a PHP 5,800.90 gap. The exact purchase date is inferred from its folder, not visible on the receipt. | Cleared the unverified date in the reviewed copy and retained the folder date in review notes. Do not use as a complete category label. |

Other source lines may still need correction. Image presence and matching totals do not prove that every category is right or that every shopping trip in either month was captured.

## Available date range and coverage

The earliest confirmed receipt date in the supplied files is **September 16, 2026**; the latest is **October 13, 2026**. R0012 has no confirmed date. The owner said these are all receipts currently available to them, but that does not establish that every shopping trip was documented.

| Month | Supplied purchases with confirmed dates | Complete month? |
| --- | ---: | --- |
| Before September 2026 | None supplied | Unknown |
| September 2026 | 7, plus undated R0012 in a September folder | Unknown; earliest supplied purchase is September 16 and one receipt lacks a confirmed date |
| October 2026 | 4 through October 13 | No full-month evidence as of October 27 |

No earlier complete month can be added from the supplied ZIPs or the other receipt archives found in Downloads. The `2025-10-06` image filenames do not create a 2025 observation: the printed receipt says October 6, 2026.

## Next evidence

1. Obtain legible missing portions or independent product identification for R0001, R0002, R0006, R0010 and R0012. Keep their unresolved values unknown. `VICTORY BRAND P` cannot be assigned from its shortened receipt text: [Victory pasta](https://mayari.com.ph/store/product/2305-sunmac-spaghetti-macaroni) and [Victory stationery](https://shopee.ph/INTERMEDIATE-PAD-%28VICTORY-BRAND%29-10PADS-PER-REAM-i.1599781345.41465699183) are both plausible brand matches, not proof of this item's identity.
2. Confirm monthly shopping-trip coverage from a separate log or owner recollection. The archive alone cannot prove a month complete.
3. Add earlier complete months if they become available. Only then run chronological Colab tests against a simple prior-frequency baseline and report precision, recall and F1. Withhold the November 2026 category heads-up on current evidence; November can be scored only after its receipt coverage is complete.

The [Colab readiness notebook](replenishment_data_readiness_colab.ipynb) accepts the supplied outer ZIP and stops before training on this evidence.
