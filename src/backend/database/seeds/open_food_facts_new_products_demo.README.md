# Open Food Facts CSV test products

`open_food_facts_new_products_demo.csv` contains 50 real Philippine-market food
products fetched from the Open Food Facts API on 2026-11-25. It follows the current
Products CSV importer's exact 13-column order. No application code was changed.

All selling prices are **PHP 1.00 test placeholders**, not verified retail prices.
Replace them before real store use. Purchase costs and suppliers are blank because
they are unknown. Import creates product records with zero stock. No stock,
purchase, sale, expiry, or physical package counts are invented. The
`units_per_package` value of 1 is an import default, not a verified pack size.

## Import

1. Open Products > Add Product > CSV Import.
2. Select the **Products** import type.
3. Upload `open_food_facts_new_products_demo.csv`.
4. Review the import results; expect 50 successful rows if the catalog has not
   changed since validation.

This CSV is for creating products, not the Inventory import type. Add actual
received stock through the existing receiving workflow, or a separate Inventory
CSV containing `sku,quantity_to_add` once physical quantities are known.
Inventory CSV imports add quantities; repeating them adds stock again.

Keep SKU and barcode columns as text when editing in a spreadsheet: some genuine
barcodes begin with zero. The CSV contains no product images; the current importer
does not accept an image column.

## Duplicate validation

Checked against all 56 products in the configured local database, including
inactive records, without writing to the database. Compared each candidate with
SKU, barcode, pack barcode, case barcode, normalized product names, and near name
matches. Identifier comparisons also ignore leading zeros. Ambiguous existing
product families were conservatively excluded. A second Gardenia 600 g high-fiber
bread record was excluded as a potential duplicate. Each selected barcode has a
valid GTIN check digit. The final CSV has no repeated identifier or normalized
product name.

This check reflects the catalog on 2026-11-25. Recheck if products were added
afterward or if importing into a different database. The importer updates an
existing matching SKU, so uploading this file again can overwrite those products'
prices and metadata with the test values.

## Sources

`open_food_facts_new_products_demo.sources.json` preserves the exact API query,
source product names, brands, declared quantities, category tags, imported names,
and individual product links. Imported names include source brand and size for
clarity; store categories are locally mapped. Open Food Facts records are
contributor supplied and may contain errors; verify labels before real store use.

Data: [Open Food Facts contributors](https://world.openfoodfacts.org),
[Open Database License 1.0](https://opendatacommons.org/licenses/odbl/1-0/).
No images are included.
