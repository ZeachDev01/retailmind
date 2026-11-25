# Additional 50 capstone demo products

Upload `demo_capstone_seed_csv.csv` using **Products > CSV Import > Products**.
It contains 50 additional real products from the Open Food Facts Philippine-market
API, retrieved on 2026-11-25. The original 50-product CSV is preserved separately.
Together they provide 100 demo products.

The original 50 products were already present among the 106 products in the local
database when this additional file was prepared. This upload therefore contains
only the additional 50, to avoid updating the original imported products.

All selling prices are **PHP 1.00 testing placeholders**. Costs and suppliers are
unknown and left blank; new products start at zero stock. Replace prices before
real store use. The package quantity of 1 is an import default, not verified
physical packaging. No stock, sales, expiry dates, or images are included.

Validated all 13 columns, GTIN check digits, unique identifiers and normalized
names, existing store categories, and safe spreadsheet cells. Compared against
all 106 current products using SKU, piece/pack/case barcode, leading-zero-normalized
identifiers, normalized names and near name matches. Ambiguous records and records
with conflicting declared sizes were excluded. No database writes were made.

Validation reflects the configured local database on 2026-11-25. Recheck if the
catalog changes or if using another database. Reimporting the same SKU updates
its product metadata and prices. Keep barcodes as text when editing in Excel to
preserve leading zeros.

`demo_capstone_seed_csv.sources.json` preserves exact API queries, original source
metadata, imported names/categories, and individual product links. Store categories
are locally mapped; contributor-supplied product metadata may contain errors.

Data: [Open Food Facts contributors](https://world.openfoodfacts.org),
[Open Database License 1.0](https://opendatacommons.org/licenses/odbl/1-0/).
